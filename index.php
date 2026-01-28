<?php
/**
 * PUBG UC STORE - BACKEND (index.php)
 * Author: SHIDO
 * Version: 2.0 (Full Fix)
 */

// 1. FRONTEND ROUTING - Statik fayllarni uzatish
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($uri === '/' || $uri === '/index.html') {
    header('Content-Type: text/html');
    readfile('index.html');
    exit;
}
if ($uri === '/style.css') {
    header('Content-Type: text/css');
    readfile('style.css');
    exit;
}
if ($uri === '/app.js') {
    header('Content-Type: application/javascript');
    readfile('app.js');
    exit;
}

// 2. SOZLAMALAR VA DEBUG
ini_set('display_errors', 0); // Productionda xatolarni yashirish
error_reporting(E_ALL);

define('BOT_TOKEN', getenv('BOT_TOKEN') ?: 'SIZNING_BOT_TOKENINGIZ');
define('ADMIN_ID', getenv('ADMIN_ID') ?: 'SIZNING_TELEGRAM_ID_RAQAMINGIZ'); 
define('WEBAPP_URL', getenv('WEBAPP_URL') ?: 'https://' . $_SERVER['HTTP_HOST']);

// 3. DATABASE (SQLite) ULANISH
try {
    $pdo = new PDO('sqlite:database.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Jadvallarni yaratish
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        telegram_id TEXT PRIMARY KEY,
        first_name TEXT,
        username TEXT,
        balance REAL DEFAULT 0,
        is_blocked INTEGER DEFAULT 0,
        block_reason TEXT,
        joined_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT,
        product_type TEXT,
        product_name TEXT,
        contact_info TEXT,
        price REAL,
        status TEXT DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS topups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT,
        amount REAL,
        status TEXT DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    die(json_encode(['error' => "DB Error: " . $e->getMessage()]));
}

// 4. API VA WEBHOOK YO'NALTIRISH (ROUTING)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");

$method = $_SERVER['REQUEST_METHOD'];

// A. Telegram Webhook
if ($method === 'POST' && $uri === '/webhook') {
    handleTelegramUpdate();
    exit;
}

// B. API: User Balansini olish
if ($method === 'GET' && $uri === '/api/user') {
    $tg_id = $_GET['id'] ?? 0;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_id = ?");
    $stmt->execute([$tg_id]);
    $user = $stmt->fetch();
    echo json_encode($user ?: ['telegram_id' => $tg_id, 'balance' => 0]);
    exit;
}

// C. API: Buyurtma berish (UC, POP, ACC)
if ($method === 'POST' && $uri === '/api/place-order') {
    $data = json_decode(file_get_contents('php://input'), true);
    handlePlaceOrder($data);
    exit;
}

// D. API: Hisob to'ldirish so'rovi
if ($method === 'POST' && $uri === '/api/request-topup') {
    $data = json_decode(file_get_contents('php://input'), true);
    handleTopupRequest($data);
    exit;
}

// Health Check
if ($uri === '/health') {
    echo "OK"; exit;
}

// ---------------- FUNKSIYALAR ----------------

function handleTelegramUpdate() {
    global $pdo;
    $update = json_decode(file_get_contents('php://input'), true);
    if (!$update) return;

    if (isset($update['message'])) {
        $msg = $update['message'];
        $chat_id = $msg['chat']['id'];
        $text = $msg['text'] ?? '';
        $first_name = $msg['from']['first_name'] ?? 'User';
        $username = $msg['from']['username'] ?? '';

        // Userni bazaga qo'shish yoki yangilash
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO users (telegram_id, first_name, username, balance, is_blocked, block_reason, joined_at) 
            VALUES (?, ?, ?, (SELECT balance FROM users WHERE telegram_id = ?), 
            (SELECT is_blocked FROM users WHERE telegram_id = ?), 
            (SELECT block_reason FROM users WHERE telegram_id = ?), 
            (SELECT joined_at FROM users WHERE telegram_id = ?))");
        $stmt->execute([$chat_id, $first_name, $username, $chat_id, $chat_id, $chat_id, $chat_id]);

        // Bloklanganini tekshirish
        $stmt = $pdo->prepare("SELECT is_blocked, block_reason FROM users WHERE telegram_id = ?");
        $stmt->execute([$chat_id]);
        $u_status = $stmt->fetch();
        if ($u_status && $u_status['is_blocked'] == 1) {
            sendMessage($chat_id, "❌ Siz bloklangansiz! Sabab: " . $u_status['block_reason']);
            return;
        }

        if ($text === '/start') {
            $kb = [
                'inline_keyboard' => [
                    [['text' => "🛒 DO'KONGA KIRISH", 'web_app' => ['url' => WEBAPP_URL]]],
                    [['text' => "👨‍💻 Admin", 'url' => 'https://t.me/shido_admin']]
                ]
            ];
            sendMessage($chat_id, "Assalomu alaykum, <b>$first_name</b>!\n\nUC, Mashhurlik va Akkauntlar do'koniga xush kelibsiz. Botdan foydalanish uchun quyidagi tugmani bosing:", $kb);
        }

        // Admin Buyruqlari
        if ($chat_id == ADMIN_ID) {
            if (strpos($text, "/msg ") === 0) {
                $m = str_replace("/msg ", "", $text);
                $users = $pdo->query("SELECT telegram_id FROM users")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($users as $u) sendMessage($u, "📢 <b>Xabar:</b>\n\n$m");
                sendMessage(ADMIN_ID, "✅ Xabar hamma foydalanuvchilarga yuborildi.");
            }
            if (preg_match("/^\+ (\d+) (\d+)/", $text, $m)) {
                $pdo->prepare("UPDATE users SET balance = balance + ? WHERE telegram_id = ?")->execute([$m[2], $m[1]]);
                sendMessage(ADMIN_ID, "✅ ID: {$m[1]} balansiga {$m[2]} qo'shildi.");
                sendMessage($m[1], "💰 Hisobingizga {$m[2]} UZS qo'shildi!");
            }
        }
    }

    if (isset($update['callback_query'])) {
        $cb = $update['callback_query'];
        $data = $cb['data'];
        $chat_id = $cb['message']['chat']['id'];
        $msg_id = $cb['message']['message_id'];

        if ($chat_id == ADMIN_ID) {
            // To'lovni tasdiqlash
            if (strpos($data, 'approve_topup_') === 0) {
                $tid = str_replace('approve_topup_', '', $data);
                processTopup($tid, 'approved', $msg_id);
            } elseif (strpos($data, 'reject_topup_') === 0) {
                $tid = str_replace('reject_topup_', '', $data);
                processTopup($tid, 'rejected', $msg_id);
            }
            // Buyurtmani boshqarish
            elseif (strpos($data, 'order_') === 0) {
                $parts = explode('_', $data); // order_status_ID_status
                changeOrderStatus($parts[2], $parts[3], $msg_id);
            }
        }
    }
}

function handlePlaceOrder($data) {
    global $pdo;
    $uid = $data['telegram_id'];
    $type = $data['type']; // uc, pop, acc
    $name = $data['name'];
    $contact = $data['contact'];
    $total = $data['total_price'];

    // Balansni tekshirish
    $stmt = $pdo->prepare("SELECT balance FROM users WHERE telegram_id = ?");
    $stmt->execute([$uid]);
    $user = $stmt->fetch();

    if (!$user || $user['balance'] < $total) {
        http_response_code(400);
        echo json_encode(['error' => "Mablag' yetarli emas"]); return;
    }

    // Balansdan ayirish va buyurtma yaratish
    $pdo->prepare("UPDATE users SET balance = balance - ? WHERE telegram_id = ?")->execute([$total, $uid]);
    $stmt = $pdo->prepare("INSERT INTO orders (user_id, product_type, product_name, contact_info, price) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$uid, $type, $name, $contact, $total]);
    $oid = $pdo->lastInsertId();

    // Adminga bildirishnoma
    $msg = "🛒 <b>Yangi Buyurtma #$oid</b>\n\n";
    $msg .= "👤 User: <code>$uid</code>\n";
    $msg .= "📦 Tur: <b>" . strtoupper($type) . "</b>\n";
    $msg .= "💎 Mahsulot: $name\n";
    $msg .= "🔑 Ma'lumot: <code>$contact</code>\n";
    $msg .= "💰 Narx: " . number_format($total, 0, '.', ' ') . " UZS";

    $kb = ['inline_keyboard' => [
        [['text' => "⏳ Jarayonda", 'callback_data' => "order_status_{$oid}_processing"], 
         ['text' => "✅ Bajarildi", 'callback_data' => "order_status_{$oid}_completed"]],
        [['text' => "❌ Bekor qilish", 'callback_data' => "order_status_{$oid}_cancelled"]]
    ]];

    sendMessage(ADMIN_ID, $msg, $kb);
    sendMessage($uid, "⏳ Buyurtmangiz (#$oid) qabul qilindi va ko'rib chiqilmoqda.");
    echo json_encode(['success' => true]);
}

function handleTopupRequest($data) {
    global $pdo;
    $uid = $data['telegram_id'];
    $amount = $data['amount'];
    $img_base64 = $data['image'];

    $stmt = $pdo->prepare("INSERT INTO topups (user_id, amount) VALUES (?, ?)");
    $stmt->execute([$uid, $amount]);
    $tid = $pdo->lastInsertId();

    // Rasmni decode qilish va vaqtincha saqlash
    $img = base64_decode(explode(',', $img_base64)[1]);
    $path = "topup_$tid.jpg";
    file_put_contents($path, $img);

    $caption = "💰 <b>Hisobni to'ldirish so'rovi!</b>\n\n👤 ID: <code>$uid</code>\n💵 Summa: " . number_format($amount, 0, '.', ' ') . " UZS";
    $kb = ['inline_keyboard' => [[
        ['text' => "✅ Tasdiqlash", 'callback_data' => "approve_topup_$tid"],
        ['text' => "❌ Rad etish", 'callback_data' => "reject_topup_$tid"]
    ]]];

    sendPhoto(ADMIN_ID, realpath($path), $caption, $kb);
    unlink($path); // Rasmni o'chirish
    echo json_encode(['success' => true]);
}

function processTopup($id, $status, $msg_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM topups WHERE id = ?");
    $stmt->execute([$id]);
    $t = $stmt->fetch();
    if (!$t || $t['status'] != 'pending') return;

    if ($status == 'approved') {
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE telegram_id = ?")->execute([$t['amount'], $t['user_id']]);
        $pdo->prepare("UPDATE topups SET status = 'approved' WHERE id = ?")->execute([$id]);
        sendMessage($t['user_id'], "✅ Hisobingiz " . number_format($t['amount'],0,'.',' ') . " UZS ga to'ldirildi!");
        editMessageText(ADMIN_ID, $msg_id, "✅ To'lov tasdiqlandi. ID: {$t['user_id']}");
    } else {
        $pdo->prepare("UPDATE topups SET status = 'rejected' WHERE id = ?")->execute([$id]);
        sendMessage($t['user_id'], "❌ To'lov so'rovingiz rad etildi. Ma'lumotlarni tekshiring.");
        editMessageText(ADMIN_ID, $msg_id, "❌ To'lov rad etildi.");
    }
}

function changeOrderStatus($id, $status, $msg_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$id]);
    $o = $stmt->fetch();
    if (!$o) return;

    $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?")->execute([$status, $id]);
    
    if ($status == 'completed') {
        sendMessage($o['user_id'], "✅ Buyurtmangiz (#$id) bajarildi! Xaridingiz uchun rahmat.");
        editMessageText(ADMIN_ID, $msg_id, "✅ Buyurtma #$id yopildi (Bajarildi).");
    } elseif ($status == 'processing') {
        sendMessage($o['user_id'], "🔄 Buyurtmangiz (#$id) jarayonga o'tkazildi.");
    } elseif ($status == 'cancelled') {
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE telegram_id = ?")->execute([$o['price'], $o['user_id']]);
        sendMessage($o['user_id'], "❌ Buyurtmangiz (#$id) bekor qilindi va mablag'ingiz qaytarildi.");
        editMessageText(ADMIN_ID, $msg_id, "❌ Buyurtma #$id bekor qilindi va pul qaytarildi.");
    }
}

// ---------------- HELPERS ----------------

function sendMessage($chat_id, $text, $keyboard = null) {
    $data = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard) $data['reply_markup'] = json_encode($keyboard);
    return request('sendMessage', $data);
}

function sendPhoto($chat_id, $photo_path, $caption, $keyboard = null) {
    $data = [
        'chat_id' => $chat_id,
        'photo' => new CURLFile($photo_path),
        'caption' => $caption,
        'parse_mode' => 'HTML'
    ];
    if ($keyboard) $data['reply_markup'] = json_encode($keyboard);
    return request('sendPhoto', $data);
}

function editMessageText($chat_id, $msg_id, $text) {
    request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => $text, 'parse_mode' => 'HTML']);
}

function request($method, $data) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

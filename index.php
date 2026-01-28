<?php
// 1. FRONTEND ROUTING - Sayt ochilganda HTML fayllarni uzatish
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

// 2. BACKEND LOGIC (API va Webhook)
// Bu yerdan botingizning asosiy mantiqi davom etadi...

// Xatolarni ko'rsatish (Debug uchun, productionda o'chirib qo'yish kerak)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ---------------- SOZLAMALAR ----------------
// Renderda Environment Variable orqali yoki shu yerga yozing
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: 'SIZNING_BOT_TOKENINGIZ');
define('ADMIN_ID', getenv('ADMIN_ID') ?: 'SIZNING_TELEGRAM_ID_RAQAMINGIZ'); 
define('WEBAPP_URL', getenv('WEBAPP_URL') ?: 'https://sizning-render-url.onrender.com'); // Frontend URL keyinchalik o'zgaradi

// Bazaga ulanish (SQLite)
try {
    $pdo = new PDO('sqlite:database.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Jadvallarni yaratish (agar mavjud bo'lmasa)
   $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY,
        telegram_id TEXT UNIQUE,
        first_name TEXT,
        username TEXT,
        balance REAL DEFAULT 0,
        is_blocked INTEGER DEFAULT 0, -- 0-aktiv, 1-bloklangan
        block_reason TEXT,
        joined_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT,
        pubg_id TEXT,
        uc_amount INTEGER,
        price REAL,
        status TEXT DEFAULT 'pending', -- pending, processing, completed, cancelled
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS topups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT,
        amount REAL,
        status TEXT DEFAULT 'pending', -- pending, approved, rejected
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}

// ---------------- ROUTING (Yo'naltirish) ----------------
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// 1. Telegram Webhook
if ($method === 'POST' && $uri === '/webhook') {
    handleTelegramUpdate();
    exit;
}

// 2. API: User Balansini olish (Web App uchun)
if ($method === 'GET' && strpos($uri, '/api/user') === 0) {
    // Brauzerga JSON formatida javob berayotganimizni aytamiz
    header('Content-Type: application/json');
    header("Access-Control-Allow-Origin: *"); // Tashqi so'rovlarga ruxsat
    
    $tg_id = $_GET['id'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_id = ?");
    $stmt->execute([$tg_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        // Balans null bo'lsa 0 deb qaytarish
        $user['balance'] = (float)($user['balance'] ?? 0);
        echo json_encode($user);
    } else {
        // Foydalanuvchi topilmasa, frontend xato bermasligi uchun 0 balans qaytaramiz
        echo json_encode(['telegram_id' => $tg_id, 'balance' => 0, 'error' => 'User not found']);
    }
    exit;
}

// 3. API: UC Sotib olish (Web App dan)
if ($method === 'POST' && $uri === '/api/buy-uc') {
    $input = json_decode(file_get_contents('php://input'), true);
    handleBuyUC($input);
    exit;
}

// 4. API: Hisob to'ldirish so'rovi (Web App dan)
if ($method === 'POST' && $uri === '/api/request-topup') {
    $input = json_decode(file_get_contents('php://input'), true);
    handleTopupRequest($input);
    exit;
}

// Bosh sahifa (Health check)
echo "PUBG UC Bot Backend ishlamoqda. Vaqt: " . date('Y-m-d H:i:s');



// ---------------- FUNKSIYALAR ----------------

function handleTelegramUpdate() {
    global $pdo;
    $update = json_decode(file_get_contents('php://input'), true);

    if (isset($update['message'])) {
        $chat_id = $update['message']['chat']['id'];
        $text = $update['message']['text'] ?? '';
        $first_name = $update['message']['from']['first_name'] ?? 'User';
        $username = $update['message']['from']['username'] ?? '';

        // User bloklanganini tekshirish
    $check = $pdo->prepare("SELECT is_blocked, block_reason FROM users WHERE telegram_id = ?");
    $check->execute([$chat_id]);
    $user_status = $check->fetch();

    if ($user_status && $user_status['is_blocked'] == 1) {
        sendMessage($chat_id, "❌ Siz botdan chetlatilgansiz!\n🛑 Sabab: " . $user_status['block_reason']);
        return; // Kod shu yerda to'xtaydi
    }

       // ---------------- YANGI KOD BOSHLANDI ----------------
        
        // Userni bazaga saqlash
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO users (telegram_id, first_name, username) VALUES (?, ?, ?)");
        $stmt->execute([$chat_id, $first_name, $username]);

        // TEKSHIRISH: Agar bazaga yangi qator qo'shilgan bo'lsa (rowCount > 0), demak bu yangi user
        if ($stmt->rowCount() > 0) {
            
            // Qo'shimcha: Jami userlar sonini hisoblash (statistikaga qiziq bo'lsa)
            $count_stmt = $pdo->query("SELECT COUNT(*) FROM users");
            $total_users = $count_stmt->fetchColumn();

            // Adminga boradigan xabar matni
            $admin_msg = "👋 <b>Yangi foydalanuvchi ro'yxatdan o'tdi!</b>\n\n";
            $admin_msg .= "👤 <b>Ismi:</b> <a href='tg://user?id=$chat_id'>$first_name</a>\n"; // Ismiga bossa lichkasiga o'tadi
            $admin_msg .= "🆔 <b>ID:</b> <code>$chat_id</code>\n";
            
            if (!empty($username)) {
                $admin_msg .= "🌐 <b>Username:</b> @$username\n";
            }
            
            $admin_msg .= "\n📊 <i>Jami foydalanuvchilar: $total_users ta</i>";

            // Xabarni adminga yuborish (ADMIN_ID konstantasidan foydalanamiz)
            sendMessage(ADMIN_ID, $admin_msg);
        }
        
        // ---------------- YANGI KOD TUGADI ----------------
        if ($text === '/start') {
            // Web App tugmasi
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => "PUBG UC Do'koni 🛒", 'web_app' => ['url' => WEBAPP_URL]]
                    ],
                    [
                        ['text' => "Admin Aloqa 👨💻", 'url' => 'https://t.me/SultanovSardorbekSheraliyevich']
                    ]
                ]
            ];
            
            // Admin paneli tugmasi (faqat admin uchun)
            if ($chat_id == ADMIN_ID) {
                $keyboard['inline_keyboard'][] = [['text' => "Admin Panel ⚙️", 'callback_data' => 'admin_panel']];
            }

            sendMessage($chat_id, "Assalomu alaykum, $first_name! \n\nPUBG Mobile UC xizmatiga xush kelibsiz. Quyidagi tugma orqali do'konga kiring:", $keyboard);
        }
        // --- ADMIN PANEL BOSHLANDI ---
        if ($chat_id == ADMIN_ID) {
            
            // Xabar yuborish (Xabar: matn)
            if (strpos($text, "Xabar: ") === 0) {
                $msg = str_replace("Xabar: ", "", $text);
                $users = $pdo->query("SELECT telegram_id FROM users")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($users as $u_id) {
                    sendMessage($u_id, $msg);
                }
                sendMessage(ADMIN_ID, "✅ Xabar barcha foydalanuvchilarga yuborildi!");
            }

            // Balansni o'zgartirish (Balans: ID Summa) - Masalan: Balans: 12345 5000
            if (preg_match("/^Balans: (\d+) (-?\d+)/", $text, $matches)) {
                $u_id = $matches[1];
                $amount = $matches[2];
                $pdo->prepare("UPDATE users SET balance = balance + ? WHERE telegram_id = ?")->execute([$amount, $u_id]);
                sendMessage(ADMIN_ID, "✅ User $u_id balansiga $amount qo'shildi/ayrildi.");
                sendMessage($u_id, "💰 Hisobga $amount UZS qo'shildi/Ayrildi.");
            }

            // Bloklash (Blok: ID Sabab) - Masalan: Blok: 12345 Qoida buzilishi
            if (preg_match("/^Blok: (\d+) (.+)/", $text, $matches)) {
                $u_id = $matches[1];
                $reason = $matches[2];
                $pdo->prepare("UPDATE users SET is_blocked = 1, block_reason = ? WHERE telegram_id = ?")->execute([$reason, $u_id]);
                sendMessage(ADMIN_ID, "🚫 User $u_id bloklandi.");
                sendMessage($u_id, "⚠️ Siz botdan chetlatildingiz!\nSabab: $reason");
            }
        }
        // --- ADMIN PANEL TUGADI ---
    }

    // Callback Query (Tugmalar bosilganda)
    if (isset($update['callback_query'])) {
        $cq_id = $update['callback_query']['id'];
        $chat_id = $update['callback_query']['message']['chat']['id'];
        $data = $update['callback_query']['data'];
        $message_id = $update['callback_query']['message']['message_id'];

        // ADMIN: Hisob to'ldirishni tasdiqlash
        if (strpos($data, 'approve_topup_') === 0 && $chat_id == ADMIN_ID) {
            $topup_id = str_replace('approve_topup_', '', $data);
            processTopup($topup_id, 'approved', $chat_id, $message_id);
        }
        elseif (strpos($data, 'reject_topup_') === 0 && $chat_id == ADMIN_ID) {
            $topup_id = str_replace('reject_topup_', '', $data);
            processTopup($topup_id, 'rejected', $chat_id, $message_id);
        }

        // ADMIN: Buyurtma statusini o'zgartirish
        elseif (strpos($data, 'order_status_') === 0 && $chat_id == ADMIN_ID) {
            // data formati: order_status_{id}_{status}
            $parts = explode('_', $data);
            $order_id = $parts[2];
            $status = $parts[3];
            changeOrderStatus($order_id, $status, $chat_id, $message_id);
        }
    }
}

// ---------------- LOGIKA FUNKSIYALARI ----------------

function handleTopupRequest($data) {
    global $pdo;
    $user_id = $data['telegram_id'];
    $amount = $data['amount'];
    $image_data = $data['image']; // Base64 rasm

    // Bazaga yozish
    $stmt = $pdo->prepare("INSERT INTO topups (user_id, amount) VALUES (?, ?)");
    $stmt->execute([$user_id, $amount]);
    $topup_id = $pdo->lastInsertId();

    // Base64 dan rasmni ajratib olish
    $image_parts = explode(";base64,", $image_data);
    $image_type_aux = explode("image/", $image_parts[0]);
    $image_base64 = base64_decode($image_parts[1]);
    
    // Vaqtincha saqlash
    $file_path = "receipt_$topup_id.png";
    file_put_contents($file_path, $image_base64);

    // Adminga rasm (photo) yuborish
    $caption = "💰 <b>Yangi to'lov so'rovi!</b>\n\n";
    $caption .= "👤 Foydalanuvchi: <code>$user_id</code>\n";
    $caption .= "💵 Summa: <b>" . number_format($amount, 0, '.', ' ') . " UZS</b>";

    $keyboard = [
        'inline_keyboard' => [[
            ['text' => "✅ Tasdiqlash", 'callback_data' => "approve_topup_$topup_id"],
            ['text' => "❌ Rad etish", 'callback_data' => "reject_topup_$topup_id"]
        ]]
    ];

    // Telegramga rasm yuborish
    $post_fields = [
        'chat_id'   => ADMIN_ID,
        'photo'     => new CURLFile(realpath($file_path)),
        'caption'   => $caption,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode($keyboard)
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot" . BOT_TOKEN . "/sendPhoto");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);

    // Rasmni o'chirib tashlash (joy egallamasligi uchun)
    unlink($file_path);

    echo json_encode(['success' => true]);
}
function processTopup($id, $action, $admin_chat_id, $message_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT * FROM topups WHERE id = ?");
    $stmt->execute([$id]);
    $topup = $stmt->fetch();

    if (!$topup || $topup['status'] != 'pending') {
        sendMessage($admin_chat_id, "Bu so'rov allaqachon ko'rib chiqilgan.");
        return;
    }

    if ($action == 'approved') {
        // Balansni oshirish
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE telegram_id = ?")->execute([$topup['amount'], $topup['user_id']]);
        $pdo->prepare("UPDATE topups SET status = 'approved' WHERE id = ?")->execute([$id]);
        
        // Userga xabar
        sendMessage($topup['user_id'], "✅ Sizning hisobingiz {$topup['amount']} UZS ga to'ldirildi!");
        editMessageText($admin_chat_id, $message_id, "✅ To'lov tasdiqlandi va balansga qo'shildi. (ID: $id)");
    } else {
        $pdo->prepare("UPDATE topups SET status = 'rejected' WHERE id = ?")->execute([$id]);
        sendMessage($topup['user_id'], "❌ Sizning to'lov so'rovingiz bekor qilindi.");
        editMessageText($admin_chat_id, $message_id, "❌ To'lov rad etildi. (ID: $id)");
    }
}

function handleBuyUC($data) {
    global $pdo;
    $user_id = $data['telegram_id'];
    $uc_amount = $data['uc_amount'];
    $price = $data['price'];
    $pubg_id = $data['pubg_id'];

    // User balansini tekshirish
    $stmt = $pdo->prepare("SELECT balance FROM users WHERE telegram_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user || $user['balance'] < $price) {
        echo json_encode(['success' => false, 'message' => "Mablag' yetarli emas"]);
        return;
    }

    // Balansdan yechish
    $pdo->prepare("UPDATE users SET balance = balance - ? WHERE telegram_id = ?")->execute([$price, $user_id]);

    // Buyurtma yaratish
    $stmt = $pdo->prepare("INSERT INTO orders (user_id, pubg_id, uc_amount, price) VALUES (?, ?, ?, ?)");
    $stmt->execute([$user_id, $pubg_id, $uc_amount, $price]);
    $order_id = $pdo->lastInsertId();

    // Userga xabar
    sendMessage($user_id, "⏳ Buyurtma qabul qilindi!\n🆔 PUBG ID: $pubg_id\n💎 UC: $uc_amount\nHolat: Kutilmoqda...");

    // Adminga xabar
    $msg = "🛒 <b>Yangi UC Buyurtmasi!</b>\n\n";
    $msg .= "🆔 Order ID: #$order_id\n";
    $msg .= "👤 User: <code>$user_id</code>\n";
    $msg .= "🎮 PUBG ID: <code>$pubg_id</code>\n";
    $msg .= "💎 UC: <b>$uc_amount</b>\n";
    $msg .= "💰 Narx: $price UZS";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => "⏳ Jarayonda", 'callback_data' => "order_status_{$order_id}_processing"],
                ['text' => "✅ Bajarildi", 'callback_data' => "order_status_{$order_id}_completed"]
            ],
            [
                ['text' => "❌ Bekor qilish (Puli qaytadi)", 'callback_data' => "order_status_{$order_id}_cancelled"]
            ]
        ]
    ];

    sendMessage(ADMIN_ID, $msg, $keyboard);
    echo json_encode(['success' => true]);
}

function changeOrderStatus($order_id, $status, $admin_chat_id, $message_id) {
    global $pdo;

    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();

    if (!$order) return;

    // Agar bekor qilinsa pulni qaytarish
    if ($status == 'cancelled' && $order['status'] != 'cancelled') {
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE telegram_id = ?")->execute([$order['price'], $order['user_id']]);
        $msg_user = "❌ Buyurtmangiz (#$order_id) bekor qilindi va pulingiz qaytarildi.";
        $msg_admin = "❌ Buyurtma #$order_id bekor qilindi va pul qaytarildi.";
    } elseif ($status == 'completed') {
        $msg_user = "✅ Buyurtmangiz (#$order_id) bajarildi! UC hisobingizga tushdi.";
        $msg_admin = "✅ Buyurtma #$order_id bajarildi deb belgilandi.";
    } elseif ($status == 'processing') {
        $msg_user = "🔄 Buyurtmangiz (#$order_id) ijro etilmoqda...";
        $msg_admin = "🔄 Buyurtma #$order_id jarayonga o'tkazildi.";
    }

    $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?")->execute([$status, $order_id]);
    
    if (isset($msg_user)) sendMessage($order['user_id'], $msg_user);
    if (isset($msg_admin)) editMessageText($admin_chat_id, $message_id, $msg_admin);
}

// ---------------- TELEGRAM API HELPER ----------------

function sendMessage($chat_id, $text, $keyboard = null) {
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard);
    }
    request('sendMessage', $data);
}

function editMessageText($chat_id, $message_id, $text) {
    $data = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    request('editMessageText', $data);
}

function request($method, $data = []) {
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
?>


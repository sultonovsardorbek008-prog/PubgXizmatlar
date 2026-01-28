// ==========================================
// 1. INITIALIZATION & CONFIG
// ==========================================
const tg = window.Telegram.WebApp;
tg.expand();

const API_BASE_URL = window.location.origin;

// State (Holat)
let cart = [];
let userBalance = 0;
let userTelegramId = null;
let currentProductId = null;
let selectedFileBase64 = null;

// Mahsulotlar ro'yxati
const products = [
    { id: 1, type: 'uc', label: "60 UC", price: 12000, uc: 60 },
    { id: 2, type: 'uc', label: "325 UC", price: 58000, uc: 325 },
    { id: 3, type: 'uc', label: "660 UC", price: 118000, uc: 660 },
    { id: 101, type: 'pop', label: "100K Mashhurlik", price: 45000 },
    { id: 102, type: 'pop', label: "500K Mashhurlik", price: 200000 },
    { id: 201, type: 'acc', label: "M416 Muzli Lv.4", price: 850000, description: "Old loginlar toza" },
    { id: 202, type: 'acc', label: "Full Akkaunt", price: 2500000, description: "Evo setlar bor" }
];

// ==========================================
// 2. APP START & LOADER
// ==========================================
document.addEventListener('DOMContentLoaded', () => {
    createExplosionParticles();
    setTimeout(() => {
        document.getElementById('intro-loader').style.display = 'none';
        document.getElementById('app').style.display = 'block';
        initApp();
    }, 2200);
});

function initApp() {
    const user = tg.initDataUnsafe.user;
    if (user) {
        userTelegramId = user.id;
        document.getElementById('user-name').innerText = user.first_name;
        document.getElementById('user-id').innerText = user.id;
        if(user.photo_url) document.getElementById('user-avatar').src = user.photo_url;
        fetchBalance();
    } else {
        userTelegramId = "123456789"; // Test uchun
        document.getElementById('user-name').innerText = "Test User";
    }
    renderProducts('uc'); // Boshida UC bo'limini ko'rsatish
}

// ==========================================
// 3. SHOP & CATEGORY LOGIC
// ==========================================
function showCategory(type) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    event.currentTarget.classList.add('active');
    renderProducts(type);
}

function renderProducts(filterType) {
    const container = document.getElementById('products-container');
    container.innerHTML = '';
    
    const filtered = products.filter(p => p.type === filterType);
    
    filtered.forEach(p => {
        const div = document.createElement('div');
        div.className = 'product-card';
        div.innerHTML = `
            <div class="uc-icon"><i class="fa-solid ${p.type === 'acc' ? 'fa-user-shield' : 'fa-gem'}"></i></div>
            <h3>${p.label}</h3>
            <span class="price-tag">${formatMoney(p.price)} UZS</span>
            ${p.description ? `<p style="font-size:10px; opacity:0.7">${p.description}</p>` : ''}
            <button class="buy-btn" onclick="addToCart(${p.id})">
                <i class="fa-solid fa-cart-plus"></i> Savatga
            </button>
        `;
        container.appendChild(div);
    });
}

// ==========================================
// 4. CART LOGIC
// ==========================================
function addToCart(id) {
    const product = products.find(p => p.id === id);
    cart = [product]; // Soddalik uchun savatda faqat 1 ta mahsulot bo'ladi
    updateCartUI();
    tg.HapticFeedback.notificationOccurred('success');
    tg.showAlert("Savatga qo'shildi!");
}

function updateCartUI() {
    const badge = document.getElementById('cart-badge');
    if (cart.length > 0) {
        badge.classList.remove('hidden');
        badge.innerText = cart.length;
    } else {
        badge.classList.add('hidden');
    }
}

// ==========================================
// 5. CHECKOUT & PAYMENT
// ==========================================
function checkout() {
    if (cart.length === 0) return tg.showAlert("Savat bo'sh!");
    
    const product = cart[0]; 
    currentProductId = product.id;

    if (userBalance < product.price) {
        return tg.showPopup({
            title: "Mablag' yetarli emas",
            message: "Iltimos, hisobingizni to'ldiring.",
            buttons: [{type: "ok"}]
        });
    }

    const modal = document.getElementById('pubg-id-modal');
    const inputField = document.getElementById('pubg-game-id');
    const modalTitle = document.getElementById('modal-input-title') || modal.querySelector('h3');

    // Mahsulot turiga qarab modalni sozlash
    if (product.type === 'acc') {
        modalTitle.innerText = "Aloqa uchun telefon raqam";
        inputField.placeholder = "+998901234567";
    } else {
        modalTitle.innerText = "PUBG Player ID";
        inputField.placeholder = "ID raqamingizni kiriting";
    }

    modal.style.display = 'flex';
}

async function processPayment() {
    const contactInput = document.getElementById('pubg-game-id').value;
    if (!contactInput) return tg.showAlert("Ma'lumot kiritilmadi!");

    const product = products.find(p => p.id === currentProductId);
    const btn = document.querySelector('#pubg-id-modal .primary-btn');
    
    btn.disabled = true;
    btn.innerText = "Yuborilmoqda...";

    try {
        const res = await fetch(`${API_BASE_URL}/api/place-order`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                telegram_id: userTelegramId,
                type: product.type,
                name: product.label,
                contact: contactInput,
                total_price: product.price
            })
        });

        if (res.ok) {
            cart = [];
            updateCartUI();
            closePubgModal();
            tg.showPopup({
                title: "Muvaffaqiyatli!",
                message: "Buyurtmangiz qabul qilindi. Operator tasdiqlashini kuting.",
                buttons: [{type: "ok"}]
            });
            fetchBalance();
        } else {
            const err = await res.json();
            tg.showAlert(err.error || "Xatolik!");
        }
    } catch (e) {
        tg.showAlert("Server bilan aloqa uzildi!");
    } finally {
        btn.disabled = false;
        btn.innerText = "To'lash";
    }
}

// ==========================================
// 6. TOPUP (BALANCE) LOGIC
// ==========================================
function previewFile() {
    const file = document.getElementById('receipt-upload').files[0];
    const hint = document.getElementById('file-preview-name');
    if (file) {
        hint.innerText = "Tanlandi: " + file.name;
        const reader = new FileReader();
        reader.onload = (e) => selectedFileBase64 = e.target.result;
        reader.readAsDataURL(file);
    }
}

async function requestTopup() {
    const amount = document.getElementById('topup-amount').value;
    const btn = document.getElementById('send-topup-btn');

    if (!amount || amount < 1000) return tg.showAlert("Minimal 1000 UZS");
    if (!selectedFileBase64) return tg.showAlert("Chek rasmini yuklang!");

    btn.disabled = true;
    btn.innerText = "Yuborilmoqda...";

    try {
        const res = await fetch(`${API_BASE_URL}/api/request-topup`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                telegram_id: userTelegramId,
                amount: amount,
                image: selectedFileBase64
            })
        });

        if (res.ok) {
            closeTopupModal();
            tg.showPopup({
                title: "Yuborildi!",
                message: "Chek tekshirilgach balans to'ldiriladi (1-10 daqiqa).",
                buttons: [{type: "ok"}]
            });
        }
    } catch (e) { tg.showAlert("Xatolik!"); }
    finally {
        btn.disabled = false;
        btn.innerText = "Tasdiqlash";
    }
}

// ==========================================
// 7. UTILS & UI
// ==========================================
async function fetchBalance() {
    try {
        const res = await fetch(`${API_BASE_URL}/api/user?id=${userTelegramId}`);
        const data = await res.json();
        if (data && data.balance !== undefined) {
            userBalance = data.balance;
            document.getElementById('balance-display').innerText = formatMoney(userBalance) + ' UZS';
        }
    } catch (e) { console.error("Balance fetch error"); }
}

function formatMoney(n) { return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, " "); }

function closePubgModal() { document.getElementById('pubg-id-modal').style.display = 'none'; }
function openTopupModal() { document.getElementById('topup-modal').style.display = 'flex'; }
function closeTopupModal() { document.getElementById('topup-modal').style.display = 'none'; }

function copyCard(number) {
    navigator.clipboard.writeText(number);
    tg.showAlert("Karta raqami nusxalandi!");
}

function createExplosionParticles() {
    const container = document.querySelector('.particles');
    if(!container) return;
    for (let i = 0; i < 30; i++) {
        const p = document.createElement('div');
        p.classList.add('particle');
        container.appendChild(p);
        const angle = Math.random() * Math.PI * 2;
        const dist = 100 + Math.random() * 100;
        p.style.setProperty('--x', Math.cos(angle) * dist + 'px');
        p.style.setProperty('--y', Math.sin(angle) * dist + 'px');
        setTimeout(() => p.classList.add('active'), 1500);
    }
}

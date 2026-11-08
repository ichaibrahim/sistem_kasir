<?php
session_start();
require_once 'koneksi.php';

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$currentUser = $_SESSION['user'];
$isAdmin = ($currentUser['role'] === 'Admin');

// API Endpoint: Cari Produk
if (isset($_GET['action']) && $_GET['action'] === 'search_product') {
    header('Content-Type: application/json');
    $sku = trim($_GET['sku'] ?? '');
    
    $stmt = $pdo->prepare("SELECT * FROM produk WHERE barcode_sku = :sku LIMIT 1");
    $stmt->execute(['sku' => $sku]);
    $product = $stmt->fetch();
    
    if ($product) {
        echo json_encode(['status' => 'success', 'data' => $product]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Produk tidak ditemukan!']);
    }
    exit;
}

// API Endpoint: Proses Checkout Transaksi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'process_checkout') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || empty($input['cart'])) {
        echo json_encode(['status' => 'error', 'message' => 'Keranjang belanja kosong!']);
        exit;
    }

    $cart = $input['cart'];
    $metode = $input['metode'] ?? 'Tunai';
    $uang_bayar = floatval($input['uang_bayar'] ?? 0);
    $total_harga = 0;

    foreach ($cart as $item) {
        $total_harga += ($item['harga_jual'] * $item['qty']);
    }

    $uang_kembali = ($metode === 'Tunai') ? ($uang_bayar - $total_harga) : 0;
    if ($metode === 'Tunai' && $uang_kembali < 0) {
        echo json_encode(['status' => 'error', 'message' => 'Uang pembayaran kurang!']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $no_trx = 'TRX-' . date('YmdHis') . '-' . rand(100, 999);
        $stmtTrx = $pdo->prepare("INSERT INTO transaksi (nomor_transaksi, user_id, total_harga, metode_pembayaran, uang_bayar, uang_kembali) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtTrx->execute([$no_trx, $currentUser['id'], $total_harga, $metode, $uang_bayar, $uang_kembali]);
        $transaksi_id = $pdo->lastInsertId();

        $stmtDetail = $pdo->prepare("INSERT INTO detail_transaksi (transaksi_id, produk_id, jumlah, harga_satuan, subtotal) VALUES (?, ?, ?, ?, ?)");
        $stmtUpdateStok = $pdo->prepare("UPDATE produk SET stok = stok - ? WHERE id = ? AND stok >= ?");

        foreach ($cart as $item) {
            $subtotal = $item['harga_jual'] * $item['qty'];
            
            $stmtUpdateStok->execute([$item['qty'], $item['id'], $item['qty']]);
            if ($stmtUpdateStok->rowCount() === 0) {
                throw new Exception("Stok untuk produk " . $item['nama_produk'] . " tidak mencukupi!");
            }

            $stmtDetail->execute([$transaksi_id, $item['id'], $item['qty'], $item['harga_jual'], $subtotal]);
        }

        $pdo->commit();
        echo json_encode([
            'status' => 'success',
            'message' => 'Transaksi berhasil!',
            'data' => [
                'id' => $transaksi_id,
                'nomor_transaksi' => $no_trx,
                'tanggal' => date('d-m-Y H:i:s'),
                'kasir' => $currentUser['nama_lengkap'],
                'total_harga' => $total_harga,
                'metode' => $metode,
                'uang_bayar' => ($metode === 'Tunai') ? $uang_bayar : $total_harga,
                'uang_kembali' => $uang_kembali,
                'cart' => $cart
            ]
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Aksi Void Transaksi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crud_action']) && $_POST['crud_action'] === 'delete_transaction') {
    if (!$isAdmin) die("Akses ditolak!");
    $trx_id = $_POST['id'];
    
    $items = $pdo->prepare("SELECT produk_id, jumlah FROM detail_transaksi WHERE transaksi_id = ?");
    $items->execute([$trx_id]);
    foreach ($items->fetchAll() as $it) {
        $pdo->prepare("UPDATE produk SET stok = stok + ? WHERE id = ?")->execute([$it['jumlah'], $it['produk_id']]);
    }

    $pdo->prepare("DELETE FROM transaksi WHERE id = ?")->execute([$trx_id]);
    header("Location: index.php?tab=history");
    exit;
}

// CRUD Produk
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crud_action']) && $_POST['crud_action'] !== 'delete_transaction') {
    if (!$isAdmin) die("Akses ditolak!");
    $act = $_POST['crud_action'];
    if ($act === 'create') {
        $stmt = $pdo->prepare("INSERT INTO produk (nama_produk, kategori, harga_beli, harga_jual, stok, barcode_sku) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$_POST['nama_produk'], $_POST['kategori'], $_POST['harga_beli'], $_POST['harga_jual'], $_POST['stok'], $_POST['barcode_sku']]);
    } elseif ($act === 'update') {
        $stmt = $pdo->prepare("UPDATE produk SET nama_produk=?, kategori=?, harga_beli=?, harga_jual=?, stok=?, barcode_sku=? WHERE id=?");
        $stmt->execute([$_POST['nama_produk'], $_POST['kategori'], $_POST['harga_beli'], $_POST['harga_jual'], $_POST['stok'], $_POST['barcode_sku'], $_POST['id']]);
    } elseif ($act === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM produk WHERE id=?");
        $stmt->execute([$_POST['id']]);
    }
    header("Location: index.php?tab=inventory");
    exit;
}

$laporan_hari_ini = $pdo->query("SELECT SUM(total_harga) AS omset, COUNT(id) AS total_trx FROM transaksi WHERE DATE(tanggal) = CURDATE()")->fetch();
$total_terjual_hari_ini = $pdo->query("SELECT SUM(jumlah) AS terjual FROM detail_transaksi dt JOIN transaksi t ON dt.transaksi_id = t.id WHERE DATE(t.tanggal) = CURDATE()")->fetch();
$q_modal_hari_ini = $pdo->query("SELECT SUM(dt.jumlah * p.harga_beli) AS total_modal FROM detail_transaksi dt JOIN produk p ON dt.produk_id = p.id JOIN transaksi t ON dt.transaksi_id = t.id WHERE DATE(t.tanggal) = CURDATE()")->fetch();
$keuntungan_hari_ini = ($laporan_hari_ini['omset'] ?? 0) - ($q_modal_hari_ini['total_modal'] ?? 0);

$daftar_produk = $pdo->query("SELECT * FROM produk ORDER BY id DESC")->fetchAll();
$riwayat_kasir = $pdo->query("SELECT t.*, u.nama_lengkap AS nama_kasir FROM transaksi t LEFT JOIN users u ON t.user_id = u.id WHERE DATE(t.tanggal) = CURDATE() ORDER BY t.id DESC")->fetchAll();

$filter_tanggal = $_GET['filter_tgl'] ?? '';
if ($isAdmin) {
    if (!empty($filter_tanggal)) {
        $stmt_riwayat_admin = $pdo->prepare("SELECT t.*, u.nama_lengkap AS nama_kasir FROM transaksi t LEFT JOIN users u ON t.user_id = u.id WHERE DATE(t.tanggal) = ? ORDER BY t.id DESC");
        $stmt_riwayat_admin->execute([$filter_tanggal]);
        $riwayat_admin = $stmt_riwayat_admin->fetchAll();
    } else {
        $riwayat_admin = $pdo->query("SELECT t.*, u.nama_lengkap AS nama_kasir FROM transaksi t LEFT JOIN users u ON t.user_id = u.id ORDER BY t.id DESC LIMIT 100")->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Front Cashier - Toko Kelontong</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
        }
    </script>
    <style>
        @media print {
            body * { visibility: hidden; }
            #receipt-print-area, #receipt-print-area * { visibility: visible; }
            #receipt-print-area {
                display: block !important;
                position: absolute; left: 0; top: 0; width: 58mm; padding: 5px; font-family: 'Courier New', Courier, monospace; font-size: 11px; color: #000; background: #fff;
            }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body class="bg-gray-100 dark:bg-zinc-950 text-slate-700 dark:text-zinc-200 font-sans min-h-screen flex selection:bg-blue-500 selection:text-white">

    <!-- SIDEBAR KIRI DENGAN IKON SVG VETOR -->
    <aside class="w-20 bg-white dark:bg-zinc-900 border-r border-slate-200 dark:border-zinc-800 flex flex-col items-center py-6 justify-between shrink-0 no-print shadow-xs">
        <div class="space-y-6 flex flex-col items-center">
            <!-- Logo Ikon Vektor -->
            <div class="w-12 h-12 bg-blue-600 rounded-2xl flex items-center justify-center text-white shadow-md shadow-blue-500/20">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </div>
            
            <!-- Navigasi Menu Sidebar -->
            <nav class="space-y-3 flex flex-col items-center">
                <!-- Tombol Kasir POS -->
                <button onclick="switchTab('pos')" id="sidebar-pos" title="Kasir Penjualan" class="w-12 h-12 rounded-2xl flex items-center justify-center transition bg-blue-600 text-white shadow-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                </button>
                <!-- Tombol Riwayat -->
                <button onclick="switchTab('history')" id="sidebar-history" title="Riwayat Transaksi" class="w-12 h-12 rounded-2xl flex items-center justify-center text-slate-400 dark:text-zinc-400 hover:bg-slate-100 dark:hover:bg-zinc-800 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012-2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
                </button>
                <?php if ($isAdmin): ?>
                    <!-- Tombol Stok / Inventori -->
                    <button onclick="switchTab('inventory')" id="sidebar-inventory" title="Kelola Stok" class="w-12 h-12 rounded-2xl flex items-center justify-center text-slate-400 dark:text-zinc-400 hover:bg-slate-100 dark:hover:bg-zinc-800 transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    </button>
                    <!-- Tombol Laporan -->
                    <button onclick="switchTab('reports')" id="sidebar-reports" title="Laporan Keuangan" class="w-12 h-12 rounded-2xl flex items-center justify-center text-slate-400 dark:text-zinc-400 hover:bg-slate-100 dark:hover:bg-zinc-800 transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </button>
                <?php endif; ?>
            </nav>
        </div>
        <!-- Tombol Logout -->
        <div>
            <a href="logout.php" title="Keluar Sistem" class="w-12 h-12 rounded-2xl flex items-center justify-center text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/30 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            </a>
        </div>
    </aside>

    <!-- AREA KONTEN UTAMA -->
    <div class="flex-1 flex flex-col min-w-0 no-print">
        
        <!-- TOP BAR -->
        <header class="h-20 bg-white dark:bg-zinc-900 border-b border-slate-200 dark:border-zinc-800 px-8 flex justify-between items-center shadow-xs">
            <div class="relative w-96 hidden md:block">
                <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400 dark:text-zinc-500">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </span>
                <input type="text" id="global-search" placeholder="Cari menu atau produk..." class="w-full pl-11 pr-4 py-2.5 bg-slate-50 dark:bg-zinc-800/60 border border-slate-200 dark:border-zinc-700 rounded-full text-xs font-medium focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-zinc-100">
            </div>

            <div class="flex items-center gap-4">
                <!-- Tombol Mode Tema -->
                <button onclick="toggleDarkMode()" id="theme-toggle-btn" class="px-4 h-10 rounded-2xl bg-slate-100 dark:bg-zinc-800 border border-slate-200 dark:border-zinc-700 flex items-center gap-2 text-xs font-medium transition hover:scale-105">
                    <svg id="theme-icon" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    <span id="theme-text">Mode Terang</span>
                </button>
                <!-- Profil Pengguna -->
                <div class="flex items-center gap-3 pl-4 border-l border-slate-200 dark:border-zinc-800">
                    <div class="w-9 h-9 rounded-2xl bg-blue-50 dark:bg-blue-950/40 text-blue-600 dark:text-blue-400 font-bold flex items-center justify-center text-xs shadow-xs">
                        <?= strtoupper(substr($currentUser['nama_lengkap'], 0, 1)) ?>
                    </div>
                    <div class="text-left hidden sm:block">
                        <div class="text-xs font-bold text-slate-800 dark:text-zinc-100 leading-tight"><?= htmlspecialchars($currentUser['nama_lengkap']) ?></div>
                        <span class="text-[9px] font-bold text-blue-600 dark:text-blue-400 uppercase tracking-wider"><?= $currentUser['role'] ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- KONTEN UTAMA -->
        <main class="flex-1 p-8 overflow-y-auto">

            <!-- TAB 1: KASIR (POS) -->
            <div id="tab-pos" class="tab-content grid grid-cols-1 lg:grid-cols-3 gap-8">
                
                <div class="lg:col-span-2 space-y-6">
                    <!-- Kategori Pilihan -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <div onclick="filterCategory('All')" class="p-3.5 bg-blue-600 text-white rounded-2xl shadow-xs cursor-pointer flex items-center gap-3 transition">
                            <div>
                                <div class="text-xs font-bold">Semua Menu</div>
                                <span class="text-[10px] text-blue-100">Tampilkan Semua</span>
                            </div>
                        </div>
                        <div onclick="filterCategory('Sembako')" class="p-4 bg-white dark:bg-zinc-900 border border-slate-200 dark:border-zinc-800 rounded-2xl shadow-xs cursor-pointer flex items-center gap-3 hover:border-blue-500 transition">
                            <div>
                                <div class="text-xs font-bold text-slate-800 dark:text-zinc-200">Sembako</div>
                                <span class="text-[10px] text-slate-400">Kebutuhan Pokok</span>
                            </div>
                        </div>
                        <div onclick="filterCategory('Makanan')" class="p-4 bg-white dark:bg-zinc-900 border border-slate-200 dark:border-zinc-800 rounded-2xl shadow-xs cursor-pointer flex items-center gap-3 hover:border-blue-500 transition">
                            <div>
                                <div class="text-xs font-bold text-slate-800 dark:text-zinc-200">Makanan</div>
                                <span class="text-[10px] text-slate-400">Snack & Mie</span>
                            </div>
                        </div>
                        <div onclick="filterCategory('Minuman')" class="p-4 bg-white dark:bg-zinc-900 border border-slate-200 dark:border-zinc-800 rounded-2xl shadow-xs cursor-pointer flex items-center gap-3 hover:border-blue-500 transition">
                            <div>
                                <div class="text-xs font-bold text-slate-800 dark:text-zinc-200">Minuman</div>
                                <span class="text-[10px] text-slate-400">Kopi & Teh</span>
                            </div>
                        </div>
                    </div>

                    <!-- Input Barcode Cepat -->
                    <div class="bg-white dark:bg-zinc-900 p-4 rounded-2xl shadow-xs border border-slate-200 dark:border-zinc-800 space-y-1.5">
                        <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider">Scan Barcode / Input SKU Produk</label>
                        <div class="flex gap-2">
                            <input type="text" id="barcode-input" autofocus placeholder="Tekan Enter setelah scan barcode atau ketik SKU..." class="w-full px-4 py-2.5 bg-slate-50 dark:bg-zinc-800 border border-slate-200 dark:border-zinc-700 rounded-xl text-xs font-medium focus:outline-none focus:ring-2 focus:ring-blue-500 dark:text-zinc-100">
                            <button onclick="scanBarcode()" class="px-5 py-2.5 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition text-xs shadow-xs">Cari</button>
                        </div>
                    </div>

                    <!-- Katalog Produk Grid Menu -->
                    <div class="space-y-3">
                        <h3 class="font-bold text-slate-800 dark:text-zinc-200 text-xs uppercase tracking-wider px-1">Daftar Menu Produk</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                            <?php foreach ($daftar_produk as $prod): ?>
                                <div onclick="addToCart(<?= htmlspecialchars(json_encode($prod)) ?>)" data-category="<?= htmlspecialchars($prod['kategori']) ?>" class="product-card bg-white dark:bg-zinc-900 p-4 rounded-2xl shadow-xs border border-slate-200 dark:border-zinc-800 hover:border-blue-500 cursor-pointer transition flex flex-col justify-between group">
                                    <div>
                                        <div class="flex justify-between items-start mb-2">
                                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 dark:bg-zinc-800 text-slate-600 dark:text-zinc-400 uppercase"><?= htmlspecialchars($prod['kategori']) ?></span>
                                            <span class="text-[10px] font-bold <?= $prod['stok'] < 5 ? 'text-rose-500' : 'text-emerald-500' ?>">Stok: <?= $prod['stok'] ?></span>
                                        </div>
                                        <h4 class="font-bold text-slate-800 dark:text-zinc-100 text-sm group-hover:text-blue-600 transition"><?= htmlspecialchars($prod['nama_produk']) ?></h4>
                                        <p class="text-[10px] text-slate-400 font-mono mt-0.5">SKU: <?= htmlspecialchars($prod['barcode_sku']) ?></p>
                                    </div>
                                    <div class="mt-4 flex justify-between items-center pt-3 border-t border-slate-100 dark:border-zinc-800">
                                        <span class="font-black text-sm text-slate-900 dark:text-white"><?= rupiah($prod['harga_jual']) ?></span>
                                        <div class="w-7 h-7 rounded-full bg-blue-50 dark:bg-blue-950 text-blue-600 dark:text-blue-400 flex items-center justify-center font-bold text-xs group-hover:bg-blue-600 group-hover:text-white transition">
                                            +
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Sisi Kanan: Invoice & Keranjang -->
                <div class="bg-white dark:bg-zinc-900 p-6 rounded-3xl shadow-xs border border-slate-200 dark:border-zinc-800 flex flex-col justify-between h-full">
                    <div>
                        <div class="flex justify-between items-center pb-4 border-b border-slate-100 dark:border-zinc-800">
                            <h2 class="text-xs font-bold text-slate-800 dark:text-zinc-100 uppercase tracking-wider">Invoice / Keranjang</h2>
                            <button onclick="clearCart()" class="text-[11px] font-bold text-rose-500 hover:text-rose-700">Kosongkan</button>
                        </div>

                        <div id="cart-list" class="divide-y divide-slate-100 dark:divide-zinc-800 max-h-[300px] overflow-y-auto my-4 pr-1">
                            <p class="text-slate-400 dark:text-zinc-500 text-xs text-center py-12">Belum ada item di keranjang.</p>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-slate-100 dark:border-zinc-800 space-y-4 bg-slate-50/50 dark:bg-zinc-800/30 -mx-6 -mb-6 p-6 rounded-b-3xl">
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-slate-500 dark:text-zinc-400 font-medium">Sub Total</span>
                            <span id="sub-total" class="font-bold text-slate-800 dark:text-zinc-200">Rp 0</span>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-slate-500 dark:text-zinc-400 font-medium">Pajak (Tax)</span>
                            <span class="font-bold text-slate-800 dark:text-zinc-200">Rp 0</span>
                        </div>
                        <div class="flex justify-between items-center pt-2 border-t border-slate-200 dark:border-zinc-700">
                            <span class="text-slate-800 dark:text-zinc-100 font-bold text-xs uppercase">Total Payment</span>
                            <span id="grand-total" class="text-xl font-black text-blue-600 dark:text-blue-400">Rp 0</span>
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Metode Pembayaran</label>
                            <select id="metode-bayar" onchange="toggleMetode()" class="w-full p-2.5 bg-white dark:bg-zinc-800 border border-slate-200 dark:border-zinc-700 rounded-xl text-xs font-medium focus:ring-2 focus:ring-blue-500 dark:text-zinc-100">
                                <option value="Tunai">Tunai (Cash)</option>
                                <option value="QRIS/Nontunai">QRIS / Non-Tunai (Transfer/Wallet)</option>
                            </select>
                        </div>

                        <div id="box-tunai" class="flex gap-2">
                            <div class="w-1/2">
                                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Uang Bayar</label>
                                <input type="number" id="uang-bayar" oninput="calculateChange()" placeholder="0" class="w-full p-2.5 bg-white dark:bg-zinc-800 border border-slate-200 dark:border-zinc-700 rounded-xl text-xs font-medium focus:outline-none dark:text-zinc-100">
                            </div>
                            <div class="w-1/2">
                                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Kembalian</label>
                                <input type="text" id="uang-kembali" readonly value="Rp 0" class="w-full p-2.5 bg-slate-100 dark:bg-zinc-700 border border-slate-200 dark:border-zinc-600 rounded-xl text-xs font-bold text-slate-700 dark:text-zinc-200">
                            </div>
                        </div>

                        <button onclick="processCheckout()" class="w-full py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-xl shadow-lg shadow-blue-500/20 transition text-xs">
                            Selesaikan Transaksi & Cetak Struk
                        </button>
                    </div>
                </div>
            </div>

            <!-- TAB 2: RIWAYAT TRANSAKSI -->
            <div id="tab-history" class="tab-content hidden space-y-6">
                <div class="bg-white dark:bg-zinc-900 p-6 rounded-3xl shadow-xs border border-slate-200 dark:border-zinc-800 space-y-4">
                    <div class="flex justify-between items-center border-b border-slate-100 dark:border-zinc-800 pb-4">
                        <div>
                            <h2 class="text-sm font-bold text-slate-800 dark:text-zinc-100 uppercase tracking-wider">Riwayat Transaksi</h2>
                            <p class="text-xs text-slate-400"><?= $isAdmin ? 'Riwayat lengkap sistem toko' : 'Riwayat shift hari ini' ?></p>
                        </div>
                        <?php if ($isAdmin): ?>
                            <form method="GET" class="flex gap-2 items-center">
                                <input type="hidden" name="tab" value="history">
                                <input type="date" name="filter_tgl" value="<?= htmlspecialchars($filter_tanggal) ?>" class="p-2 border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 rounded-xl text-xs dark:text-zinc-100">
                                <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-xs font-bold rounded-xl hover:bg-blue-700">Filter</button>
                                <a href="index.php" class="px-4 py-2 bg-slate-100 dark:bg-zinc-800 text-slate-600 dark:text-zinc-300 text-xs font-bold rounded-xl">Reset</a>
                            </form>
                        <?php endif; ?>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-slate-50 dark:bg-zinc-800/50 text-slate-400 uppercase text-[9px] font-bold tracking-wider border-b border-slate-200 dark:border-zinc-800">
                                <tr>
                                    <th class="p-3">No. Transaksi</th>
                                    <th class="p-3">Waktu</th>
                                    <th class="p-3">Kasir</th>
                                    <th class="p-3">Metode</th>
                                    <th class="p-3">Total Belanja</th>
                                    <th class="p-3 text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">
                                <?php 
                                $target_riwayat = $isAdmin ? $riwayat_admin : $riwayat_kasir;
                                if (empty($target_riwayat)): 
                                ?>
                                    <tr><td colspan="6" class="text-center py-10 text-slate-400">Belum ada riwayat transaksi.</td></tr>
                                <?php else: foreach ($target_riwayat as $trx): ?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-zinc-800/40 transition">
                                        <td class="p-3 font-mono font-bold text-blue-600 dark:text-blue-400"><?= $trx['nomor_transaksi'] ?></td>
                                        <td class="p-3 text-slate-500 dark:text-zinc-400"><?= $trx['tanggal'] ?></td>
                                        <td class="p-3 text-slate-700 dark:text-zinc-200 font-medium"><?= htmlspecialchars($trx['nama_kasir'] ?? 'Umum') ?></td>
                                        <td class="p-3"><span class="px-2 py-0.5 rounded-full bg-slate-100 dark:bg-zinc-800 text-slate-700 dark:text-zinc-300 text-[10px] font-bold"><?= $trx['metode_pembayaran'] ?></span></td>
                                        <td class="p-3 font-black text-slate-900 dark:text-white"><?= rupiah($trx['total_harga']) ?></td>
                                        <td class="p-3 text-center flex justify-center gap-2">
                                            <button onclick='reprintReceipt(<?= json_encode($trx) ?>)' class="px-3 py-1 bg-blue-600 text-white text-xs font-bold rounded-xl hover:bg-blue-700">Cetak Struk</button>
                                            <?php if ($isAdmin): ?>
                                                <form method="POST" onsubmit="return confirm('Void transaksi ini akan mengembalikan stok produk. Lanjutkan?');" class="inline">
                                                    <input type="hidden" name="crud_action" value="delete_transaction">
                                                    <input type="hidden" name="id" value="<?= $trx['id'] ?>">
                                                    <button type="submit" class="px-3 py-1 bg-rose-500 text-white text-xs font-bold rounded-xl hover:bg-rose-600">Void</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php if ($isAdmin): ?>
            <!-- TAB 3: INVENTORI (Admin Only) -->
            <div id="tab-inventory" class="tab-content hidden space-y-6">
                <div class="flex justify-between items-center bg-white dark:bg-zinc-900 p-6 rounded-3xl shadow-xs border border-slate-200 dark:border-zinc-800">
                    <div>
                        <h2 class="text-sm font-bold text-slate-800 dark:text-zinc-100 uppercase tracking-wider">Manajemen Stok Barang</h2>
                        <p class="text-xs text-slate-400">Kelola data inventori produk toko</p>
                    </div>
                    <button onclick="openProductModal()" class="px-5 py-2.5 bg-blue-600 text-white rounded-xl text-xs font-bold hover:bg-blue-700 shadow-sm">+ Tambah Produk</button>
                </div>
                <div class="bg-white dark:bg-zinc-900 rounded-3xl shadow-xs border border-slate-200 dark:border-zinc-800 overflow-hidden">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-50 dark:bg-zinc-800/50 text-slate-400 uppercase text-[9px] font-bold tracking-wider border-b border-slate-200 dark:border-zinc-800">
                            <tr>
                                <th class="p-3">SKU</th>
                                <th class="p-3">Nama Produk</th>
                                <th class="p-3">Kategori</th>
                                <th class="p-3">Harga Beli</th>
                                <th class="p-3">Harga Jual</th>
                                <th class="p-3">Stok</th>
                                <th class="p-3 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-zinc-800">
                            <?php foreach ($daftar_produk as $prod): ?>
                                <tr class="hover:bg-slate-50 dark:hover:bg-zinc-800/40 transition">
                                    <td class="p-3 font-mono text-slate-500 dark:text-zinc-400"><?= htmlspecialchars($prod['barcode_sku']) ?></td>
                                    <td class="p-3 font-bold text-slate-800 dark:text-zinc-100"><?= htmlspecialchars($prod['nama_produk']) ?></td>
                                    <td class="p-3 text-slate-500 dark:text-zinc-400"><?= htmlspecialchars($prod['kategori']) ?></td>
                                    <td class="p-3 text-slate-500 dark:text-zinc-400"><?= rupiah($prod['harga_beli']) ?></td>
                                    <td class="p-3 font-bold text-slate-800 dark:text-zinc-100"><?= rupiah($prod['harga_jual']) ?></td>
                                    <td class="p-3">
                                        <?php if ($prod['stok'] < 5): ?>
                                            <span class="px-2 py-0.5 bg-rose-50 text-rose-600 rounded font-bold text-[10px]">Stok: <?= $prod['stok'] ?> (Menipis)</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 bg-emerald-50 text-emerald-600 rounded font-bold text-[10px]">Stok: <?= $prod['stok'] ?> pcs</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3 text-center flex justify-center gap-2">
                                        <button onclick='editProduct(<?= json_encode($prod) ?>)' class="px-3 py-1 bg-amber-500 text-white font-bold text-xs rounded-xl hover:bg-amber-600">Edit</button>
                                        <form method="POST" onsubmit="return confirm('Hapus produk ini?');" class="inline">
                                            <input type="hidden" name="crud_action" value="delete">
                                            <input type="hidden" name="id" value="<?= $prod['id'] ?>">
                                            <button type="submit" class="px-3 py-1 bg-rose-500 text-white font-bold text-xs rounded-xl hover:bg-rose-600">Hapus</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 4: LAPORAN KEUANGAN (Admin Only) -->
            <div id="tab-reports" class="tab-content hidden space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="bg-white dark:bg-zinc-900 p-6 rounded-3xl shadow-xs border border-slate-200 dark:border-zinc-800 space-y-2">
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Omset Hari Ini</p>
                        <h3 class="text-3xl font-black text-slate-900 dark:text-white"><?= rupiah($laporan_hari_ini['omset'] ?? 0) ?></h3>
                        <p class="text-xs text-slate-400"><?= $laporan_hari_ini['total_trx'] ?? 0 ?> transaksi sukses</p>
                    </div>
                    <div class="bg-white dark:bg-zinc-900 p-6 rounded-3xl shadow-xs border border-slate-200 dark:border-zinc-800 space-y-2">
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Estimasi Keuntungan (Profit)</p>
                        <h3 class="text-3xl font-black text-emerald-600"><?= rupiah($keuntungan_hari_ini) ?></h3>
                        <p class="text-xs text-slate-400">Omset bersih dikurangi modal</p>
                    </div>
                    <div class="bg-white dark:bg-zinc-900 p-6 rounded-3xl shadow-xs border border-slate-200 dark:border-zinc-800 space-y-2">
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Item Keluar</p>
                        <h3 class="text-3xl font-black text-blue-600"><?= $total_terjual_hari_ini['terjual'] ?? 0 ?> <span class="text-base font-normal">pcs</span></h3>
                        <p class="text-xs text-slate-400">Barang terjual hari ini</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </main>
    </div>

    <!-- Modal Produk (Admin) -->
    <?php if ($isAdmin): ?>
    <div id="product-modal" class="fixed inset-0 bg-slate-950/60 backdrop-blur-xs hidden flex items-center justify-center p-4 z-50 no-print">
        <div class="bg-white dark:bg-zinc-900 rounded-3xl max-w-md w-full p-8 shadow-2xl space-y-4 border border-slate-200 dark:border-zinc-800">
            <h3 id="modal-title" class="text-sm font-bold text-slate-800 dark:text-zinc-100 uppercase tracking-wider">Tambah Produk Baru</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="crud_action" id="modal-action" value="create">
                <input type="hidden" name="id" id="modal-id">
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">SKU / Barcode</label>
                    <input type="text" name="barcode_sku" id="modal-sku" required class="w-full p-3 bg-slate-50 dark:bg-zinc-800 border border-slate-200 dark:border-zinc-700 rounded-xl text-xs font-medium dark:text-zinc-100">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Nama Produk</label>
                    <input type="text" name="nama_produk" id="modal-nama" required class="w-full p-3 bg-slate-50 dark:bg-zinc-800 border border-slate-200 dark:border-zinc-700 rounded-xl text-xs font-medium dark:text-zinc-100">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Kategori</label>
                    <input type="text" name="kategori" id="modal-kategori" required class="w-full p-3 bg-slate-50 dark:bg-zinc-800 border border-slate-200 dark:border-zinc-700 rounded-xl text-xs font-medium dark:text-zinc-100">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Harga Beli</label>
                        <input type="number" name="harga_beli" id="modal-hbeli" required class="w-full p-3 bg-slate-50 dark:bg-zinc-800 border border-slate-200 dark:border-zinc-700 rounded-xl text-xs font-medium dark:text-zinc-100">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Harga Jual</label>
                        <input type="number" name="harga_jual" id="modal-hjual" required class="w-full p-3 bg-slate-50 dark:bg-zinc-800 border border-slate-200 dark:border-zinc-700 rounded-xl text-xs font-medium dark:text-zinc-100">
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Stok Awal</label>
                    <input type="number" name="stok" id="modal-stok" required class="w-full p-3 bg-slate-50 dark:bg-zinc-800 border border-slate-200 dark:border-zinc-700 rounded-xl text-xs font-medium dark:text-zinc-100">
                </div>
                <div class="flex justify-end gap-2 pt-4 border-t border-slate-100 dark:border-zinc-800">
                    <button type="button" onclick="closeProductModal()" class="px-5 py-2.5 border border-slate-200 dark:border-zinc-700 rounded-xl text-xs font-bold text-slate-600 dark:text-zinc-300">Batal</button>
                    <button type="submit" class="px-5 py-2.5 bg-blue-600 text-white rounded-xl text-xs font-bold hover:bg-blue-700 shadow-sm">Simpan</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Template Thermal Receipt -->
    <div id="receipt-print-area" style="display: none;">
        <div style="text-align: center; font-weight: bold; font-size: 14px;">TOKO KELONTONG</div>
        <div style="text-align: center; margin-bottom: 5px; font-size: 11px;">Jl. Raya Utama No. 12, Indonesia</div>
        <div style="border-bottom: 1px dashed #000; margin: 6px 0;"></div>
        <div style="font-size: 11px;">No  : <span id="r-trx"></span></div>
        <div style="font-size: 11px;">Tgl : <span id="r-tgl"></span></div>
        <div style="font-size: 11px;">Kasir: <span id="r-kasir"></span></div>
        <div style="border-bottom: 1px dashed #000; margin: 6px 0;"></div>
        <div id="r-items" style="font-size: 11px;"></div>
        <div style="border-bottom: 1px dashed #000; margin: 6px 0;"></div>
        <table style="width: 100%; font-size: 11px;">
            <tr><td>Total:</td><td style="text-align: right; font-weight: bold;" id="r-total"></td></tr>
            <tr><td>Metode:</td><td style="text-align: right;" id="r-metode"></td></tr>
            <tr><td>Bayar:</td><td style="text-align: right;" id="r-bayar"></td></tr>
            <tr><td>Kembali:</td><td style="text-align: right;" id="r-kembali"></td></tr>
        </table>
        
        <div id="r-qris-info" style="display: none; margin-top: 8px; font-size: 10px; border-top: 1px dashed #000; padding-top: 4px;">
            <div style="text-align: center; font-weight: bold;">INFO PEMBAYARAN NON-TUNAI</div>
            <div>Merchant : TOKO KELONTONG</div>
            <div>Akun/QRIS: 0812-3456-7890 (DANA/OVO/BCA)</div>
            <div>Status   : <span style="color: green; font-weight: bold;">LUNAS (VERIFIED)</span></div>
        </div>

        <div style="border-bottom: 1px dashed #000; margin: 6px 0;"></div>
        <div style="text-align: center; margin-top: 6px; font-size: 11px; font-weight: bold;">*** TERIMA KASIH ***</div>
    </div>

    <!-- JavaScript Interaksi POS & Dark/Light Mode Switcher -->
    <script>
        let cart = [];

        function toggleDarkMode() {
            const html = document.documentElement;
            const textEl = document.getElementById('theme-text');
            if (html.classList.contains('dark')) {
                html.classList.remove('dark');
                localStorage.setItem('theme', 'light');
                textEl.innerText = 'Mode Terang';
            } else {
                html.classList.add('dark');
                localStorage.setItem('theme', 'dark');
                textEl.innerText = 'Mode Gelap';
            }
        }

        if (localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
            document.getElementById('theme-text').innerText = 'Mode Gelap';
        } else {
            document.getElementById('theme-text').innerText = 'Mode Terang';
        }

        function switchTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            const target = document.getElementById('tab-' + tabName);
            if (target) target.classList.remove('hidden');

            document.querySelectorAll('aside nav button').forEach(btn => {
                btn.classList.remove('bg-blue-600', 'text-white', 'shadow-sm');
                btn.classList.add('text-slate-400', 'dark:text-zinc-400', 'hover:bg-slate-100', 'dark:hover:bg-zinc-800');
            });
            const activeBtn = document.getElementById('sidebar-' + tabName);
            if (activeBtn) {
                activeBtn.classList.remove('text-slate-400', 'dark:text-zinc-400', 'hover:bg-slate-100', 'dark:hover:bg-zinc-800');
                activeBtn.classList.add('bg-blue-600', 'text-white', 'shadow-sm');
            }

            if (tabName === 'pos') document.getElementById('barcode-input')?.focus();
        }

        const urlParams = new URLSearchParams(window.location.search);
        const activeTab = urlParams.get('tab');
        if (activeTab) switchTab(activeTab);

        function filterCategory(cat) {
            const cards = document.querySelectorAll('.product-card');
            cards.forEach(card => {
                if (cat === 'All' || card.getAttribute('data-category') === cat) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        document.getElementById('global-search')?.addEventListener('input', function(e) {
            const keyword = e.target.value.toLowerCase();
            const cards = document.querySelectorAll('.product-card');
            cards.forEach(card => {
                const text = card.innerText.toLowerCase();
                card.style.display = text.includes(keyword) ? 'flex' : 'none';
            });
        });

        document.getElementById('barcode-input')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') scanBarcode();
        });

        function scanBarcode() {
            const input = document.getElementById('barcode-input');
            const sku = input.value.trim();
            if (!sku) return;

            fetch(`index.php?action=search_product&sku=${encodeURIComponent(sku)}`)
                .then(res => res.json())
                .then(res => {
                    if (res.status === 'success') {
                        addToCart(res.data);
                        input.value = '';
                    } else {
                        alert(res.message);
                    }
                });
        }

        function addToCart(product) {
            const index = cart.findIndex(item => item.id === product.id);
            if (index > -1) {
                if (cart[index].qty + 1 > product.stok) {
                    alert('Stok barang tidak mencukupi!');
                    return;
                }
                cart[index].qty += 1;
            } else {
                if (product.stok < 1) {
                    alert('Stok barang habis!');
                    return;
                }
                cart.push({ id: product.id, nama_produk: product.nama_produk, harga_jual: parseFloat(product.harga_jual), stok: parseInt(product.stok), qty: 1 });
            }
            renderCart();
        }

        function updateQty(index, change) {
            const item = cart[index];
            if (change > 0 && item.qty + change > item.stok) {
                alert('Stok terbatas!');
                return;
            }
            item.qty += change;
            if (item.qty <= 0) cart.splice(index, 1);
            renderCart();
        }

        function clearCart() {
            cart = [];
            renderCart();
        }

        function renderCart() {
            const container = document.getElementById('cart-list');
            const grandTotalEl = document.getElementById('grand-total');
            const subTotalEl = document.getElementById('sub-total');

            if (cart.length === 0) {
                container.innerHTML = `<p class="text-slate-400 dark:text-zinc-500 text-xs text-center py-12">Belum ada item di keranjang.</p>`;
                grandTotalEl.innerText = 'Rp 0';
                subTotalEl.innerText = 'Rp 0';
                calculateChange();
                return;
            }

            let html = '', total = 0;
            cart.forEach((item, index) => {
                const subtotal = item.harga_jual * item.qty;
                total += subtotal;
                html += `
                    <div class="py-3 flex justify-between items-center text-xs">
                        <div class="w-1/2">
                            <div class="font-bold text-slate-800 dark:text-zinc-100">${item.nama_produk}</div>
                            <div class="text-[10px] text-slate-400">Rp ${item.harga_jual.toLocaleString('id-ID')}</div>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button onclick="updateQty(${index}, -1)" class="w-6 h-6 bg-slate-100 dark:bg-zinc-800 rounded-md font-bold hover:bg-slate-200 dark:hover:bg-zinc-700">-</button>
                            <span class="font-bold text-slate-700 dark:text-zinc-200">${item.qty}</span>
                            <button onclick="updateQty(${index}, 1)" class="w-6 h-6 bg-slate-100 dark:bg-zinc-800 rounded-md font-bold hover:bg-slate-200 dark:hover:bg-zinc-700">+</button>
                        </div>
                        <div class="font-black text-slate-900 dark:text-white text-right">Rp ${subtotal.toLocaleString('id-ID')}</div>
                    </div>
                `;
            });
            container.innerHTML = html;
            subTotalEl.innerText = 'Rp ' + total.toLocaleString('id-ID');
            grandTotalEl.innerText = 'Rp ' + total.toLocaleString('id-ID');
            calculateChange();
        }

        function toggleMetode() {
            const metode = document.getElementById('metode-bayar').value;
            const boxTunai = document.getElementById('box-tunai');
            const uangBayarInput = document.getElementById('uang-bayar');
            
            if (metode === 'QRIS/Nontunai') {
                boxTunai.classList.add('hidden');
                uangBayarInput.value = '';
            } else {
                boxTunai.classList.remove('hidden');
            }
            calculateChange();
        }

        function calculateChange() {
            const total = cart.reduce((acc, item) => acc + (item.harga_jual * item.qty), 0);
            const metode = document.getElementById('metode-bayar').value;
            const uangBayar = parseFloat(document.getElementById('uang-bayar').value) || 0;
            const kembalianEl = document.getElementById('uang-kembali');

            if (metode === 'QRIS/Nontunai') {
                kembalianEl.value = 'Rp 0 (Non-Tunai)';
                return;
            }
            const kembali = uangBayar - total;
            if (kembali < 0) {
                kembalianEl.value = 'Uang Kurang';
            } else {
                kembalianEl.value = 'Rp ' + kembali.toLocaleString('id-ID');
            }
        }

        function processCheckout() {
            if (cart.length === 0) { alert('Keranjang kosong!'); return; }
            const total = cart.reduce((acc, item) => acc + (item.harga_jual * item.qty), 0);
            const metode = document.getElementById('metode-bayar').value;
            const uangBayar = parseFloat(document.getElementById('uang-bayar').value) || 0;

            if (metode === 'Tunai' && uangBayar < total) { 
                alert('Jumlah uang pembayaran tunai kurang dari total tagihan!'); 
                return; 
            }

            const payloadData = {
                cart: cart,
                metode: metode,
                uang_bayar: (metode === 'Tunai') ? uangBayar : total
            };

            fetch('index.php?action=process_checkout', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payloadData)
            })
            .then(res => res.json())
            .then(res => {
                if (res.status === 'success') {
                    printReceipt(res.data);
                    alert('Transaksi sukses & stok berhasil dikurangi!');
                    location.reload();
                } else {
                    alert('Gagal: ' + res.message);
                }
            })
            .catch(err => {
                alert('Terjadi kesalahan koneksi server.');
            });
        }

        function printReceipt(data) {
            document.getElementById('r-trx').innerText = data.nomor_transaksi;
            document.getElementById('r-tgl').innerText = data.tanggal;
            document.getElementById('r-kasir').innerText = data.kasir;
            document.getElementById('r-metode').innerText = data.metode;

            let itemsHtml = '';
            data.cart.forEach(item => {
                itemsHtml += `
                    <div style="margin-bottom: 2px;">
                        <div>${item.nama_produk}</div>
                        <div style="display: flex; justify-content: space-between;">
                            <span>${item.qty} x ${item.harga_jual.toLocaleString('id-ID')}</span>
                            <span>${(item.qty * item.harga_jual).toLocaleString('id-ID')}</span>
                        </div>
                    </div>
                `;
            });
            document.getElementById('r-items').innerHTML = itemsHtml;
            document.getElementById('r-total').innerText = 'Rp ' + data.total_harga.toLocaleString('id-ID');
            document.getElementById('r-bayar').innerText = 'Rp ' + data.uang_bayar.toLocaleString('id-ID');
            document.getElementById('r-kembali').innerText = 'Rp ' + data.uang_kembali.toLocaleString('id-ID');

            const qrisInfo = document.getElementById('r-qris-info');
            if (data.metode === 'QRIS/Nontunai') {
                qrisInfo.style.display = 'block';
            } else {
                qrisInfo.style.display = 'none';
            }

            window.print();
        }

        function reprintReceipt(trx) {
            document.getElementById('r-trx').innerText = trx.nomor_transaksi;
            document.getElementById('r-tgl').innerText = trx.tanggal;
            document.getElementById('r-kasir').innerText = trx.nama_kasir || 'Kasir';
            document.getElementById('r-metode').innerText = trx.metode_pembayaran;
            document.getElementById('r-total').innerText = 'Rp ' + parseFloat(trx.total_harga).toLocaleString('id-ID');
            document.getElementById('r-bayar').innerText = 'Rp ' + parseFloat(trx.uang_bayar).toLocaleString('id-ID');
            document.getElementById('r-kembali').innerText = 'Rp ' + parseFloat(trx.uang_kembali).toLocaleString('id-ID');

            document.getElementById('r-items').innerHTML = `<div style="text-align:center; font-style:italic;">(Cetak Ulang Struk Riwayat)</div>`;

            const qrisInfo = document.getElementById('r-qris-info');
            if (trx.metode_pembayaran === 'QRIS/Nontunai') {
                qrisInfo.style.display = 'block';
            } else {
                qrisInfo.style.display = 'none';
            }

            window.print();
        }

        function openProductModal() {
            document.getElementById('modal-title').innerText = 'Tambah Produk Baru';
            document.getElementById('modal-action').value = 'create';
            document.getElementById('modal-id').value = '';
            ['sku', 'nama', 'kategori', 'hbeli', 'hjual', 'stok'].forEach(id => document.getElementById('modal-' + id).value = '');
            document.getElementById('product-modal').classList.remove('hidden');
        }

        function editProduct(prod) {
            document.getElementById('modal-title').innerText = 'Edit Produk';
            document.getElementById('modal-action').value = 'update';
            document.getElementById('modal-id').value = prod.id;
            document.getElementById('modal-sku').value = prod.barcode_sku;
            document.getElementById('modal-nama').value = prod.nama_produk;
            document.getElementById('modal-kategori').value = prod.kategori;
            document.getElementById('modal-hbeli').value = prod.harga_beli;
            document.getElementById('modal-hjual').value = prod.harga_jual;
            document.getElementById('modal-stok').value = prod.stok;
            document.getElementById('product-modal').classList.remove('hidden');
        }

        function closeProductModal() {
            document.getElementById('product-modal').classList.add('hidden');
        }
    </script>
</body>
</html>
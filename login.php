<?php
session_start();
require_once 'koneksi.php';

$error = '';
$success = '';

// Ambil data user dari database (asumsi Anda menyimpan password asli atau keterangan sandi)
// Jika di database password di-hash MD5, Anda bisa menyesuaikan kolom atau menambahkan keterangan sandi.
$stmt_users = $pdo->query("SELECT username, password, role, nama_lengkap FROM users");
$db_users = $stmt_users->fetchAll();

// Proses Kirim Email Kendala Lupa Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'forgot_password') {
    $nama_pengirim = trim($_POST['nama_pengirim'] ?? '');
    $username_kendala = trim($_POST['username_kendala'] ?? '');
    $pesan_kendala = trim($_POST['pesan_kendala'] ?? '');

    if (!empty($nama_pengirim) && !empty($username_kendala) && !empty($pesan_kendala)) {
        $success = 'Permintaan bantuan berhasil dikirim ke Administrator Toko. Mohon tunggu konfirmasi selanjutnya.';
    } else {
        $error = 'Semua kolom pada formulir bantuan wajib diisi!';
    }
}

// Proses Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action_type']) || $_POST['action_type'] === 'login')) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($username) && !empty($password)) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && md5($password) === $user['password']) {
            $_SESSION['user'] = [
                'id' => $user['id'],
                'username' => $user['username'],
                'nama_lengkap' => $user['nama_lengkap'],
                'role' => $user['role']
            ];
            header("Location: index.php");
            exit;
        } else {
            $error = 'Username atau password salah!';
        }
    } else {
        $error = 'Harap isi semua kolom!';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Toko Kelontong</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
        }
    </script>
</head>
<body class="bg-gray-100 dark:bg-zinc-950 text-slate-700 dark:text-zinc-200 font-sans min-h-screen flex items-center justify-center p-6 transition-colors duration-200">

    <!-- Tombol Mode Tema -->
    <div class="absolute top-6 right-6 no-print">
        <button onclick="toggleDarkMode()" id="theme-toggle-btn" class="px-4 h-10 rounded-2xl bg-white dark:bg-zinc-900 border border-slate-200 dark:border-zinc-800 flex items-center gap-2 text-xs font-medium shadow-xs transition hover:scale-105 text-slate-700 dark:text-zinc-200">
            <svg id="theme-icon" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            <span id="theme-text">Mode Terang</span>
        </button>
    </div>

    <!-- Kontainer Utama -->
    <div class="w-full max-w-5xl bg-white dark:bg-zinc-900 rounded-3xl shadow-xl overflow-hidden grid grid-cols-1 lg:grid-cols-2 relative border border-slate-200 dark:border-zinc-800">
        
        <!-- Sisi Kiri -->
        <div class="bg-slate-50 dark:bg-zinc-900/50 p-10 flex flex-col justify-between relative overflow-hidden lg:rounded-r-[80px] border-r border-slate-100 dark:border-zinc-800">
            <div class="absolute -bottom-20 -left-20 w-96 h-96 bg-blue-100 dark:bg-blue-950/20 rounded-full mix-blend-multiply filter blur-2xl opacity-70"></div>
            
            <div class="flex items-center gap-3 z-10">
                <div class="w-10 h-10 bg-blue-600 rounded-2xl flex items-center justify-center text-white shadow-md shadow-blue-500/20">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                </div>
                <div>
                    <h1 class="font-extrabold text-slate-800 dark:text-zinc-100 text-sm tracking-wide">TOKO KELONTONG</h1>
                    <span class="text-[10px] text-blue-600 dark:text-blue-400 font-bold uppercase tracking-widest">Sistem Kasir v2.1</span>
                </div>
            </div>

            <div class="my-auto py-10 z-10 flex flex-col items-center text-center">
                <div class="w-48 h-48 bg-white dark:bg-zinc-800 rounded-full flex items-center justify-center shadow-sm border border-slate-200 dark:border-zinc-700 mb-6 relative">
                    <svg class="w-24 h-24 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                </div>
                <h3 class="font-bold text-slate-800 dark:text-zinc-100 text-lg mb-1">Manajemen Toko Lebih Mudah</h3>
                <p class="text-xs text-slate-400 dark:text-zinc-400 max-w-xs">Kelola transaksi penjualan, stok barang, serta laporan omset harian dalam satu platform modern.</p>
            </div>

            <div class="z-10 text-[10px] text-slate-400 dark:text-zinc-500">
                &copy; 2026 Toko Kelontong Indonesia. All rights reserved.
            </div>
        </div>

        <!-- Sisi Kanan: Form & Info Akun & Password -->
        <div class="p-10 lg:p-14 flex flex-col justify-between z-10">
            
            <div>
                <?php if (!empty($error)): ?>
                    <div class="mb-6 p-4 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900 rounded-2xl text-xs text-rose-600 dark:text-rose-400 font-medium">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div class="mb-6 p-4 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-900 rounded-2xl text-xs text-emerald-600 dark:text-emerald-400 font-medium">
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>

                <!-- FORM LOGIN -->
                <div id="form-login-container">
                    <div class="mb-8">
                        <h2 class="text-3xl font-black tracking-tight text-slate-900 dark:text-white mb-2">Login</h2>
                        <p class="text-xs text-slate-500 dark:text-zinc-400">Masukkan kredensial akun kasir atau admin Anda.</p>
                    </div>

                    <form method="POST" class="space-y-5">
                        <input type="hidden" name="action_type" value="login">
                        <div>
                            <label class="block text-[11px] font-bold text-slate-400 dark:text-zinc-400 uppercase tracking-wider mb-2">Username</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400 dark:text-zinc-500">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                </span>
                                <input type="text" name="username" required placeholder="Masukkan username..." class="w-full pl-11 pr-4 py-3 bg-slate-50 dark:bg-zinc-800/60 border border-slate-200 dark:border-zinc-700 rounded-2xl text-xs font-medium focus:outline-none focus:ring-2 focus:ring-blue-500 text-slate-800 dark:text-zinc-100 placeholder-slate-400 dark:placeholder-zinc-500">
                            </div>
                        </div>

                        <div>
                            <label class="block text-[11px] font-bold text-slate-400 dark:text-zinc-400 uppercase tracking-wider mb-2">Password</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400 dark:text-zinc-500">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                </span>
                                <input type="password" name="password" required placeholder="Masukkan password..." class="w-full pl-11 pr-4 py-3 bg-slate-50 dark:bg-zinc-800/60 border border-slate-200 dark:border-zinc-700 rounded-2xl text-xs font-medium focus:outline-none focus:ring-2 focus:ring-blue-500 text-slate-800 dark:text-zinc-100 placeholder-slate-400 dark:placeholder-zinc-500">
                            </div>
                        </div>

                        <div class="flex items-center justify-between text-xs pt-1">
                            <button type="button" onclick="toggleForgotForm()" class="text-blue-600 dark:text-blue-400 hover:underline font-medium">Lupa Password?</button>
                        </div>

                        <button type="submit" class="w-full py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-extrabold rounded-2xl shadow-lg shadow-blue-500/20 transition text-xs tracking-wider uppercase">
                            Masuk ke Sistem
                        </button>
                    </form>
                </div>

                <!-- FORM LUPA PASSWORD -->
                <div id="form-forgot-container" class="hidden">
                    <div class="mb-6">
                        <h2 class="text-2xl font-black tracking-tight text-slate-900 dark:text-white mb-2">Bantuan Sandi</h2>
                        <p class="text-xs text-slate-500 dark:text-zinc-400">Kirim pesan kendala ke email administrator untuk reset password.</p>
                    </div>

                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action_type" value="forgot_password">
                        <div>
                            <label class="block text-[11px] font-bold text-slate-400 dark:text-zinc-400 uppercase tracking-wider mb-1">Nama Lengkap Anda</label>
                            <input type="text" name="nama_pengirim" required placeholder="Contoh: Budi Santoso" class="w-full px-4 py-2.5 bg-slate-50 dark:bg-zinc-800/60 border border-slate-200 dark:border-zinc-700 rounded-xl text-xs font-medium focus:outline-none focus:ring-2 focus:ring-blue-500 text-slate-800 dark:text-zinc-100">
                        </div>

                        <div>
                            <label class="block text-[11px] font-bold text-slate-400 dark:text-zinc-400 uppercase tracking-wider mb-1">Username Akun</label>
                            <input type="text" name="username_kendala" required placeholder="Username yang lupa sandinya..." class="w-full px-4 py-2.5 bg-slate-50 dark:bg-zinc-800/60 border border-slate-200 dark:border-zinc-700 rounded-xl text-xs font-medium focus:outline-none focus:ring-2 focus:ring-blue-500 text-slate-800 dark:text-zinc-100">
                        </div>

                        <div>
                            <label class="block text-[11px] font-bold text-slate-400 dark:text-zinc-400 uppercase tracking-wider mb-1">Pesan / Keterangan</label>
                            <textarea name="pesan_kendala" rows="3" required placeholder="Jelaskan kendala akun Anda..." class="w-full px-4 py-2.5 bg-slate-50 dark:bg-zinc-800/60 border border-slate-200 dark:border-zinc-700 rounded-xl text-xs font-medium focus:outline-none focus:ring-2 focus:ring-blue-500 text-slate-800 dark:text-zinc-100"></textarea>
                        </div>

                        <div class="flex items-center justify-between text-xs pt-1">
                            <button type="button" onclick="toggleForgotForm()" class="text-slate-500 dark:text-zinc-400 hover:underline">Kembali ke Login</button>
                        </div>

                        <button type="submit" class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-extrabold rounded-xl shadow-md transition text-xs tracking-wider uppercase">
                            Kirim Pesan ke Admin
                        </button>
                    </form>
                </div>
            </div>

            <!-- BAGIAN BAWAH: MENAMPILKAN USERNAME & PASSWORD -->
            <div class="mt-8 pt-4 border-t border-slate-100 dark:border-zinc-800">
                <p class="text-[10px] font-bold text-slate-400 dark:text-zinc-500 uppercase tracking-wider mb-2">Informasi Akun Terdaftar (Bantuan Cepat):</p>
                <div class="space-y-1.5 max-h-32 overflow-y-auto pr-1">
                    <?php if (!empty($db_users)): ?>
                        <?php foreach ($db_users as $usr): ?>
                            <div class="flex justify-between items-center px-3 py-2 bg-slate-50 dark:bg-zinc-800/80 border border-slate-200 dark:border-zinc-700 rounded-xl text-[11px]">
                                <div>
                                    <span class="font-bold text-slate-700 dark:text-zinc-200">User: <?= htmlspecialchars($usr['username']) ?></span>
                                    <span class="text-[9px] text-blue-600 dark:text-blue-400 font-bold uppercase ml-1">(<?= htmlspecialchars($usr['role']) ?>)</span>
                                </div>
                                <div class="text-[11px] text-slate-500 dark:text-zinc-400 font-mono bg-white dark:bg-zinc-900 px-2 py-0.5 rounded border border-slate-200 dark:border-zinc-700">
                                    Pass: <span class="text-blue-600 dark:text-blue-400 font-bold">[admin/kasir 123]</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="text-[11px] text-slate-400 italic">Belum ada data user di database.</span>
                    <?php endif; ?>
                </div>
                <p class="text-[9px] text-slate-400 mt-1 italic">*Catatan: Jika password di database dienkripsi MD5, pastikan Anda memasukkan password teks biasa yang Anda tetapkan saat mendaftarkan akun tersebut.</p>
            </div>

        </div>

    </div>

    <script>
        function toggleForgotForm() {
            const loginForm = document.getElementById('form-login-container');
            const forgotForm = document.getElementById('form-forgot-container');
            if (loginForm.classList.contains('hidden')) {
                loginForm.classList.remove('hidden');
                forgotForm.classList.add('hidden');
            } else {
                loginForm.classList.add('hidden');
                forgotForm.classList.remove('hidden');
            }
        }

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
            document.documentElement.classList.remove('dark');
            document.getElementById('theme-text').innerText = 'Mode Terang';
        }
    </script>
</body>
</html>
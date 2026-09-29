<?php
// File: web/index.php (Router Aplikasi)

// 1. Ambil path dari URL asli yang diminta pengguna (misal: "login", "dashboard")
$request_path = trim(strtok($_SERVER['REQUEST_URI'], '?'), '/');

// 2. Muat file konfigurasi utama untuk mengakses fungsi dan koneksi DB
require_once __DIR__ . '/../config/db.php';

// 3. Tentukan file PHP mana yang harus dimuat berdasarkan status aplikasi dan login
$page_to_load = '';

if (!has_admin_user()) {
    // KONDISI 1: Belum ada admin -> Paksa semua ke halaman setup
    $page_to_load = 'setup.php';

} elseif (!current_user()) {
    // KONDISI 2: Sudah ada admin, tapi belum login
    
    // Jika path kosong (root), langsung redirect ke /login
    if ($request_path === '') {
        header('Location: /login');
        exit;
    }
    
    // Izinkan akses hanya ke halaman 'login' dan 'setup'
    if ($request_path === 'login' || $request_path === 'setup') {
        $page_to_load = $request_path . '.php';
    } else {
        // Untuk semua halaman lain, paksa redirect ke /login
        header('Location: /login');
        exit;
    }

} else {
    // KONDISI 3: Sudah login
    if (empty($request_path) || $request_path === 'index.php') {
        // Jika mengakses root domain, langsung arahkan ke dashboard
        header('Location: /dashboard');
        exit;
    }
    // Jika mengakses halaman spesifik (misal: /users), siapkan untuk dimuat
    $page_to_load = $request_path . '.php';
}

// 4. Muat file halaman yang telah ditentukan
$file_path_to_include = __DIR__ . '/' . $page_to_load;

if (file_exists($file_path_to_include)) {
    // Muat file halaman (misal: login.php, dashboard.php)
    include $file_path_to_include;
} else {
    // Jika file tidak ditemukan, tampilkan halaman 404 yang rapi
    http_response_code(404);
    include __DIR__ . '/includes/header.php';
    echo '<div class="page-404">';
    echo '<h1>404 - Halaman Tidak Ditemukan</h1>';
    echo '<p>Maaf, halaman yang Anda tuju tidak ada.</p>';
    echo '<a href="/dashboard" class="btn mt-2">Kembali ke Dashboard</a>';
    echo '</div>';
    include __DIR__ . '/includes/footer.php';
}

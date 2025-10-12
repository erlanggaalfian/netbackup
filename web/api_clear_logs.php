<?php
require_once __DIR__ . '/../config/db.php';
require_login();
require_admin(); // Pastikan hanya admin yang bisa menjalankan aksi ini

// Hanya izinkan metode POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'error' => 'Metode tidak diizinkan.']));
}

// Verifikasi token CSRF
if (!verify_csrf(post('csrf'))) {
    http_response_code(400); 
    exit(json_encode(['success' => false, 'error' => 'Invalid CSRF']));
}

try {
    // Jalankan perintah untuk menghapus semua data dari tabel logs
    $pdo->exec('DELETE FROM logs');
    
    // -- PERUBAHAN DI SINI --
    // Ambil username saat ini dan buat pesan log yang lebih deskriptif
    $username = current_user()['username'] ?? 'Unknown';
    $log_message = 'All logs cleared by user: ' . $username;
    
    // Catat aksi ke dalam log dengan pesan yang baru
    app_log('logs_cleared', $log_message, current_user()['id']); 
    
    // Kirim respons sukses
    exit(json_encode(['success' => true]));

} catch (PDOException $e) {
    // Jika terjadi error di database, kirim respons error
    http_response_code(500);
    // Catat error ke log PHP untuk debugging, jangan tampilkan ke user
    error_log("Gagal menghapus log: " . $e->getMessage()); 
    exit(json_encode(['success' => false, 'error' => 'Terjadi kesalahan pada database saat mencoba menghapus log.']));
}


<?php
require_once __DIR__ . '/../config/db.php';
require_login();
require_admin(); // Pastikan hanya admin yang bisa menjalankan aksi ini

// 1. Hanya izinkan metode POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Metode tidak diizinkan.']));
}

// 2. Verifikasi token CSRF
if (!verify_csrf(post('csrf'))) {
    http_response_code(400); 
    exit(json_encode(['success' => false, 'message' => 'Invalid CSRF Token. Silakan refresh halaman logs.php dan coba lagi.']));
}

try {
    $action = post('action'); 
    $username = current_user()['username'] ?? 'Unknown';
    $user_id = current_user()['id'] ?? 0;
    
    if ($action === 'clear_all') {
        // PERMINTAAN: Kosongkan seluruh isi tabel log
        $pdo->exec('DELETE FROM logs');
        
        $log_message = 'Semua log dikosongkan oleh user: ' . $username;
        app_log('logs_cleared', $log_message, $user_id); 
        
        exit(json_encode(['success' => true]));
        
    } elseif ($action === 'delete_selected') {
        // PERMINTAAN: Hapus log berdasarkan yang dicentang (Select All / Multi Select)
        $ids_string = post('ids');
        
        if (empty($ids_string)) {
            exit(json_encode(['success' => false, 'message' => 'Tidak ada log yang dipilih.']));
        }

        // Ubah string ID menjadi array dan pastikan semuanya adalah angka (integer)
        $ids_array = explode(',', $ids_string);
        $ids_array = array_map('intval', $ids_array);
        $ids_array = array_filter($ids_array, function($id) { return $id > 0; });

        if (count($ids_array) > 0) {
            // Buat placeholder ?,?,? sesuai jumlah ID
            $placeholders = implode(',', array_fill(0, count($ids_array), '?'));
            
            $stmt = $pdo->prepare("DELETE FROM logs WHERE id IN ($placeholders)");
            $stmt->execute($ids_array);
            
            $log_message = count($ids_array) . " log dihapus oleh user: " . $username;
            app_log('log_deleted', $log_message, $user_id); 
            
            exit(json_encode(['success' => true]));
        } else {
            exit(json_encode(['success' => false, 'message' => 'ID log tidak valid.']));
        }
        
    } else {
        exit(json_encode(['success' => false, 'message' => 'Aksi tidak dikenali.']));
    }

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Gagal menghapus log: " . $e->getMessage()); 
    exit(json_encode(['success' => false, 'message' => 'Terjadi kesalahan database: ' . $e->getMessage()]));
}
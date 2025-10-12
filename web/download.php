<?php
require_once __DIR__ . '/../config/db.php';
require_login();

// 1. Ambil ID backup dari URL
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('Error: Backup ID tidak valid.');
}

// 2. Ambil konten konfigurasi dan nama file dari DATABASE
$stmt = $pdo->prepare('SELECT config, filename FROM backups WHERE id = ? AND status = "success"');
$stmt->execute([$id]);
$backup = $stmt->fetch();

// 3. Validasi hasil query
if (!$backup || empty($backup['config'])) {
    http_response_code(404);
    die('Error: Data backup tidak ditemukan di database atau isinya kosong.');
}

// 4. Siapkan header untuk download
$filename = $backup['filename'] ?? "backup_{$id}.txt";
$config_content = $backup['config'];

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
header('Content-Length: ' . strlen($config_content)); // Gunakan strlen untuk mengukur panjang string
header('Pragma: no-cache');
header('Expires: 0');

// 5. Kirim konten langsung dari database ke browser
echo $config_content;
exit;
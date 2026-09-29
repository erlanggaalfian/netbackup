<?php
require_once __DIR__ . '/../config/db.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!verify_csrf(post('csrf'))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$device_id = (int)(post('device_id') ?? 0);
if ($device_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid device ID']);
    exit;
}

try {
    // Ambil detail perangkat
    $stmt = $pdo->prepare('SELECT d.id, d.name FROM devices d WHERE d.id = ?');
    $stmt->execute([$device_id]);
    $device = $stmt->fetch();
    
    if (!$device) {
        echo json_encode(['success' => false, 'error' => 'Device not found']);
        exit;
    }
    
    // --- PERBAIKAN UTAMA ADA DI SINI ---
    // Tentukan path absolut ke Python di dalam virtual environment
    $app_root = dirname(__DIR__);
    $python_executable = escapeshellarg($app_root . '/venv/bin/python3');
    $python_script = escapeshellarg($app_root . '/python/backup_single.py');
    
    // Bangun command untuk dieksekusi
    $command = "{$python_executable} {$python_script} " . (int)$device_id . " 2>&1";
    
    // Jalankan command
    $output = shell_exec($command);
    
    // Parse output JSON dari Python script (mencari JSON di dalam output jika ada logs/warnings tambahan dari stderr)
    $clean_output = trim((string)$output);
    $start_pos = strpos($clean_output, '{');
    $end_pos = strrpos($clean_output, '}');
    
    $result = null;
    if ($start_pos !== false && $end_pos !== false && $end_pos > $start_pos) {
        $json_part = substr($clean_output, $start_pos, $end_pos - $start_pos + 1);
        $result = json_decode($json_part, true);
    }
    
    if (is_array($result) && isset($result['success'])) {
        if ($result['success']) {
            app_log('backup_manual_success', 'Manual backup berhasil untuk ' . $device['name'] . '. File: ' . ($result['filename'] ?? 'N/A'), current_user()['id'], $device_id);
            echo json_encode([
                'success' => true,
                'filename' => $result['filename'] ?? 'N/A',
                'message' => $result['message'] ?? 'Proses selesai.'
            ]);
        } else {
            app_log('backup_manual_failed', 'Manual backup gagal untuk ' . $device['name'] . ': ' . ($result['error'] ?? 'Unknown error'), current_user()['id'], $device_id);
            echo json_encode([
                'success' => false,
                'error' => $result['error'] ?? 'Terjadi kesalahan pada skrip Python.'
            ]);
        }
    } else {
        // Fallback jika output bukan JSON valid — tetap catat ke tabel backups
        $failMsg = 'Skrip Python mengembalikan output tidak valid.';
        if ($output) {
            $failMsg .= ' ' . mb_substr(trim($output), 0, 500);
        }
        record_failed_backup($pdo, $device_id, $failMsg);
        app_log('backup_manual_failed', 'Manual backup gagal (invalid output) untuk ' . $device['name'] . '. Output: ' . $output, current_user()['id'], $device_id);
        echo json_encode([
            'success' => false,
            'error' => 'Skrip Python mengembalikan output tidak valid.',
            'details' => $output
        ]);
    }
    
} catch (Exception $e) {
    app_log('backup_manual_error', 'PHP Error saat backup manual: ' . $e->getMessage(), current_user()['id'], $device_id);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

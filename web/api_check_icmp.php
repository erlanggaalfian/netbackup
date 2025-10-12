<?php
require_once __DIR__ . '/../config/db.php';
require_login();

header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid device ID']);
    exit;
}

try {
    // Ambil IP perangkat
    $stmt = $pdo->prepare('SELECT ip FROM devices WHERE id = ?');
    $stmt->execute([$id]);
    $device = $stmt->fetch();
    
    if (!$device) {
        echo json_encode(['success' => false, 'error' => 'Device not found']);
        exit;
    }
    
    $ip = $device['ip'];
    
    // Cek ICMP ping
    $icmp_status = ping_host($ip);
    
    echo json_encode([
        'success' => true,
        'icmp_status' => $icmp_status,
        'ip' => $ip
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

<?php
// File ini berfungsi sebagai endpoint API untuk mengecek status koneksi satu perangkat.
require_once __DIR__ . '/../config/db.php';
require_login();

// Set header sebagai JSON
header('Content-Type: application/json');

// Ambil ID perangkat dari URL
$device_id = (int)(get('id') ?? 0);

if ($device_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Device ID tidak valid']);
    exit;
}

// Ambil detail perangkat dari database
$stmt = $pdo->prepare("SELECT ip, port, protocol FROM devices WHERE id = ?");
$stmt->execute([$device_id]);
$device = $stmt->fetch();

if (!$device) {
    http_response_code(404);
    echo json_encode(['error' => 'Perangkat tidak ditemukan']);
    exit;
}

// Lakukan pengecekan koneksi TCP
$tcp_status = check_device_connectivity($device['ip'], (int)$device['port'], 1.5); // Timeout lebih singkat untuk API

// Kembalikan hasilnya dalam format JSON
echo json_encode([
    'success' => true,
    'tcp_status' => $tcp_status,
    'protocol' => $device['protocol']
]);
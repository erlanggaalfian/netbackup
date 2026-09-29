<?php
/**
 * API agregat konektivitas dashboard — baca cache (cepat); refresh=1 untuk cek ulang.
 */
require_once __DIR__ . '/../config/db.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $force = (get('refresh') === '1');
    if ($force) {
        set_time_limit(120);
    }

    $data = get_device_connectivity($pdo, $force);
    $failed = connectivity_failed_device_lists($data['devices'] ?? [], $pdo);

    echo json_encode([
        'success' => true,
        'cached' => !$force,
        'stale' => !empty($data['stale']),
        'interval_sec' => $data['interval_sec'] ?? DEVICE_CONNECTIVITY_TTL,
        'checked_at' => $data['checked_at'] ?? null,
        'total' => (int)($data['total'] ?? 0),
        'ping' => $data['ping'] ?? ['ok' => 0, 'failed' => 0, 'unknown' => 0],
        'interconnect' => $data['interconnect'] ?? ['ok' => 0, 'failed' => 0],
        'failed' => $failed,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Gagal memeriksa konektivitas perangkat',
    ]);
}

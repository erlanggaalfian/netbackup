<?php
/**
 * API status perangkat — baca cache (cepat).
 * GET ?ids=1,2,3        → cache saja
 * GET ?refresh=1&id=5   → cek ulang satu perangkat
 */
require_once __DIR__ . '/../config/db.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$forceRefresh = (get('refresh') === '1');
$singleId = (int)(get('id') ?? 0);
$idsParam = trim(get('ids') ?? '');

if ($forceRefresh) {
    set_time_limit(120);
}

try {
    if ($singleId > 0) {
        $data = get_device_connectivity($pdo, $forceRefresh, [$singleId]);
        $key = (string)$singleId;
        echo json_encode([
            'success' => true,
            'checked_at' => $data['checked_at'] ?? null,
            'stale' => !empty($data['stale']),
            'device' => $data['devices'][$key] ?? null,
        ]);
        exit;
    }

    $onlyIds = null;
    if ($idsParam !== '') {
        $onlyIds = array_values(array_filter(array_map('intval', explode(',', $idsParam))));
    }

    $data = get_device_connectivity($pdo, false, $onlyIds);
    echo json_encode([
        'success' => true,
        'checked_at' => $data['checked_at'] ?? null,
        'stale' => !empty($data['stale']),
        'interval_sec' => $data['interval_sec'] ?? DEVICE_CONNECTIVITY_TTL,
        'devices' => $data['devices'] ?? [],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Gagal memuat status perangkat']);
}

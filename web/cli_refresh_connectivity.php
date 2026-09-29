<?php
/**
 * CLI: refresh cache konektivitas perangkat (cron / background).
 * Usage: php web/cli_refresh_connectivity.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/db.php';

$lockPath = APP_ROOT . '/cache/.connectivity_refresh.lock';
$lockDir = dirname($lockPath);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}

$lock = @fopen($lockPath, 'c+');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

try {
    refresh_device_connectivity_cache($pdo);
} catch (Throwable $e) {
    fwrite(STDERR, 'connectivity refresh failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

exit(0);

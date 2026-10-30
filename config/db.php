<?php
/**
 * File: config/db.php
 * Deskripsi: Berkas konfigurasi koneksi database PDO MySQL/MariaDB serta helper functions (autentikasi, enkripsi, verifikasi session).
 */
declare(strict_types=1);

/**
 * Memeriksa apakah sudah ada pengguna dengan peran 'admin' di database.
 * @return bool True jika ada, false jika tidak ada.
 */
function has_admin_user(): bool {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT 1 FROM users WHERE role = 'admin' LIMIT 1");
        // fetchColumn() akan mengembalikan nilai (1) jika baris ditemukan, atau false jika tidak.
        return $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        // Jika terjadi error (misal: tabel belum ada), anggap belum ada admin.
        return false;
    }
}

// Selalu mulai session di baris paling atas
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// --- "Ingat saya": login persistent per perangkat ---
// Cookie menyimpan token acak; di DB hanya hash SHA-256-nya (bocor DB != bisa dipakai).
// Token dirotasi setiap dipakai dan dicabut saat logout.
const REMEMBER_COOKIE = 'nb_remember';
const REMEMBER_DAYS = 30;

function remember_cookie_params(): array {
    return [
        'expires' => time() + REMEMBER_DAYS * 86400,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/** Buat token persistent setelah login sukses dengan "Ingat saya" dicentang. */
function remember_login(PDO $pdo, int $userId): void {
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + REMEMBER_DAYS * 86400);
    $stmt = $pdo->prepare('INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
    $stmt->execute([$userId, hash('sha256', $token), $expires]);
    setcookie(REMEMBER_COOKIE, $token, remember_cookie_params());
    $_COOKIE[REMEMBER_COOKIE] = $token;
}

/**
 * Coba pulihkan sesi dari cookie "Ingat saya".
 * Dipanggil di setiap request sebelum halaman mengecek login.
 * Return true jika sesi berhasil dipulihkan.
 */
function try_remember_login(PDO $pdo): bool {
    $token = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (!is_string($token) || !preg_match('/^[0-9a-f]{64}$/', $token)) {
        return false;
    }
    try {
        $hash = hash('sha256', $token);
        $stmt = $pdo->prepare('SELECT rt.user_id, rt.expires_at, u.username, u.role
                               FROM remember_tokens rt JOIN users u ON u.id = rt.user_id
                               WHERE rt.token_hash = ? LIMIT 1');
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        // Bersihkan token basi/tidak dikenal + cookie-nya.
        $clear = function () use ($pdo, $hash) {
            try { $pdo->prepare('DELETE FROM remember_tokens WHERE token_hash = ?')->execute([$hash]); } catch (Throwable $e) {}
            setcookie(REMEMBER_COOKIE, '', time() - 3600, '/');
            unset($_COOKIE[REMEMBER_COOKIE]);
        };
        if (!$row || strtotime((string)$row['expires_at']) < time()) {
            $clear();
            return false;
        }
        // Rotasi: token sekali pakai — hapus yang lama, terbitkan yang baru.
        $clear();
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int)$row['user_id'], 'username' => $row['username'], 'role' => $row['role']];
        remember_login($pdo, (int)$row['user_id']);
        // Bersihkan token kedaluwarsa lain sesekali.
        try { $pdo->exec("DELETE FROM remember_tokens WHERE expires_at < NOW()"); } catch (Throwable $e) {}
        app_log('login_remember', 'Auto-login via Ingat saya: ' . $row['username'], (int)$row['user_id']);
        return true;
    } catch (Throwable $e) {
        // Tabel belum ada (fresh install) atau DB error: abaikan, user login manual.
        return false;
    }
}

/** Cabut semua token persistent milik user + hapus cookie-nya (dipakai saat logout). */
function forget_remember_login(PDO $pdo, ?int $userId): void {
    try {
        if ($userId) {
            $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$userId]);
        }
    } catch (Throwable $e) { /* abaikan */ }
    setcookie(REMEMBER_COOKIE, '', time() - 3600, '/');
    unset($_COOKIE[REMEMBER_COOKIE]);
}

// Aktifkan error reporting untuk development agar mudah di-debug
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Definisi Path
define('APP_ROOT', dirname(__DIR__));

function app_version(): string {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $path = APP_ROOT . '/VERSION';
    if (is_file($path)) {
        $raw = trim((string)file_get_contents($path));
        if ($raw !== '') {
            $cached = $raw;
            return $cached;
        }
    }
    $cached = '2.8.1';
    return $cached;
}

$INI_PATH = APP_ROOT . '/config/db_setting.ini';

// Koneksi Database
if (!file_exists($INI_PATH)) {
    http_response_code(500); die('FATAL ERROR: config/db_setting.ini tidak ditemukan.');
}
$ini = parse_ini_file($INI_PATH, true);
if (!$ini || !isset($ini['database'])) {
    http_response_code(500); die('FATAL ERROR: Gagal membaca db_setting.ini atau section [database] hilang.');
}
$db_config = $ini['database'];
$encKey = $db_config['ENCRYPTION_KEY'] ?? '';

try {
    $dsn = "mysql:host={$db_config['DB_HOST']};port={$db_config['DB_PORT']};dbname={$db_config['DB_NAME']};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_config['DB_USER'], $db_config['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    error_log('Koneksi database gagal: ' . $e->getMessage());
    http_response_code(500);
    die('Error: Tidak dapat terhubung ke server database.');
}

// "Ingat saya": pulihkan sesi dari cookie persistent sebelum halaman mengecek login.
if (empty($_SESSION['user']) && isset($_COOKIE[REMEMBER_COOKIE])) {
    try_remember_login($pdo);
}

// --- Fungsi Helper ---

function post(string $key): ?string {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : null;
}

function get(string $key): ?string {
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : null;
}

function csrf_token(): string {
    // Double-submit cookie: token di session + cookie, validasi dari cookie.
    // Cookie tidak di-cache browser, jadi stale HTML tetap bisa submit.
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    // Sync cookie setiap kali token di-generate (GET request)
    $token = $_SESSION['csrf'];
    if (!isset($_COOKIE['csrf_token']) || !hash_equals($_COOKIE['csrf_token'], $token)) {
        setcookie('csrf_token', $token, [
            'expires'  => 0,
            'path'     => '/',
            'httponly'  => false,  // JS perlu baca untuk AJAX
            'samesite' => 'Lax',
        ]);
    }
    return $token;
}

function verify_csrf(?string $token): bool {
    if (empty($token)) return false;
    // Validasi dari cookie (selalu fresh, tidak di-cache browser)
    if (isset($_COOKIE['csrf_token']) && hash_equals($_COOKIE['csrf_token'], $token)) {
        return true;
    }
    // Fallback: validasi dari session (untuk kasus cookie diblock browser)
    if (isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token)) {
        return true;
    }
    return false;
}

/**
 * Menentukan alasan redirect ke halaman login + self-heal cookie sesi yang basi.
 *
 * 'expired' = browser mengirim session id tapi server tidak punya datanya
 *             (cookie basi/rusak di perangkat itu). Cookie-nya dihapus otomatis
 *             agar percobaan login berikutnya mulai bersih — user tidak perlu
 *             hapus cookie manual per perangkat.
 * 'login'   = belum pernah login / browser tidak mengirim cookie sesi.
 */
function login_redirect_reason(): string {
    if (isset($_COOKIE[session_name()])) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params['path'] ?? '/');
        unset($_COOKIE[session_name()]);
        return 'expired';
    }
    return 'login';
}

function require_login(): void {
    if (!empty($_SESSION['user'])) {
        return;
    }
    header('Location: login.php?reason=' . login_redirect_reason());
    exit;
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_admin(): void {
    $user = current_user();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        die('Akses ditolak. Anda harus menjadi admin untuk melakukan aksi ini.');
    }
}

function app_log(string $type, string $message, ?int $actorId = null, ?int $targetId = null): void {
    global $pdo;
    try {
        $stmt = $pdo->prepare('INSERT INTO logs(type, actor, target_id, timestamp, details) VALUES(?, ?, ?, NOW(), ?)');
        $stmt->execute([$type, $actorId, $targetId, $message]);
    } catch (Throwable $e) { /* Abaikan jika pencatatan log gagal */ }
}

function encrypt_secret(string $plaintext): string {
    global $encKey;
    $key = hash('sha256', $encKey, true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $cipher);
}

function decrypt_secret(string $encoded): string {
    global $encKey;
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 28) return '';
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $key = hash('sha256', $encKey, true);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? '' : $plain;
}

function check_device_connectivity(string $host, int $port, float $timeout = 1.0): bool {
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if ($socket) {
        fclose($socket);
        return true;
    }
    return false;
}

function ping_host_command(string $ip, int $waitSec): string {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        return sprintf('ping -n 1 -w %d %s', max(500, $waitSec * 1000), escapeshellarg($ip));
    }
    return sprintf('ping -c 1 -W %d %s', max(1, $waitSec), escapeshellarg($ip));
}

function ping_host(string $ip): ?bool {
    exec(ping_host_command($ip, 1) . ' 2>/dev/null', $output, $return_code);
    return $return_code === 0;
}

/**
 * Ping banyak host secara paralel (proc_open).
 * @param array<string, string> $targets map key => ip
 * @return array<string, ?bool>
 */
function ping_hosts_parallel(array $targets, float $timeoutSec = 1.0): array {
    if ($targets === []) {
        return [];
    }
    $results = [];
    $processes = [];
    $waitSec = max(1, (int)ceil($timeoutSec));

    foreach ($targets as $key => $ip) {
        $cmd = ping_host_command($ip, $waitSec);
        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (is_resource($proc)) {
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            $processes[] = ['key' => (string)$key, 'proc' => $proc];
        } else {
            $results[(string)$key] = null;
        }
    }

    $deadline = microtime(true) + $timeoutSec + 1.0;
    while ($processes !== [] && microtime(true) < $deadline) {
        foreach ($processes as $idx => $item) {
            $status = proc_get_status($item['proc']);
            if (!$status['running']) {
                $results[$item['key']] = ((int)$status['exitcode'] === 0);
                proc_close($item['proc']);
                unset($processes[$idx]);
            }
        }
        if ($processes !== []) {
            usleep(40000);
        }
    }

    foreach ($processes as $item) {
        $results[$item['key']] = false;
        proc_close($item['proc']);
    }

    return $results;
}

/**
 * Cek koneksi TCP banyak host secara paralel.
 * @param array<string, array{ip: string, port: int}> $targets
 * @return array<string, bool>
 */
function check_devices_tcp_parallel(array $targets, float $timeoutSec = 1.0): array {
    if ($targets === []) {
        return [];
    }

    $results = [];
    $pending = [];

    foreach ($targets as $key => $target) {
        $ip = $target['ip'];
        $port = (int)$target['port'];
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'tcp://' . $ip . ':' . $port,
            $errno,
            $errstr,
            0,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
        );
        if ($socket) {
            stream_set_blocking($socket, false);
            $pending[(int)$socket] = ['key' => (string)$key, 'socket' => $socket, 'start' => microtime(true)];
        } else {
            $results[(string)$key] = false;
        }
    }

    $deadline = microtime(true) + $timeoutSec;
    while ($pending !== [] && microtime(true) < $deadline) {
        $write = [];
        $except = [];
        foreach ($pending as $info) {
            $write[] = $info['socket'];
            $except[] = $info['socket'];
        }
        $remaining = max(0.0, $deadline - microtime(true));
        $sec = (int)$remaining;
        $usec = (int)(($remaining - $sec) * 1_000_000);
        $read = [];
        $selected = @stream_select($read, $write, $except, $sec, $usec);
        if ($selected === false) {
            break;
        }
        foreach ($write as $sock) {
            $fd = (int)$sock;
            if (!isset($pending[$fd])) {
                continue;
            }
            $results[$pending[$fd]['key']] = true;
            fclose($pending[$fd]['socket']);
            unset($pending[$fd]);
        }
        $now = microtime(true);
        foreach ($pending as $fd => $info) {
            if (($now - $info['start']) >= $timeoutSec) {
                $results[$info['key']] = false;
                fclose($info['socket']);
                unset($pending[$fd]);
            }
        }
    }

    foreach ($pending as $info) {
        $results[$info['key']] = false;
        fclose($info['socket']);
    }

    return $results;
}

/** TTL cache hasil cek konektivitas (detik) — diperbarui oleh cron CLI */
const DEVICE_CONNECTIVITY_TTL = 180;

function device_connectivity_cache_path(): string {
    $dir = APP_ROOT . '/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir . '/device_connectivity.json';
}

function load_device_connectivity_cache(bool $ignoreTtl = false): ?array {
    $path = device_connectivity_cache_path();
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['checked_at'])) {
        return null;
    }
    if (!$ignoreTtl && (time() - (int)($data['ts'] ?? 0)) >= DEVICE_CONNECTIVITY_TTL) {
        return null;
    }
    return $data;
}

function save_device_connectivity_cache(array $data): void {
    $data['ts'] = time();
    if (empty($data['checked_at'])) {
        $data['checked_at'] = date('c');
    }
    @file_put_contents(device_connectivity_cache_path(), json_encode($data), LOCK_EX);
}

function icmp_state(?bool $icmp): string {
    if ($icmp === true) {
        return 'up';
    }
    if ($icmp === false) {
        return 'down';
    }
    return 'unknown';
}

function recompute_connectivity_summary(array $devicesMap): array {
    $ping = ['ok' => 0, 'failed' => 0, 'unknown' => 0];
    $interconnect = ['ok' => 0, 'failed' => 0];
    foreach ($devicesMap as $row) {
        $icmp = $row['icmp'] ?? 'unknown';
        if ($icmp === 'up') {
            $ping['ok']++;
        } elseif ($icmp === 'down') {
            $ping['failed']++;
        } else {
            $ping['unknown']++;
        }
        if (($row['interconnect'] ?? 'down') === 'up') {
            $interconnect['ok']++;
        } else {
            $interconnect['failed']++;
        }
    }
    return [
        'total' => count($devicesMap),
        'ping' => $ping,
        'interconnect' => $interconnect,
    ];
}

/**
 * Jalankan cek ping + TCP paralel untuk satu atau semua perangkat (di server).
 */
function run_device_connectivity_checks(PDO $pdo, ?array $onlyIds = null, float $tcpTimeout = 1.0): array {
    $sql = 'SELECT id, name, ip, port, protocol FROM devices';
    $params = [];
    if ($onlyIds !== null && $onlyIds !== []) {
        $onlyIds = array_values(array_unique(array_map('intval', $onlyIds)));
        $placeholders = implode(',', array_fill(0, count($onlyIds), '?'));
        $sql .= " WHERE id IN ($placeholders)";
        $params = $onlyIds;
    }
    $sql .= ' ORDER BY id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if ($rows === []) {
        return [];
    }

    $pingTargets = [];
    $tcpTargets = [];
    $meta = [];
    foreach ($rows as $device) {
        $id = (int)$device['id'];
        $key = (string)$id;
        $ip = (string)$device['ip'];
        $pingTargets[$key] = $ip;
        $tcpTargets[$key] = ['ip' => $ip, 'port' => (int)$device['port']];
        $protocol = (string)$device['protocol'];
        $meta[$key] = [
            'id' => $id,
            'name' => (string)$device['name'],
            'ip' => $ip,
            'port' => (int)$device['port'],
            'protocol' => $protocol,
        ];
    }

    $pingResults = ping_hosts_parallel($pingTargets, 1.0);
    $tcpResults = check_devices_tcp_parallel($tcpTargets, $tcpTimeout);

    $onlineStmt = $pdo->prepare('UPDATE devices SET status = ?, last_seen = NOW() WHERE id = ?');
    $offlineStmt = $pdo->prepare('UPDATE devices SET status = ? WHERE id = ?');

    $checked = [];
    foreach ($meta as $key => $device) {
        $id = $device['id'];
        $icmp = icmp_state($pingResults[$key] ?? null);
        $tcpOk = (bool)($tcpResults[$key] ?? false);
        $interconnect = $tcpOk ? 'up' : 'down';

        try {
            if ($tcpOk) {
                $onlineStmt->execute(['Online', $id]);
            } else {
                $offlineStmt->execute(['Offline', $id]);
            }
        } catch (Throwable $e) {
            // abaikan
        }

        $checked[$key] = [
            'id' => $id,
            'name' => $device['name'],
            'ip' => $device['ip'],
            'port' => $device['port'],
            'icmp' => $icmp,
            'interconnect' => $interconnect,
            'protocol' => $device['protocol'],
        ];
    }

    return $checked;
}

/**
 * Tulis ulang cache konektivitas (cron CLI / refresh manual).
 */
function refresh_device_connectivity_cache(PDO $pdo, ?array $onlyIds = null): array {
    $existing = load_device_connectivity_cache(true);
    $devicesMap = is_array($existing['devices'] ?? null) ? $existing['devices'] : [];

    $checked = run_device_connectivity_checks($pdo, $onlyIds);
    foreach ($checked as $idKey => $row) {
        $devicesMap[$idKey] = $row;
    }

    if ($onlyIds === null) {
        $allIds = $pdo->query('SELECT id FROM devices ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        
        // Bersihkan perangkat yang sudah tidak ada di database (mencegah ghost device)
        $cleanedMap = [];
        foreach ($allIds as $rowId) {
            $key = (string)(int)$rowId;
            if (isset($devicesMap[$key])) {
                $cleanedMap[$key] = $devicesMap[$key];
            }
        }
        $devicesMap = $cleanedMap;

        $missingIds = [];
        foreach ($allIds as $rowId) {
            $key = (string)(int)$rowId;
            if (!isset($devicesMap[$key])) {
                $missingIds[] = (int)$rowId;
            }
        }
        if ($missingIds !== []) {
            foreach (run_device_connectivity_checks($pdo, $missingIds) as $idKey => $row) {
                $devicesMap[$idKey] = $row;
            }
        }
    }

    $summary = recompute_connectivity_summary($devicesMap);
    $payload = [
        'checked_at' => date('c'),
        'interval_sec' => DEVICE_CONNECTIVITY_TTL,
        'devices' => $devicesMap,
    ] + $summary;

    save_device_connectivity_cache($payload);
    return $payload;
}

/** Picu refresh cache di background — maks. sekali per 2 menit (hindari spam proses). */
function trigger_connectivity_background_refresh(): void {
    static $triggered = false;
    if ($triggered) {
        return;
    }
    $triggered = true;

    $cooldownPath = APP_ROOT . '/cache/.connectivity_web_trigger';
    if (is_file($cooldownPath) && (time() - (int)@filemtime($cooldownPath)) < 120) {
        return;
    }
    @touch($cooldownPath);

    $cli = APP_ROOT . '/web/cli_refresh_connectivity.php';
    if (!is_file($cli)) {
        return;
    }

    $php = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        @pclose(@popen('start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($cli), 'r'));
        return;
    }
    @exec(escapeshellarg($php) . ' ' . escapeshellarg($cli) . ' > /dev/null 2>&1 &');
}

/**
 * Baca cache konektivitas tanpa ping/TCP — responsif saat first load dashboard.
 */
function get_device_connectivity_snapshot(bool $allowStale = true, ?PDO $pdo = null): ?array {
    if ($pdo === null) {
        global $pdo;
    }
    $cached = load_device_connectivity_cache(false);
    $stale = false;
    if ($cached === null && $allowStale) {
        $cached = load_device_connectivity_cache(true);
        $stale = $cached !== null;
    }
    if ($cached === null) {
        return null;
    }
    $devicesMap = is_array($cached['devices'] ?? null) ? $cached['devices'] : [];
    if ($devicesMap === []) {
        return null;
    }

    // Pembersihan dinamis untuk perangkat yang sudah dihapus dari database
    if ($pdo instanceof PDO) {
        try {
            $allIds = $pdo->query('SELECT id FROM devices')->fetchAll(PDO::FETCH_COLUMN);
            $allIdsMap = array_flip(array_map('strval', $allIds));
            $pruned = false;
            foreach ($devicesMap as $key => $row) {
                if (!isset($allIdsMap[(string)$key])) {
                    unset($devicesMap[$key]);
                    $pruned = true;
                }
            }
            if ($pruned) {
                $summary = recompute_connectivity_summary($devicesMap);
                $cached['devices'] = $devicesMap;
                $cached = array_merge($cached, $summary);
                save_device_connectivity_cache($cached);
            }
        } catch (Throwable $e) {
            // abaikan
        }
    }

    $summary = recompute_connectivity_summary($devicesMap);
    return [
        'checked_at' => $cached['checked_at'],
        'interval_sec' => DEVICE_CONNECTIVITY_TTL,
        'stale' => $stale,
        'devices' => $devicesMap,
    ] + $summary;
}

/**
 * Ambil snapshot konektivitas — baca cache saja (cepat). Cek jaringan hanya saat $forceRefresh.
 */
function get_device_connectivity(PDO $pdo, bool $forceRefresh = false, ?array $onlyIds = null): array {
    if ($forceRefresh) {
        $checkIds = ($onlyIds !== null && $onlyIds !== []) ? $onlyIds : null;
        $data = refresh_device_connectivity_cache($pdo, $checkIds);
        if ($onlyIds !== null && $onlyIds !== []) {
            $filtered = [];
            foreach ($onlyIds as $id) {
                $key = (string)(int)$id;
                if (isset($data['devices'][$key])) {
                    $filtered[$key] = $data['devices'][$key];
                }
            }
            return [
                'checked_at' => $data['checked_at'],
                'interval_sec' => $data['interval_sec'],
                'stale' => false,
                'devices' => $filtered,
            ] + recompute_connectivity_summary($filtered);
        }
        return $data + ['stale' => false];
    }

    $cachedFresh = load_device_connectivity_cache(false);
    $cachedAny = load_device_connectivity_cache(true);
    $cached = $cachedFresh ?? $cachedAny;
    $stale = ($cachedFresh === null && $cachedAny !== null);

    if ($cached === null) {
        $empty = [
            'checked_at' => null,
            'interval_sec' => DEVICE_CONNECTIVITY_TTL,
            'stale' => false,
            'devices' => [],
            'total' => 0,
            'ping' => ['ok' => 0, 'failed' => 0, 'unknown' => 0],
            'interconnect' => ['ok' => 0, 'failed' => 0],
        ];
        return $empty;
    }

    $devicesMap = is_array($cached['devices'] ?? null) ? $cached['devices'] : [];

    if ($onlyIds !== null && $onlyIds !== []) {
        $filtered = [];
        foreach ($onlyIds as $id) {
            $key = (string)(int)$id;
            if (isset($devicesMap[$key])) {
                $filtered[$key] = $devicesMap[$key];
            }
        }
        $summary = recompute_connectivity_summary($filtered);
        return [
            'checked_at' => $cached['checked_at'],
            'interval_sec' => DEVICE_CONNECTIVITY_TTL,
            'stale' => $stale,
            'devices' => $filtered,
        ] + $summary;
    }

    $summary = recompute_connectivity_summary($devicesMap);
    return [
        'checked_at' => $cached['checked_at'],
        'interval_sec' => DEVICE_CONNECTIVITY_TTL,
        'stale' => $stale,
        'devices' => $devicesMap,
    ] + $summary;
}

/** Payload JSON konektivitas dashboard dari snapshot cache. */
function build_dashboard_connectivity_payload(?array $snapshot, ?PDO $pdo = null): array {
    if ($snapshot === null) {
        return [
            'success' => true,
            'checked_at' => null,
            'stale' => false,
            'interval_sec' => DEVICE_CONNECTIVITY_TTL,
            'total' => 0,
            'ping' => ['ok' => 0, 'failed' => 0, 'unknown' => 0],
            'interconnect' => ['ok' => 0, 'failed' => 0],
            'failed' => ['ping' => [], 'interconnect' => []],
        ];
    }

    $devicesMap = is_array($snapshot['devices'] ?? null) ? $snapshot['devices'] : [];

    return [
        'success' => true,
        'checked_at' => $snapshot['checked_at'] ?? null,
        'stale' => !empty($snapshot['stale']),
        'interval_sec' => $snapshot['interval_sec'] ?? DEVICE_CONNECTIVITY_TTL,
        'total' => (int)($snapshot['total'] ?? count($devicesMap)),
        'ping' => $snapshot['ping'] ?? ['ok' => 0, 'failed' => 0, 'unknown' => 0],
        'interconnect' => $snapshot['interconnect'] ?? ['ok' => 0, 'failed' => 0],
        'failed' => connectivity_failed_device_lists($devicesMap, $pdo),
    ];
}

/** Daftar perangkat yang gagal ping / interkoneksi (tanpa query DB bila metadata ada di cache). */
function connectivity_failed_device_lists(array $devicesMap, ?PDO $pdo = null): array {
    if ($devicesMap === []) {
        return ['ping' => [], 'interconnect' => []];
    }

    $meta = [];
    $missingIds = [];
    foreach ($devicesMap as $idKey => $row) {
        if (!empty($row['name'])) {
            $meta[$idKey] = $row;
        } else {
            $missingIds[] = (int)$idKey;
        }
    }

    if ($missingIds !== [] && $pdo !== null) {
        $missingIds = array_values(array_unique($missingIds));
        $placeholders = implode(',', array_fill(0, count($missingIds), '?'));
        $stmt = $pdo->prepare("SELECT id, name, ip, port, protocol FROM devices WHERE id IN ($placeholders)");
        $stmt->execute($missingIds);
        foreach ($stmt->fetchAll() as $row) {
            $meta[(string)(int)$row['id']] = $row;
        }
    }

    $pingFailed = [];
    $icFailed = [];
    foreach ($devicesMap as $idKey => $row) {
        $m = $meta[$idKey] ?? null;
        $protocol = (string)($row['protocol'] ?? ($m['protocol'] ?? 'SSH'));
        $port = (int)($row['port'] ?? ($m['port'] ?? ($protocol === 'Telnet' ? 23 : 22)));
        $name = (string)($row['name'] ?? ($m['name'] ?? ('Perangkat #' . $idKey)));

        if (($row['icmp'] ?? 'unknown') === 'down') {
            $pingFailed[] = [
                'id' => (int)$idKey,
                'name' => $name,
            ];
        }
        if (($row['interconnect'] ?? 'down') !== 'up') {
            $icFailed[] = [
                'id' => (int)$idKey,
                'name' => $name,
                'protocol' => $protocol,
                'port' => $port,
            ];
        }
    }

    $sortByName = static function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    };
    usort($pingFailed, $sortByName);
    usort($icFailed, $sortByName);

    return ['ping' => $pingFailed, 'interconnect' => $icFailed];
}

/** @deprecated alias — dashboard memakai get_device_connectivity() */
function check_devices_connectivity_summary(PDO $pdo, float $tcpTimeout = 1.5): array {
    unset($tcpTimeout);
    $data = get_device_connectivity($pdo, false);
    return [
        'checked_at' => $data['checked_at'],
        'total' => $data['total'],
        'ping' => $data['ping'],
        'interconnect' => $data['interconnect'],
    ];
}

function device_icmp_badge_html(string $state): string {
    if ($state === 'up') {
        return '<span class="badge success">ICMP: OK</span>';
    }
    if ($state === 'down') {
        return '<span class="badge danger">ICMP: Mati</span>';
    }
    if ($state === 'loading') {
        return '<span class="badge gray">ICMP: …</span>';
    }
    return '<span class="badge gray">ICMP: —</span>';
}

function device_interconnect_badge_html(string $state, string $protocol = 'SSH'): string {
    $label = htmlspecialchars($protocol, ENT_QUOTES, 'UTF-8');
    if ($state === 'up') {
        return '<span class="badge success">' . $label . ': OK</span>';
    }
    if ($state === 'down') {
        return '<span class="badge danger">' . $label . ': Gagal</span>';
    }
    if ($state === 'loading') {
        return '<span class="badge gray">' . $label . ': …</span>';
    }
    return '<span class="badge gray">' . $label . ': —</span>';
}

/** Opsi select: 25 baris, 50 baris, … */
function limit_select_options(array $limits, int $selected): string {
    $html = '';
    foreach ($limits as $limit) {
        $limit = (int)$limit;
        $sel = ($selected === $limit) ? ' selected' : '';
        $html .= '<option value="' . $limit . '"' . $sel . '>' . $limit . ' baris</option>';
    }
    return $html;
}

/** Tombol atur ulang filter */
function filter_reset_link(string $href): string {
    return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="btn btn-sm secondary">Atur Ulang</a>';
}

/** Apakah status backup termasuk gagal (termasuk nilai legacy). */
function is_backup_failed(?string $status): bool {
    if ($status === null || $status === '') {
        return false;
    }
    $normalized = strtolower(trim($status));
    return in_array($normalized, ['failed', 'failure', 'error', 'gagal'], true);
}

/** SQL kondisi baris backup gagal. */
function backup_failed_sql(string $column = 'status'): string {
    return "($column IS NOT NULL AND $column <> 'success')";
}

/** Catat baris backup gagal ke tabel backups. */
function record_failed_backup(PDO $pdo, int $deviceId, string $message): void {
    if ($deviceId <= 0) {
        return;
    }
    try {
        $failedStatus = 'failed';
        $stmtCol = $pdo->query("SHOW COLUMNS FROM backups LIKE 'status'");
        $col = $stmtCol->fetch(PDO::FETCH_ASSOC);
        if ($col && isset($col['Type'])) {
            preg_match_all("/'(.*?)'/", $col['Type'], $matches);
            if (!empty($matches[1])) {
                $enum_values = $matches[1];
                foreach (['failed', 'failure', 'gagal', 'error'] as $val) {
                    if (in_array($val, $enum_values, true)) {
                        $failedStatus = $val;
                        break;
                    }
                }
                if ($failedStatus === 'failed') {
                    foreach ($enum_values as $val) {
                        if (strtolower($val) !== 'success') {
                            $failedStatus = $val;
                            break;
                        }
                    }
                }
            }
        }
        $stmt = $pdo->prepare('INSERT INTO backups (device_id, config, status, filename) VALUES (?, ?, ?, ?)');
        $stmt->execute([$deviceId, mb_substr($message, 0, 65000), $failedStatus, 'N/A']);
    } catch (Throwable $e) {
        // abaikan — dashboard/log tetap jalan
    }
}

/** TTL cache statistik dashboard (detik). */
const DASHBOARD_STATS_TTL = 90;
/** Maks. baris feed kegagalan backup di dashboard. */
const DASHBOARD_FAILURE_FEED_LIMIT = 20;

function dashboard_stats_cache_path(): string {
    $dir = APP_ROOT . '/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir . '/dashboard_stats.json';
}

function load_dashboard_stats_cache(): ?array {
    $path = dashboard_stats_cache_path();
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['ts'])) {
        return null;
    }
    if ((time() - (int)$data['ts']) >= DASHBOARD_STATS_TTL) {
        return null;
    }
    return $data;
}

/** Statistik backup dashboard — cache file agar tidak scan tabel backups tiap request. */
function fetch_dashboard_stats(PDO $pdo): array {
    $cached = load_dashboard_stats_cache();
    if ($cached !== null) {
        return $cached;
    }

    $failedCond = backup_failed_sql('status');
    $backup_metrics = $pdo->query("
        SELECT
            SUM(status = 'success' AND timestamp >= CURDATE() AND timestamp < CURDATE() + INTERVAL 1 DAY) AS today_s,
            SUM($failedCond AND timestamp >= CURDATE() AND timestamp < CURDATE() + INTERVAL 1 DAY) AS today_f,
            SUM(status = 'success' AND timestamp >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS week_s,
            SUM($failedCond AND timestamp >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS week_f,
            SUM(status = 'success' AND timestamp >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS month_s,
            SUM($failedCond AND timestamp >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS month_f,
            SUM(status = 'success' AND timestamp >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)) AS year_s,
            SUM($failedCond AND timestamp >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)) AS year_f
        FROM backups
        WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    $top_errors = $pdo->query("
        SELECT d.name, COUNT(b.id) AS fails
        FROM backups b
        JOIN devices d ON b.device_id = d.id
        WHERE " . backup_failed_sql('b.status') . "
        GROUP BY d.id
        ORDER BY fails DESC
        LIMIT 5
    ")->fetchAll();

    $payload = [
        'ts' => time(),
        'backup_metrics' => $backup_metrics,
        'top_errors' => $top_errors,
        'failure_feed' => fetch_dashboard_failure_feed($pdo, DASHBOARD_FAILURE_FEED_LIMIT),
        'recent_backups' => $pdo->query("
            SELECT b.id, d.name AS device_name, b.status, b.timestamp
            FROM backups b
            JOIN devices d ON b.device_id = d.id
            ORDER BY b.timestamp DESC
            LIMIT 5
        ")->fetchAll(),
        'unscheduled_devices' => $pdo->query("
            SELECT d.name
            FROM devices d
            LEFT JOIN settings s ON d.id = s.device_id AND s.active = 1
            WHERE s.device_id IS NULL
            ORDER BY d.name ASC
        ")->fetchAll(),
    ];

    @file_put_contents(dashboard_stats_cache_path(), json_encode($payload), LOCK_EX);
    return $payload;
}

/** Feed kegagalan backup dashboard — riwayat + tandai yang masih gagal. */
function fetch_dashboard_failure_feed(PDO $pdo, int $limit = 20): array {
    $limit = max(1, min(50, $limit));
    $failedCond = backup_failed_sql('b.status');

    $stillFailedCount = (int)$pdo->query("
        SELECT COUNT(*) FROM devices d
        INNER JOIN backups b ON b.id = (
            SELECT b2.id FROM backups b2
            WHERE b2.device_id = d.id
            ORDER BY b2.timestamp DESC, b2.id DESC
            LIMIT 1
        )
        WHERE $failedCond
    ")->fetchColumn();

    $sql = "
        SELECT b.id AS backup_id, b.timestamp,
               d.id AS device_id, d.name AS device_name, d.ip AS device_ip,
               IF(
                   b.id = (
                       SELECT b2.id FROM backups b2
                       WHERE b2.device_id = d.id
                       ORDER BY b2.timestamp DESC, b2.id DESC
                       LIMIT 1
                   ),
                   1, 0
               ) AS is_still_failed
        FROM backups b
        INNER JOIN devices d ON d.id = b.device_id
        WHERE $failedCond
        ORDER BY is_still_failed DESC, b.timestamp DESC
        LIMIT $limit
    ";
    $items = $pdo->query($sql)->fetchAll();

    return [
        'still_failed_count' => $stillFailedCount,
        'items' => $items,
    ];
}

/**
 * Header kolom tabel dengan link pengurutan naik/turun.
 */
function table_sort_link(string $column, string $text, string $current_sort, string $current_order, array $extra_params = []): string {
    $current_order = strtolower($current_order) === 'asc' ? 'asc' : 'desc';
    $new_order = ($current_sort === $column && $current_order === 'asc') ? 'desc' : 'asc';
    $params = array_merge($_GET, $extra_params);
    $params['sort'] = $column;
    $params['order'] = $new_order;
    $params['page'] = 1;
    $query = http_build_query($params);
    $class = ($current_sort === $column) ? "sortable active $current_order" : 'sortable';
    $label = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    return '<th class="' . $class . '"><a href="?' . htmlspecialchars($query, ENT_QUOTES, 'UTF-8') . '" class="sort-link">'
        . $label . ' <span class="sort-arrow" aria-hidden="true"></span></a></th>';
}

/**
 * Tombol pagination tunggal (<<, <, angka, >, >>).
 */
function pagination_btn(string $href, string $label, bool $disabled = false, bool $active = false): string {
    $class = 'btn btn-sm' . ($active ? '' : ' secondary') . ($disabled ? ' disabled' : '');
    if ($disabled) {
        return '<span class="' . $class . '" aria-disabled="true">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="' . $class . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
}

/**
 * Hitung rentang nomor halaman (maks. 5 tombol angka).
 * - Total ≤ 5: tampilkan semua halaman yang ada.
 * - Total > 5: window 5 halaman, halaman aktif di tengah jika memungkinkan.
 *   Halaman 1–3 → 1..5 | Halaman 4 → 2..6 | … | akhir → (N-4)..N
 *
 * @return array{0: int, 1: int}
 */
function pagination_page_range(int $page, int $nav_pages): array {
    $max_visible = 5;

    if ($nav_pages <= 1) {
        return [1, max(1, $nav_pages)];
    }

    if ($nav_pages <= $max_visible) {
        return [1, $nav_pages];
    }

    $page = max(1, min($page, $nav_pages));
    $half = intdiv($max_visible, 2); // 2 → halaman aktif di posisi tengah (ke-3 dari 5)

    $start = $page - $half;
    $end = $page + ($max_visible - $half - 1);

    if ($start < 1) {
        $start = 1;
        $end = $max_visible;
    }

    if ($end > $nav_pages) {
        $end = $nav_pages;
        $start = $nav_pages - $max_visible + 1;
    }

    return [$start, $end];
}

/**
 * Pagination standar: << < 1 2 3 4 5 > >>
 *
 * @param callable(int): string $build_url
 * @param array{show_summary?: bool, last_page?: int} $options
 */
function render_pagination(int $page, int $total_pages, callable $build_url, array $options = []): void {
    $show_summary = $options['show_summary'] ?? true;
    $total_pages = max(0, $total_pages);
    $last_page = (int)($options['last_page'] ?? $total_pages);
    if ($last_page < 1) {
        $last_page = max(1, $total_pages);
    } else {
        $last_page = min($last_page, max(1, $total_pages));
    }

    if ($total_pages <= 1) {
        return;
    }

    $page = max(1, min($page, $last_page));
    $has_prev = ($page > 1);
    $can_next = ($page < $last_page);
    $can_last = ($page < $last_page);
    [$start, $end] = pagination_page_range($page, $last_page);

    echo '<div class="pagination-wrap">';
    echo '<div class="pagination">';
    echo pagination_btn($build_url(1), '<<', !$has_prev);
    echo pagination_btn($build_url(max(1, $page - 1)), '<', !$has_prev);

    for ($i = $start; $i <= $end; $i++) {
        echo pagination_btn($build_url($i), (string)$i, false, $i === $page);
    }

    echo pagination_btn($build_url(min($last_page, $page + 1)), '>', !$can_next);
    echo pagination_btn($build_url($last_page), '>>', !$can_last);

    echo '</div>';

    if ($show_summary) {
        echo '<div class="pagination-summary small-text-muted">';
        echo 'Halaman ' . (int)$page . ' dari ' . (int)$total_pages;
        if ($last_page < $total_pages) {
            echo ' (navigasi maks. hal. ' . (int)$last_page . ')';
        }
        echo '</div>';
    }

    echo '</div>';
}
?>
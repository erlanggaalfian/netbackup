<?php
/**
 * File Konfigurasi Database dan Helper Functions (Versi Final & Stabil)
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
    session_start();
}

// Aktifkan error reporting untuk development agar mudah di-debug
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Definisi Path
define('APP_ROOT', dirname(__DIR__));
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

// --- Fungsi Helper ---

function post(string $key): ?string {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : null;
}

function get(string $key): ?string {
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : null;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(?string $token): bool {
    return !empty($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function require_login(): void {
    if (empty($_SESSION['user'])) {
        header('Location: login.php');
        exit;
    }
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

function check_device_connectivity(string $host, int $port, float $timeout = 2.0): bool {
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if ($socket) {
        fclose($socket);
        return true;
    }
    return false;
}

function ping_host(string $ip): ?bool {
    exec(sprintf('ping -c 1 -W 1 %s', escapeshellarg($ip)), $output, $return_code);
    return $return_code === 0;
}
?>
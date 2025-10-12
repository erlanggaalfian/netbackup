<?php
require_once __DIR__ . '/../config/db.php';
require_login();
// require_admin(); // Dihapus agar user biasa bisa mengakses

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM devices WHERE id=?');
$stmt->execute([$id]);
$device = $stmt->fetch();
if (!$device) { http_response_code(404); echo 'Not found'; exit; }

$vendors = $pdo->query('SELECT * FROM vendor ORDER BY name')->fetchAll();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        $error = 'Invalid CSRF token';
    } else {
        $name = post('name');
        $ip = post('ip');
        $location = post('location');
        $vendor_id = (int)(post('vendor_id') ?? 0);
        $protocol = post('protocol');
        $port = (int)(post('port') ?? 22);
        $username = post('username');
        $password = post('password');

        if ($name && $ip && $vendor_id && $protocol && $port && $username) {
            $enc = $device['password_enc'];
            if ($password) { $enc = encrypt_secret($password); }
            $stmt = $pdo->prepare('UPDATE devices SET name=?, ip=?, location=?, vendor_id=?, protocol=?, port=?, username=?, password_enc=? WHERE id=?');
            $stmt->execute([$name, $ip, $location, $vendor_id, $protocol, $port, $username, $enc, $id]);
            app_log('device_edit', 'Edited device ' . $name . ' (' . $ip . ')', current_user()['id'] ?? null, $id);
            header('Location: devices.php');
            exit;
        } else {
            $error = 'Required fields missing';
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1 class="page-title">Edit Perangkat</h1>
  <p class="page-subtitle">Perbarui konfigurasi dan pengaturan perangkat</p>
</div>

<div class="form-container">
  <h2 class="form-title">Konfigurasi Perangkat</h2>
  <?php if ($error): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>
  
  <form method="post">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    
    <div class="form-grid">
      <div class="field">
        <label>Nama Perangkat *</label>
        <input name="name" value="<?php echo htmlspecialchars($device['name']); ?>" required>
      </div>
      <div class="field">
        <label>Alamat IP *</label>
        <input name="ip" value="<?php echo htmlspecialchars($device['ip']); ?>" required>
      </div>
    </div>
    
    <div class="field">
      <label>Lokasi</label>
      <input name="location" value="<?php echo htmlspecialchars($device['location'] ?? ''); ?>" placeholder="contoh: Data Center Rack A1">
    </div>
    
    <div class="form-grid">
      <div class="field">
        <label>Vendor *</label>
        <select name="vendor_id" required>
          <?php foreach ($vendors as $v): ?>
            <option value="<?php echo (int)$v['id']; ?>" <?php if ((int)$v['id']===(int)$device['vendor_id']) echo 'selected'; ?>><?php echo htmlspecialchars($v['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Protokol *</label>
        <select name="protocol">
          <option value="SSH" <?php if ($device['protocol']==='SSH') echo 'selected'; ?>>SSH</option>
          <option value="Telnet" <?php if ($device['protocol']==='Telnet') echo 'selected'; ?>>Telnet</option>
        </select>
      </div>
    </div>
    
    <div class="form-grid">
      <div class="field">
        <label>Port *</label>
        <input type="number" name="port" value="<?php echo (int)$device['port']; ?>" required>
      </div>
      <div class="field">
        <label>Username *</label>
        <input name="username" value="<?php echo htmlspecialchars($device['username']); ?>" required>
      </div>
    </div>
    
    <div class="field">
      <label>Password (kosongkan untuk mempertahankan yang lama)</label>
      <input type="password" name="password" placeholder="Masukkan password baru">
    </div>
    
    <div class="action-buttons mt-3">
      <button class="btn btn-lg" type="submit">
        <span>💾</span> Perbarui Perangkat
      </button>
      <a href="/devices" class="btn secondary">Batal</a>
    </div>
  </form>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>

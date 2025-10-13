<?php
require_once __DIR__ . '/../config/db.php';
require_login();
// require_admin(); // Dihapus agar user biasa bisa mengakses

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

        if ($name && $ip && $vendor_id && $protocol && $port && $username && $password) {
            $enc = encrypt_secret($password);
            $stmt = $pdo->prepare('INSERT INTO devices(name, ip, location, vendor_id, protocol, port, username, password_enc) VALUES(?,?,?,?,?,?,?,?)');
            $stmt->execute([$name, $ip, $location, $vendor_id, $protocol, $port, $username, $enc]);
            $id = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO settings(device_id, schedule, active) VALUES(?,?,1)')->execute([$id, 'daily']);
            app_log('device_add', 'Added device ' . $name . ' (' . $ip . ')', current_user()['id'] ?? null, $id);
            header('Location: devices.php');
            exit;
        } else {
            $error = 'All fields are required';
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="form-container form-container-full-width">
  <h1 class="page-title">Tambah Perangkat</h1>
  <p class="page-subtitle">Konfigurasi perangkat jaringan baru untuk manajemen backup</p>
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
        <input name="name" placeholder="contoh: Router-01" required>
      </div>
      <div class="field">
        <label>Alamat IP *</label>
        <input name="ip" placeholder="192.168.1.1" required>
      </div>
    </div>
    
    <div class="field">
      <label>Lokasi</label>
      <input name="location" placeholder="contoh: Data Center Rack A1, Lantai 2 Kantor">
    </div>
    
    <div class="form-grid">
      <div class="field">
        <label>Vendor *</label>
        <select name="vendor_id" required>
          <option value="">Pilih vendor</option>
          <?php foreach ($vendors as $v): ?>
            <option value="<?php echo (int)$v['id']; ?>"><?php echo htmlspecialchars($v['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Protokol *</label>
        <select name="protocol">
          <option value="SSH">SSH</option>
          <option value="Telnet">Telnet</option>
        </select>
      </div>
    </div>
    
    <div class="form-grid">
      <div class="field">
        <label>Port *</label>
        <input type="number" name="port" value="22" placeholder="22" required>
      </div>
      <div class="field">
        <label>Username *</label>
        <input name="username" placeholder="admin" required>
      </div>
    </div>
    
    <div class="field">
      <label>Password *</label>
      <input type="password" name="password" placeholder="Masukkan password perangkat" required>
    </div>
    
    <div class="action-buttons mt-3">
      <button class="btn btn-lg" type="submit">
        <span>💾</span> Simpan Perangkat
      </button>
      <a href="/devices" class="btn secondary">Batal</a>
    </div>
  </form>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>

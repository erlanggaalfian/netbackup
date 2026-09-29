<?php
require_once __DIR__ . '/../config/db.php';
require_login();

// Ambil ID dari URL
$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: devices.php');
    exit;
}

// Ambil data perangkat yang akan diedit
$stmt = $pdo->prepare('SELECT * FROM devices WHERE id = ?');
$stmt->execute([$id]);
$device = $stmt->fetch();

if (!$device) {
    header('Location: devices.php');
    exit;
}

// Mengambil data vendor
$vendors = $pdo->query('SELECT * FROM vendor ORDER BY name')->fetchAll();

// --- Mengambil data Site dan Roles ---
$sites = $pdo->query('SELECT * FROM sites ORDER BY name')->fetchAll();
$roles = $pdo->query('SELECT * FROM roles ORDER BY name')->fetchAll();
// -------------------------------------

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        $error = 'Invalid CSRF token';
    } else {
        $name = post('name');
        $ip = post('ip');
        $location = post('location');
        
        // --- MODIFIKASI: Penanganan Site dan Role (Pilih atau Buat Baru) ---
        $site_id_post = post('site_id');
        $final_site_id = null;
        if ($site_id_post === 'NEW') {
            $new_site_name = trim((string)post('new_site_name'));
            if ($new_site_name !== '') {
                $stmt = $pdo->prepare('SELECT id FROM sites WHERE name = ?');
                $stmt->execute([$new_site_name]);
                $existing_site = $stmt->fetchColumn();
                
                if ($existing_site) {
                    $final_site_id = $existing_site;
                } else {
                    $pdo->prepare('INSERT INTO sites(name) VALUES(?)')->execute([$new_site_name]);
                    $final_site_id = (int)$pdo->lastInsertId();
                }
            }
        } elseif ($site_id_post !== '') {
            $final_site_id = (int)$site_id_post;
        }

        $role_id_post = post('role_id');
        $final_role_id = null;
        if ($role_id_post === 'NEW') {
            $new_role_name = trim((string)post('new_role_name'));
            if ($new_role_name !== '') {
                $stmt = $pdo->prepare('SELECT id FROM roles WHERE name = ?');
                $stmt->execute([$new_role_name]);
                $existing_role = $stmt->fetchColumn();
                
                if ($existing_role) {
                    $final_role_id = $existing_role;
                } else {
                    $pdo->prepare('INSERT INTO roles(name) VALUES(?)')->execute([$new_role_name]);
                    $final_role_id = (int)$pdo->lastInsertId();
                }
            }
        } elseif ($role_id_post !== '') {
            $final_role_id = (int)$role_id_post;
        }
        // -------------------------------------------------------------------------

        $vendor_id = (int)(post('vendor_id') ?? 0);
        $protocol = post('protocol');
        $port = (int)(post('port') ?? 22);
        $username = post('username');
        $password = post('password'); // Password bersifat opsional saat edit

        if ($name && $ip && $vendor_id && $protocol && $port && $username) {
            
            // --- UPDATE QUERY: Memperbarui fungsi UPDATE agar menggunakan final_site_id dan final_role_id ---
            if (!empty($password)) {
                $enc = encrypt_secret($password);
                $stmt = $pdo->prepare('UPDATE devices SET name=?, ip=?, location=?, site_id=?, role_id=?, vendor_id=?, protocol=?, port=?, username=?, password_enc=? WHERE id=?');
                $stmt->execute([$name, $ip, $location, $final_site_id, $final_role_id, $vendor_id, $protocol, $port, $username, $enc, $id]);
            } else {
                $stmt = $pdo->prepare('UPDATE devices SET name=?, ip=?, location=?, site_id=?, role_id=?, vendor_id=?, protocol=?, port=?, username=? WHERE id=?');
                $stmt->execute([$name, $ip, $location, $final_site_id, $final_role_id, $vendor_id, $protocol, $port, $username, $id]);
            }
            // ---------------------------------------------------------------------------------

            app_log('device_edit', 'Updated device ' . $name . ' (' . $ip . ')', current_user()['id'] ?? null, $id);
            header('Location: devices.php');
            exit;
        } else {
            $error = 'All fields except password are required';
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header-container motion-fade-up">
  <h1 class="page-header">Edit Perangkat</h1>
  <p class="page-subtitle">Perbarui konfigurasi perangkat jaringan</p>
</div>

<div class="form-container motion-fade-up">
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
      <label>Lokasi Fisik</label>
      <input name="location" value="<?php echo htmlspecialchars($device['location'] ?? ''); ?>">
    </div>
    
    <div class="form-grid">
      <div class="field">
        <label>Kelompok / Site</label>
        <select name="site_id" onchange="document.getElementById('new_site_container').style.display = (this.value === 'NEW') ? 'block' : 'none';">
          <option value="">-- Tidak Dikategorikan --</option>
          <option value="NEW" class="select-option-emphasis">+ Buat Site Baru...</option>
          <?php foreach ($sites as $s): ?>
            <option value="<?php echo (int)$s['id']; ?>" <?php echo ($device['site_id'] == $s['id']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($s['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div id="new_site_container" class="toggle-reveal is-hidden mt-8">
            <input type="text" name="new_site_name" placeholder="Ketik nama site baru..." autocomplete="off">
        </div>
      </div>
      
      <div class="field">
        <label>Tipe Perangkat</label>
        <select name="role_id" onchange="document.getElementById('new_role_container').style.display = (this.value === 'NEW') ? 'block' : 'none';">
          <option value="">-- Tidak Dikategorikan --</option>
          <option value="NEW" class="select-option-emphasis">+ Buat Tipe Baru...</option>
          <?php foreach ($roles as $r): ?>
            <option value="<?php echo (int)$r['id']; ?>" <?php echo ($device['role_id'] == $r['id']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($r['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div id="new_role_container" class="toggle-reveal is-hidden mt-8">
            <input type="text" name="new_role_name" placeholder="Ketik tipe perangkat baru..." autocomplete="off">
        </div>
      </div>
    </div>
    
    <div class="form-grid">
      <div class="field">
        <label>Vendor *</label>
        <select name="vendor_id" required>
          <option value="">Pilih vendor</option>
          <?php foreach ($vendors as $v): ?>
            <option value="<?php echo (int)$v['id']; ?>" <?php echo ($device['vendor_id'] == $v['id']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($v['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Protokol *</label>
        <select name="protocol">
          <option value="SSH" <?php echo ($device['protocol'] === 'SSH') ? 'selected' : ''; ?>>SSH</option>
          <option value="Telnet" <?php echo ($device['protocol'] === 'Telnet') ? 'selected' : ''; ?>>Telnet</option>
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
      <label>Password Baru (Kosongkan jika tidak ingin mengubah sandi)</label>
      <input type="password" name="password" placeholder="Masukkan password baru untuk mengganti yang lama">
    </div>
    
    <div class="action-buttons mt-3">
      <button class="btn btn-lg" type="submit">
        Simpan Perubahan
      </button>
      <a href="devices.php" class="btn btn-lg secondary">Batal</a>
    </div>
  </form>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
<?php
require_once __DIR__ . '/../config/db.php';
require_login();
require_admin();

$error = '';
$success = '';

// Handle form submission untuk menambah vendor baru
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    if (!verify_csrf(post('csrf'))) {
        $error = 'Token CSRF tidak valid.';
    } else {
        $name = post('name');
        $command = post('command');

        if (!empty($name) && !empty($command)) {
            // Menggunakan INSERT IGNORE seperti yang diminta untuk menghindari error duplikat
            $stmt = $pdo->prepare("INSERT IGNORE INTO vendor(name, command) VALUES (?, ?)");
            $stmt->execute([$name, $command]);
            
            if ($stmt->rowCount() > 0) {
                $success = "Vendor '{$name}' berhasil ditambahkan.";
                app_log('vendor_add', "Menambahkan vendor: {$name}", current_user()['id']);
            } else {
                $error = "Vendor '{$name}' sudah ada atau terjadi kesalahan.";
            }
        } else {
            $error = 'Nama vendor dan command tidak boleh kosong.';
        }
    }
}

// Handle penghapusan vendor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!verify_csrf(post('csrf'))) {
        $error = 'Token CSRF tidak valid.';
    } else {
        $id = (int)post('id');
        try {
            $stmt = $pdo->prepare("DELETE FROM vendor WHERE id = ?");
            $stmt->execute([$id]);
            $success = "Vendor berhasil dihapus.";
            app_log('vendor_delete', "Menghapus vendor id: {$id}", current_user()['id']);
        } catch (PDOException $e) {
            // Menangani error jika vendor masih digunakan oleh perangkat
            if ($e->getCode() == '23000') {
                $error = 'Gagal menghapus: Vendor ini masih digunakan oleh satu atau lebih perangkat.';
            } else {
                $error = 'Terjadi kesalahan saat menghapus vendor.';
            }
        }
    }
}


// Ambil semua data vendor untuk ditampilkan
$vendors = $pdo->query('SELECT id, name, command FROM vendor ORDER BY name ASC')->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="form-container form-container-full-width">
  <h1 class="page-title">Manajemen Vendor</h1>
  <p class="page-subtitle">Tambah, edit, atau hapus vendor perangkat dan command backup-nya</p>
  <button onclick="toggleNotes()" class="btn btn-sm badge-notes">
    📋 Lihat Contoh Command
  </button>
</div>

<!-- Notes Modal -->
<div id="notes-modal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Contoh Command Backup per Brand</h3>
      <button onclick="toggleNotes()" class="modal-close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="vendor-notes">
        <div class="note-section">
          <h4>🔷 Cisco</h4>
          <div class="command-examples">
            <div class="command-item">
              <strong>IOS:</strong> <code>show running-config</code>
            </div>
            <div class="command-item">
              <strong>NX-OS:</strong> <code>show running-config</code>
            </div>
            <div class="command-item">
              <strong>ASA:</strong> <code>show running-config</code>
            </div>
          </div>
        </div>
        
        <div class="note-section">
          <h4>🔷 Huawei</h4>
          <div class="command-examples">
            <div class="command-item">
              <strong>VRP:</strong> <code>display current-configuration | no-more</code>
              <div class="note-text">⚠️ Penting: Tambahkan <code>| no-more</code> untuk menghindari error</div>
            </div>
            <div class="command-item">
              <strong>eNSP:</strong> <code>display current-configuration</code>
            </div>
          </div>
        </div>
        
        <div class="note-section">
          <h4>🔷 Juniper</h4>
          <div class="command-examples">
            <div class="command-item">
              <strong>JunOS:</strong> <code>show configuration | display set</code>
            </div>
            <div class="command-item">
              <strong>ScreenOS:</strong> <code>get config</code>
            </div>
          </div>
        </div>
        
        <div class="note-section">
          <h4>🔷 Mikrotik</h4>
          <div class="command-examples">
            <div class="command-item">
              <strong>RouterOS:</strong> <code>/export</code>
            </div>
            <div class="command-item">
              <strong>RouterOS (tersed):</strong> <code>/export compact</code>
            </div>
          </div>
        </div>
        
        <div class="note-section">
          <h4>🔷 ZTE</h4>
          <div class="command-examples">
            <div class="command-item">
              <strong>ZXR10:</strong> <code>show running-config</code>
            </div>
            <div class="command-item">
              <strong>ZXR10 (tersed):</strong> <code>show running-config | no-more</code>
            </div>
          </div>
        </div>
        
        <div class="note-section">
          <h4>🔷 Fortinet</h4>
          <div class="command-examples">
            <div class="command-item">
              <strong>FortiGate:</strong> <code>show full-configuration</code>
            </div>
            <div class="command-item">
              <strong>FortiGate (tersed):</strong> <code>show full-configuration | grep -v "^#"</code>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($error): ?><div class="alert error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

<div class="form-grid-layout">

    <div class="form-container form-container-no-margin">
        <h2 class="form-title">Tambah Vendor Baru</h2>
        <form action="/vendors" method="post">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <div class="field">
                <label for="name">Nama Vendor</label>
                <input type="text" id="name" name="name" placeholder="contoh: cisco_nxos" required>
            </div>
            <div class="field">
                <label for="command">Command Backup</label>
                <input type="text" id="command" name="command" placeholder="contoh: show running-config" required>
            </div>
            <div class="action-buttons mt-2">
                <button type="submit" class="btn">Tambah Vendor</button>
            </div>
        </form>
    </div>

    <div class="table-container">
        <div class="table-header">
            <h2 class="table-title">Daftar Vendor</h2>
        </div>
        <table class="modern-table">
            <thead>
                <tr>
                    <th>Nama Vendor</th>
                    <th>Command</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vendors)): ?>
                    <tr>
                        <td colspan="3" class="table-cell-center">Belum ada data vendor.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($vendors as $vendor): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($vendor['name']); ?></strong></td>
                        <td><code style="font-family: monospace; background: #f1f5f9; padding: 2px 6px; border-radius: 4px;"><?php echo htmlspecialchars($vendor['command']); ?></code></td>
                        <td>
                            <div class="action-buttons">
                                <a href="/edit_vendor?id=<?php echo $vendor['id']; ?>" class="btn btn-sm secondary">Edit</a>
                                <form action="/vendors" method="POST" onsubmit="return confirm('Anda yakin ingin menghapus vendor ini?');" class="inline">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $vendor['id']; ?>">
                                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>"> 
                                    <button type="submit" class="btn btn-sm danger">Hapus</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function toggleNotes() {
    const modal = document.getElementById('notes-modal');
    if (modal.style.display === 'none' || modal.style.display === '') {
        modal.style.display = 'flex';
    } else {
        modal.style.display = 'none';
    }
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('notes-modal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
/**
 * File: web/vendors.php
 * Halaman untuk mengelola master data vendor dan command backup.
 * Versi Final: Clean Code + Sinkronisasi Global CSS + Status Mode Interaktif.
 */
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
        // Tangkap nilai checkbox mode interaktif
        $is_interactive = isset($_POST['is_interactive']) ? 1 : 0;

        if (!empty($name) && !empty($command)) {
            // Menggunakan INSERT IGNORE untuk menghindari error duplikat
            $stmt = $pdo->prepare("INSERT IGNORE INTO vendor(name, command, is_interactive) VALUES (?, ?, ?)");
            $stmt->execute([$name, $command, $is_interactive]);
            
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

// Ambil semua data vendor untuk ditampilkan (Termasuk field is_interactive)
$vendor_sort = get('sort') ?? 'name';
$vendor_order = get('order') ?? 'asc';
$allowed_vendor_sort = ['name', 'command', 'is_interactive'];
$vendor_sort_col = in_array($vendor_sort, $allowed_vendor_sort, true) ? $vendor_sort : 'name';
$vendor_sql_order = (strtolower($vendor_order) === 'desc') ? 'DESC' : 'ASC';
$vendors = $pdo->query('SELECT id, name, command, is_interactive FROM vendor ORDER BY ' . $vendor_sort_col . ' ' . $vendor_sql_order)->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header-container m-item motion-fade-up motion-fade-up">
  <div class="page-header-flex">
    <div>
      <h1 class="page-header m-item-title">Manajemen Vendor</h1>
      <p class="page-subtitle m-item-extra">Tambah, edit, atau hapus vendor perangkat dan command backup-nya</p>
    </div>
    <div class="page-actions">
      <button onclick="toggleNotes()" class="btn secondary">Lihat Contoh Command</button>
    </div>
  </div>
</div>

<div id="notes-modal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Contoh Command Backup per Brand</h3>
      <button onclick="toggleNotes()" class="modal-close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="vendor-notes">
        
        <div class="note-section">
          <h4>🔷 Arista & Cisco</h4>
          <div class="command-examples">
            <div class="command-item">
              <strong>Arista / Cisco:</strong> <code class="code-block">show running-config</code>
            </div>
          </div>
        </div>
        
        <div class="note-section">
          <h4>🔷 Huawei</h4>
          <div class="command-examples">
            <div class="command-item">
              <strong>Huawei ATN & SW:</strong> <code class="code-block">display current-configuration | no-more</code>
            </div>
          </div>
            <div class="command-item">
              <strong>Huawei S6730:</strong> <code class="code-block">screen-length 0 temporary \ndisplay current-configuration</code>
              <div class="note-text alert-warning-text note-text-sm">
                ⚠️ <strong>Penting:</strong> Centang <strong>"Gunakan Mode Interaktif"</strong> saat menambahkan atau mengedit vendor Huawei S6730.
              </div>
            </div>
        </div>
        
        <div class="note-section">
          <h4>🔷 Juniper</h4>
          <div class="command-examples">
            <div class="command-item">
              <strong>Juniper:</strong> <code class="code-block">show configuration | display set</code>
            </div>
          </div>
        </div>
        
        <div class="note-section">
          <h4>🔷 Mikrotik</h4>
          <div class="command-examples">
            <div class="command-item">
              <strong>Mikrotik v6:</strong> <code class="code-block">/export</code>
            </div>
            <div class="command-item">
              <strong>Mikrotik v7:</strong> <code class="code-block">/export show-sensitive</code>
            </div>
          </div>
        </div>
        
        <div class="note-section">
          <h4>🔷 VyOS</h4>
          <div class="command-examples">
            <div class="command-item">
              <strong>VyOS:</strong> <code class="code-block code-break-all">/opt/vyatta/bin/vyatta-op-cmd-wrapper show configuration commands</code>
            </div>
          </div>
        </div>
        
        <div class="note-section">
          <h4>🔷 ZTE</h4>
          <div class="command-examples">
            <div class="command-item">
              <strong>ZTE:</strong> <code class="code-block">terminal length 0\nshow running-config</code>
              <div class="note-text alert-warning-text note-text-sm">
                ⚠️ <strong>Penting:</strong> Centang <strong>"Gunakan Mode Interaktif"</strong> saat menambahkan atau mengedit vendor ZTE.
                Jika config terpotong di tengah (mis. setelah <code>pon-onu-mng</code>), skrip backup otomatis menekan spasi pada prompt <code>--More--</code>.
                Alternatif perintah: <code>scroll\nshow running-config</code> atau <code>show running-config | nomore</code> (sesuai firmware).
              </div>
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

    <div class="form-container motion-fade-up form-container-no-margin">
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
            
            <div class="field field-option">
                <label class="option-toggle" for="is_interactive">
                    <input type="checkbox" name="is_interactive" id="is_interactive" value="1">
                    <span class="option-toggle-content">
                        <span class="option-toggle-title">Gunakan Mode Interaktif</span>
                        <span class="option-toggle-desc">Centang untuk perangkat yang butuh invoke_shell, misalnya ZTE atau Huawei S6730.</span>
                    </span>
                </label>
            </div>

            <div class="action-buttons mt-3">
                <button type="submit" class="btn btn-lg">Tambah Vendor</button>
            </div>
        </form>
    </div>

    <div class="table-container motion-fade-up motion-fade-up">
        <div class="table-header">
            <h2 class="table-title">Daftar Vendor</h2>
        </div>
        <div class="table-scroll-wrap">
        <table class="modern-table">
            <thead>
                <tr>
                    <?php echo table_sort_link('name', 'Nama Vendor', $vendor_sort, $vendor_order); ?>
                    <?php echo table_sort_link('command', 'Command', $vendor_sort, $vendor_order); ?>
                    <?php echo table_sort_link('is_interactive', 'Mode Interaktif', $vendor_sort, $vendor_order); ?>
                    <th style="text-align: right; width: 120px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vendors)): ?>
                    <tr>
                        <td colspan="4" class="table-cell-center">Belum ada data vendor.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($vendors as $vendor): ?>
                    <tr>
                        <td class="td-primary" data-label="Nama Vendor"><strong><?php echo htmlspecialchars($vendor['name']); ?></strong> <span class="m-collapsed-extra"><?php if ((int)($vendor['is_interactive'] ?? 0) === 1): ?><span class="badge blue">Aktif</span><?php else: ?><span class="badge gray">Nonaktif</span><?php endif; ?></span></td>
                        <td data-label="Command"><code class="code-block"><?php echo htmlspecialchars($vendor['command']); ?></code></td>
                        <td data-label="Mode Interaktif" style="text-align: center;">
                            <?php if ((int)($vendor['is_interactive'] ?? 0) === 1): ?>
                                <span class="badge blue">Aktif</span>
                            <?php else: ?>
                                <span class="badge gray">Nonaktif</span>
                            <?php endif; ?>
                        </td>
                        <td class="td-actions" data-label="Aksi" style="text-align: right;">
                            <div class="action-buttons">
                                <a href="/edit_vendor?id=<?php echo $vendor['id']; ?>" class="btn btn-sm secondary">Edit</a>
                                <form action="/vendors" method="POST" onsubmit="return mConfirmSubmit(event, this, 'Anda yakin ingin menghapus vendor ini?', 'Hapus');" class="inline">
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

window.onclick = function(event) {
    const modal = document.getElementById('notes-modal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
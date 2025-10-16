<?php
require_once __DIR__ . '/../config/db.php';
require_login();
require_admin();

$id = (int)(get('id') ?? 0);
if ($id <= 0) {
    header('Location: vendors.php');
    exit;
}

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        $error = 'Token CSRF tidak valid.';
    } else {
        $name = post('name');
        $command = post('command');

        if (!empty($name) && !empty($command)) {
            $stmt = $pdo->prepare("UPDATE vendor SET name = ?, command = ? WHERE id = ?");
            $stmt->execute([$name, $command, $id]);
            $success = "Vendor '{$name}' berhasil diperbarui.";
            app_log('vendor_edit', "Mengedit vendor id: {$id}", current_user()['id']);
        } else {
            $error = 'Nama vendor dan command tidak boleh kosong.';
        }
    }
}

// Ambil data vendor saat ini
$stmt = $pdo->prepare("SELECT id, name, command FROM vendor WHERE id = ?");
$stmt->execute([$id]);
$vendor = $stmt->fetch();

if (!$vendor) {
    header('Location: vendors.php');
    exit;
}

include __DIR__ . '/includes/header.php';
?>

<div class="form-container form-container-full-width">
  <h1 class="page-title">Edit Vendor</h1>
  <p class="page-subtitle">Perbarui nama dan command untuk vendor: <strong><?php echo htmlspecialchars($vendor['name']); ?></strong></p>
</div>

<div class="form-container form-container-max-width">
    <?php if ($error): ?><div class="alert error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <h2 class="form-title">Detail Vendor</h2>
    <form action="edit_vendor.php?id=<?php echo $vendor['id']; ?>" method="post">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <div class="field">
            <label for="name">Nama Vendor</label>
            <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($vendor['name']); ?>" required>
        </div>
        <div class="field">
            <label for="command">Command Backup</label>
            <input type="text" id="command" name="command" value="<?php echo htmlspecialchars($vendor['command']); ?>" required>
        </div>
        <div class="action-buttons mt-2">
            <button type="submit" class="btn"><span>💾</span> Simpan Perubahan</button>
            <a href="/vendors" class="btn secondary">Kembali ke Daftar Vendor</a>
        </div>
    </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

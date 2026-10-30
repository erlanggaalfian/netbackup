<?php
require_once __DIR__ . '/../config/db.php';
require_login();
require_admin();

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header('Location: vendors.php');
    exit;
}

$error = '';

// =========================
// HANDLE UPDATE
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $update_id = isset($_POST['vendor_id']) ? (int)$_POST['vendor_id'] : 0;
    $csrf_token = $_POST['csrf'] ?? '';

    if (!verify_csrf($csrf_token)) {
        $error = 'Token CSRF tidak valid';
    } else {

        $name = trim($_POST['name'] ?? '');
        $command = trim($_POST['command'] ?? '');
        $is_interactive = isset($_POST['is_interactive']) ? 1 : 0;

        if ($update_id <= 0) {
            $error = 'ID vendor tidak valid';
        } elseif ($name === '' || $command === '') {
            $error = 'Nama vendor dan command wajib diisi';
        } else {

            try {

                // cek vendor ada atau tidak
                $checkVendor = $pdo->prepare("SELECT id FROM vendor WHERE id = ?");
                $checkVendor->execute([$update_id]);

                if (!$checkVendor->fetch()) {
                    throw new Exception('Vendor tidak ditemukan');
                }

                // cek duplicate name
                $duplicate = $pdo->prepare("SELECT id FROM vendor WHERE name = ? AND id != ?");
                $duplicate->execute([$name, $update_id]);

                if ($duplicate->fetch()) {
                    throw new Exception('Nama vendor sudah digunakan');
                }

                // update data
                $stmt = $pdo->prepare("
                    UPDATE vendor
                    SET
                        name = ?,
                        `command` = ?,
                        is_interactive = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $name,
                    $command,
                    $is_interactive,
                    $update_id
                ]);

                // debug row affected
                $affected = $stmt->rowCount();

                // commit paksa jika transaction aktif
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }

                app_log(
                    'vendor_edit',
                    "Updated vendor ID {$update_id}",
                    current_user()['id'] ?? null
                );

                header('Location: vendors.php?status=edited');
                exit;

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log('EDIT_VENDOR_ERROR: ' . $e->getMessage());

                $error = $e->getMessage();
            }
        }
    }
}

// =========================
// FETCH DATA
// =========================
$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        `command`,
        is_interactive
    FROM vendor
    WHERE id = ?
");

$stmt->execute([$id]);
$vendor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vendor) {
    header('Location: vendors.php');
    exit;
}

include __DIR__ . '/includes/header.php';
?>

<!-- Bagian Header disamakan dengan style Edit Perangkat -->
<div class="page-header-container m-item">
    <h1 class="page-header m-item-title">Edit Vendor</h1>
    <p class="page-subtitle m-item-extra">
        Perbarui konfigurasi vendor: <strong><?php echo htmlspecialchars($vendor['name']); ?></strong>
    </p>
</div>

<div class="form-container">
    <h2 class="form-title">Detail Vendor</h2>

    <?php if ($error): ?>
        <div class="alert error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="post">

        <input
            type="hidden"
            name="csrf"
            value="<?php echo htmlspecialchars(csrf_token()); ?>"
        >

        <input
            type="hidden"
            name="vendor_id"
            value="<?php echo (int)$vendor['id']; ?>"
        >

        <div class="field">
            <label for="name">Nama Vendor</label>
            <input
                type="text"
                id="name"
                name="name"
                value="<?php echo htmlspecialchars($vendor['name']); ?>"
                required
            >
        </div>

        <div class="field">
            <label for="command">Command Backup</label>
            <input
                type="text"
                id="command"
                name="command"
                value="<?php echo htmlspecialchars($vendor['command']); ?>"
                required
            >
        </div>

        <div class="field field-option">
            <label class="option-toggle" for="is_interactive">
                <input type="checkbox" name="is_interactive" id="is_interactive" value="1" <?php echo (!empty($vendor['is_interactive'])) ? 'checked' : ''; ?>>
                <span class="option-toggle-content">
                    <span class="option-toggle-title">Gunakan Mode Interaktif</span>
                    <span class="option-toggle-desc">Centang untuk perangkat yang butuh invoke_shell, misalnya ZTE atau Huawei S6730.</span>
                </span>
            </label>
        </div>

        <div class="action-buttons mt-3">
            <button type="submit" class="btn btn-lg">Simpan Perubahan</button>
            <a href="vendors.php" class="btn btn-lg secondary">Kembali ke Daftar Vendor</a>
        </div>

    </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
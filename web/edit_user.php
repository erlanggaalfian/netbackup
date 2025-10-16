<?php
require_once __DIR__ . '/../config/db.php';
require_login();
require_admin();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM users WHERE id=?');
$stmt->execute([$id]);
$user = $stmt->fetch();
if (!$user) { http_response_code(404); echo 'Not found'; exit; }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        $error = 'Invalid CSRF token';
    } else {
        $role = post('role');
        $password = post('password');
        if (!in_array($role, ['admin','user'], true)) {
            $error = 'Invalid role';
        } else {
            if ($password) {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare('UPDATE users SET password_hash=?, role=? WHERE id=?');
                $stmt->execute([$hash, $role, $id]);
            } else {
                $stmt = $pdo->prepare('UPDATE users SET role=? WHERE id=?');
                $stmt->execute([$role, $id]);
            }
            app_log('user_edit', 'Edited user id=' . $id, current_user()['id'] ?? null, $id);
            header('Location: users.php');
            exit;
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="form-container form-container-full-width">
  <h1 class="page-title">Edit Pengguna</h1>
  <p class="page-subtitle">Perbarui data pengguna: <?php echo htmlspecialchars($user['username']); ?></p>
</div>

<div class="form-container">
  <h2 class="form-title">Data Pengguna</h2>
  <?php if ($error): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>
  
  <form method="post">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    
    <div class="field">
      <label>Role *</label>
      <select name="role">
        <option value="user" <?php if ($user['role']==='user') echo 'selected'; ?>>User</option>
        <option value="admin" <?php if ($user['role']==='admin') echo 'selected'; ?>>Admin</option>
      </select>
    </div>
    
    <div class="field">
      <label>Password Baru (kosongkan untuk mempertahankan yang lama)</label>
      <input type="password" name="password" placeholder="Masukkan password baru">
    </div>
    
    <div class="action-buttons mt-3">
      <button class="btn btn-lg" type="submit">
        <span>💾</span> Perbarui Pengguna
      </button>
      <a href="/users" class="btn secondary">Batal</a>
    </div>
  </form>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>

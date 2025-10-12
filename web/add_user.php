<?php
require_once __DIR__ . '/../config/db.php';
require_login();
require_admin();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        $error = 'Invalid CSRF token';
    } else {
        $username = post('username');
        $password = post('password');
        $role = post('role');
        if ($username && $password && in_array($role, ['admin','user'], true)) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare('INSERT INTO users(username, password_hash, role) VALUES(?,?,?)');
            try {
                $stmt->execute([$username, $hash, $role]);
                app_log('user_add', 'Added user ' . $username, current_user()['id'] ?? null);
                header('Location: users.php');
                exit;
            } catch (Throwable $e) {
                $error = 'Username already exists';
            }
        } else {
            $error = 'All fields required';
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1 class="page-title">Tambah Pengguna</h1>
  <p class="page-subtitle">Buat akun pengguna baru untuk sistem</p>
</div>

<div class="form-container">
  <h2 class="form-title">Data Pengguna</h2>
  <?php if ($error): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>
  
  <form method="post">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    
    <div class="field">
      <label>Username *</label>
      <input name="username" placeholder="Masukkan username" required>
    </div>
    
    <div class="field">
      <label>Password *</label>
      <input type="password" name="password" placeholder="Masukkan password" required>
    </div>
    
    <div class="field">
      <label>Role *</label>
      <select name="role">
        <option value="user">User</option>
        <option value="admin">Admin</option>
      </select>
    </div>
    
    <div class="action-buttons mt-3">
      <button class="btn btn-lg" type="submit">
        <span>💾</span> Simpan Pengguna
      </button>
      <a href="/users" class="btn secondary">Batal</a>
    </div>
  </form>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>

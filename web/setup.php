<?php
require_once __DIR__ . '/../config/db.php';

// Cek apakah sudah ada admin
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
if ($stmt->fetchColumn() > 0) {
    header('Location: login.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        $error = 'Invalid CSRF token';
    } else {
        $username = post('username');
        $password = post('password');
        if ($username && $password) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare('INSERT INTO users(username, password_hash, role) VALUES(?,?,?)');
            try {
                $stmt->execute([$username, $hash, 'admin']);
                header('Location: /login?status=setup_success');
                exit;
            } catch (Throwable $e) {
                $error = 'Username already exists';
            }
        } else {
            $error = 'All fields required';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>NetBackup - Setup</title>
  <link rel="icon" type="image/png" href="/img/logo.png">
  <link rel="shortcut icon" type="image/png" href="/img/logo.png">
  <link rel="stylesheet" href="/style/global.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="auth-page">
<div class="login-container">
  <div class="logo-section">
    <h1 class="logo-title">Setup Awal</h1>
    <p class="logo-subtitle">Buat akun administrator pertama</p>
  </div>

  <?php if ($error): ?>
    <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <div class="form-group">
      <label class="form-label">Username Admin</label>
      <input type="text" name="username" class="form-input" placeholder="Masukkan username" required>
    </div>
    <div class="form-group">
      <label class="form-label">Password</label>
      <input type="password" name="password" class="form-input" placeholder="Masukkan password" required>
    </div>
    <button type="submit" class="login-btn">Buat Akun</button>
  </form>
</div>
</body>
</html>


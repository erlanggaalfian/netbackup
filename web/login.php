<?php
/**
 * File: web/login.php
 * Halaman masuk sistem utama (Login).
 * Versi Final: Keamanan CSRF + Integrasi Latar Belakang Interaktif & Pengalihan Kontak Email Administrasi.
 */
require_once __DIR__ . '/../config/db.php';

// Prevent browser from caching the login page (stale CSRF token)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Regenerate CSRF token on every GET to prevent stale tokens
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Cek jika tidak ada admin, redirect ke setup.php
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
if ($stmt->fetchColumn() == 0) {
    header('Location: setup.php');
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
            $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['user'] = [ 'id' => $user['id'], 'username' => $user['username'], 'role' => $user['role'] ];
                app_log('login_success', 'User logged in: ' . $user['username'], $user['id']);
                header('Location: /dashboard');
                exit;
            } else {
                $error = 'Invalid credentials';
                app_log('login_failed', 'Failed login for user: ' . $username);
            }
        } else {
            $error = 'Please enter username and password';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>NetBackup - Login</title>
  <link rel="icon" type="image/png" href="/img/logo.png">
  <link rel="shortcut icon" type="image/png" href="/img/logo.png">
  <link rel="stylesheet" href="/style/global.css?v=2.10.0_auth_bg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="auth-page">

<?php include __DIR__ . '/includes/auth-bg.php'; ?>

<div class="login-wrapper">
<div class="login-container">
<div class="logo-section">
    <img src="/img/logo.png" alt="NetBackup Logo" class="login-logo">
    <h1 class="logo-title">NetBackup</h1>
    <p class="logo-subtitle">ALWAYS READY TO RISE</p>
</div>

  <?php if ($error): ?>
    <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    
    <div class="form-group motion-fade-up">
      <label class="form-label">
        <svg class="form-icon" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"></path>
        </svg>
        Username
      </label>
      <input type="text" name="username" class="form-input" placeholder="Masukkan username Anda" required>
    </div>

    <div class="form-group motion-fade-up">
      <label class="form-label">
        <svg class="form-icon" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"></path>
        </svg>
        Password
      </label>
      <div class="password-container">
        <input type="password" name="password" id="password" class="form-input" placeholder="Masukkan password Anda" required>
        <button type="button" class="password-toggle" onclick="togglePassword()">
          <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
            <path d="M10 12a2 2 0 100-4 2 2 0 000 4z"></path>
            <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"></path>
          </svg>
        </button>
      </div>
    </div>

    <div class="form-options motion-fade-up">
      <div class="remember-me">
        <input type="checkbox" id="remember" name="remember">
        <label for="remember">Ingat saya</label>
      </div>
      <a href="mailto:erlanggaalfian82@gmail.com?subject=NetBackup%20-%20Permintaan%20Reset%20Password" class="forgot-password">Lupa password?</a>
    </div>

    <button type="submit" class="btn login-btn">
      <span>Masuk</span>
      <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
        <path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd"></path>
      </svg>
    </button>
  </form>

    <p class="footer-text">
        &copy; <?php echo date('Y'); ?> <strong>NetBackup v<?php echo htmlspecialchars(app_version()); ?></strong>. by Erlangga Alfian.
    </p>
</div>
</div>

<script>
function togglePassword() {
  const passwordInput = document.getElementById('password');
  const toggleBtn = document.querySelector('.password-toggle');
  
  if (passwordInput.type === 'password') {
    passwordInput.type = 'text';
    toggleBtn.innerHTML = `
      <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
        <path fill-rule="evenodd" d="M3.707 2.293a1 1 0 00-1.414 1.414l14 14a1 1 0 001.414-1.414l-1.473-1.473A10.014 10.014 0 0019.542 10C18.268 5.943 14.478 3 10 3a9.958 9.958 0 00-4.512 1.074l-1.78-1.781zm4.261 4.26l1.514 1.515a2.003 2.003 0 012.45 2.45l1.514 1.514a4 4 0 00-5.478-5.478z" clip-rule="evenodd"></path>
        <path d="M12.454 16.697L9.75 13.992a4 4 0 01-3.742-3.741L2.335 6.578A9.98 9.98 0 00.458 10c1.274 4.057 5.065 7 9.542 7 .847 0 1.669-.105 2.454-.303z"></path>
      </svg>
    `;
  } else {
    passwordInput.type = 'password';
    toggleBtn.innerHTML = `
      <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
        <path d="M10 12a2 2 0 100-4 2 2 0 000 4z"></path>
        <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"></path>
      </svg>
    `;
  }
}
</script>
</body>
</html>
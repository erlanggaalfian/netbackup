<?php
require_once __DIR__ . '/../config/db.php';

// Log logout action if a user is logged in
if (current_user()) {
    app_log('logout', 'User logged out: ' . current_user()['username'], current_user()['id']);
}

// Unset all session values
$_SESSION = [];

// Destroy the session
session_destroy();

// Redirect to login page after 3 seconds
header('Refresh: 3; url=/login');
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>NetBackup - Keluar</title>
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
    <div class="login-container" style="text-align: center;">
      <div class="logout-icon logout-icon-lg">👋</div>
      <h1 class="logo-title logo-title-spaced">Berhasil Keluar</h1>
      <p class="logo-subtitle text-muted">Anda akan diarahkan ke halaman login...</p>
    </div>
  </div>

</body>
</html>

<?php
require_once __DIR__ . '/../config/db.php';

if (current_user()) {
    app_log('logout', 'User logged out: ' . current_user()['username'], current_user()['id']);
}
$_SESSION = [];
session_destroy();

header('Refresh: 2; url=/login');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>NetBackup - Keluar</title>
  <link rel="icon" type="image/png" href="/img/logo.png">
  <link rel="shortcut icon" type="image/png" href="/img/logo.png">
  <link rel="stylesheet" href="/style/global.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="auth-page">
<div class="logout-container">
  <div class="logout-icon">👋</div>
  <h1 class="logout-title">Berhasil Keluar</h1>
  <p class="logout-text">Anda telah berhasil keluar dari sistem. Mengarahkan ke halaman login...</p>
</div>
</body>
</html>


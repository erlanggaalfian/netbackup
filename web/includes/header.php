<?php
// Memuat file konfigurasi database utama
require_once __DIR__ . '/../../config/db.php';

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NetBackup - Manajemen Backup Perangkat Jaringan</title>
    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/img/logo.png">
    <link rel="stylesheet" href="/style/global.css?v=2.11.0_tilt">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<?php
$body_classes = ['interactive-bg-page'];
if (!empty($page_body_class)) {
    $body_classes[] = $page_body_class;
}
?>
<body class="<?php echo htmlspecialchars(implode(' ', $body_classes), ENT_QUOTES, 'UTF-8'); ?>">
<?php include __DIR__ . '/interactive-bg.php'; ?>

<div class="navbar" id="main-navbar">
    <?php include __DIR__ . '/navbar.php'; ?>
</div>

<div class="container">

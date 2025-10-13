<?php
// Memuat file konfigurasi database utama
require_once __DIR__ . '/../../config/db.php';

// Pemicu backup otomatis berbasis web telah dihapus karena sudah ada cron job yang lebih stabil.
// require_once __DIR__ . '/../cron_trigger.php';

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NetBackup - Manajemen Backup Perangkat Jaringan</title>
    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="shortcut icon" type="image/png" href="/img/logo.png">
    <link rel="stylesheet" href="/style/global.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
<!-- Canvas untuk latar belakang interaktif ditambahkan di sini -->
<canvas id="dashboard-interactive-bg"></canvas>

<div class="navbar">
    <?php include __DIR__ . '/navbar.php'; ?>
</div>

<div class="container">

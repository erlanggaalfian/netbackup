<?php
// Mendapatkan URI yang diminta untuk menandai link navigasi yang aktif
$requestUri = strtok($_SERVER['REQUEST_URI'], '?');
if (empty($requestUri)) {
    $requestUri = '/dashboard'; // Default ke dashboard jika berada di root
}

// Cek apakah pengguna saat ini adalah admin
$isAdmin = (current_user() && (current_user()['role'] ?? '') === 'admin');
?>
<div class="navbar-inner">
    <div class="navbar-brand">
        <a href="/dashboard" class="brand-link">
            <img src="/img/logo.png" alt="NetBackup Logo" class="navbar-img-logo">
            <span class="brand-name">NetBackup</span>
        </a>
    </div>

    <nav class="navbar-menu">
        <a href="/dashboard" class="nav-link <?php echo $requestUri === '/dashboard' ? 'active' : ''; ?>">Dashboard</a>
        <a href="/devices" class="nav-link <?php echo in_array($requestUri, ['/devices', '/edit_device', '/add_device']) ? 'active' : ''; ?>">Perangkat</a>
        <a href="/backups" class="nav-link <?php echo in_array($requestUri, ['/backups', '/view_backup']) ? 'active' : ''; ?>">Backup</a>
        <a href="/schedule" class="nav-link <?php echo $requestUri === '/schedule' ? 'active' : ''; ?>">Jadwal</a>
        
        <?php if ($isAdmin) : ?>
            <div class="nav-separator"></div>
            <a href="/vendors" class="nav-link <?php echo in_array($requestUri, ['/vendors', '/edit_vendor']) ? 'active' : ''; ?>">Vendor</a>
            <a href="/users" class="nav-link <?php echo in_array($requestUri, ['/users', '/edit_user', '/add_user']) ? 'active' : ''; ?>">Pengguna</a>
            <a href="/logs" class="nav-link <?php echo $requestUri === '/logs' ? 'active' : ''; ?>">Log</a>
        <?php endif; ?>
    </nav>

    <div class="navbar-user">
        <?php if (current_user()) : ?>
            <div class="user-welcome">
                Halo, <strong><?php echo htmlspecialchars(current_user()['username']); ?></strong>
            </div>
            <a href="/logout" class="btn btn-sm secondary logout-btn">
                <span>Keluar</span>
            </a>
        <?php else: ?>
            <a href="/login" class="btn btn-sm">Masuk</a>
        <?php endif; ?>
    </div>
</div>

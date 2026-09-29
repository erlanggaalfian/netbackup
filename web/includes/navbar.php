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

    <nav class="navbar-menu" aria-label="Navigasi utama">
        <a href="/dashboard" class="nav-link <?php echo $requestUri === '/dashboard' ? 'active' : ''; ?>">Dashboard</a>
        <a href="/devices" class="nav-link <?php echo in_array($requestUri, ['/devices', '/edit_device', '/add_device']) ? 'active' : ''; ?>">Perangkat</a>
        <a href="/backups" class="nav-link <?php echo in_array($requestUri, ['/backups', '/view_backup']) ? 'active' : ''; ?>">Backup</a>
        <a href="/schedule" class="nav-link <?php echo $requestUri === '/schedule' ? 'active' : ''; ?>">Jadwal</a>
        
        <?php if ($isAdmin) : ?>
            <div class="nav-separator"></div>
            <a href="/vendors" class="nav-link <?php echo in_array($requestUri, ['/vendors', '/edit_vendor']) ? 'active' : ''; ?>">Vendor</a>
            <a href="/settings" class="nav-link <?php echo $requestUri === '/settings' ? 'active' : ''; ?>">Pengaturan</a>
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

    <button type="button" class="navbar-toggle" aria-label="Buka menu navigasi" aria-expanded="false" aria-controls="navbar-mobile-panel">
        <span class="navbar-toggle-icon"></span>
    </button>
</div>

<div class="navbar-mobile-panel" id="navbar-mobile-panel" aria-hidden="true">
    <a href="/dashboard" class="nav-link <?php echo $requestUri === '/dashboard' ? 'active' : ''; ?>">Dashboard</a>
    <a href="/devices" class="nav-link <?php echo in_array($requestUri, ['/devices', '/edit_device', '/add_device']) ? 'active' : ''; ?>">Perangkat</a>
    <a href="/backups" class="nav-link <?php echo in_array($requestUri, ['/backups', '/view_backup']) ? 'active' : ''; ?>">Backup</a>
    <a href="/schedule" class="nav-link <?php echo $requestUri === '/schedule' ? 'active' : ''; ?>">Jadwal</a>

    <?php if ($isAdmin) : ?>
        <div class="nav-separator"></div>
        <a href="/vendors" class="nav-link <?php echo in_array($requestUri, ['/vendors', '/edit_vendor']) ? 'active' : ''; ?>">Vendor</a>
        <a href="/settings" class="nav-link <?php echo $requestUri === '/settings' ? 'active' : ''; ?>">Pengaturan</a>
        <a href="/logs" class="nav-link <?php echo $requestUri === '/logs' ? 'active' : ''; ?>">Log</a>
    <?php endif; ?>

    <div class="navbar-mobile-user">
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

<div class="mobile-bottom-nav">
    <a href="/dashboard" class="mobile-nav-item <?php echo $requestUri === '/dashboard' ? 'active' : ''; ?>">
        <svg class="mobile-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
        <span class="mobile-nav-label">Dashboard</span>
    </a>
    <a href="/devices" class="mobile-nav-item <?php echo in_array($requestUri, ['/devices', '/edit_device', '/add_device']) ? 'active' : ''; ?>">
        <svg class="mobile-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-7 0h.01"></path></svg>
        <span class="mobile-nav-label">Perangkat</span>
    </a>
    <a href="/backups" class="mobile-nav-item <?php echo in_array($requestUri, ['/backups', '/view_backup']) ? 'active' : ''; ?>">
        <svg class="mobile-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path></svg>
        <span class="mobile-nav-label">Backup</span>
    </a>
    <a href="/schedule" class="mobile-nav-item <?php echo $requestUri === '/schedule' ? 'active' : ''; ?>">
        <svg class="mobile-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
        <span class="mobile-nav-label">Jadwal</span>
    </a>
    <button type="button" class="mobile-nav-item mobile-menu-trigger" aria-controls="navbar-mobile-panel" aria-expanded="false">
        <svg class="mobile-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
        <span class="mobile-nav-label">Menu</span>
    </button>
</div>


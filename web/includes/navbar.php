<?php
// ... existing code ...
if (empty($requestUri)) {
    $requestUri = '/dashboard'; // Default ke dashboard jika di root
}

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
            <div class="user-profile">
                <div class="user-avatar-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm2-3a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm4 8c0 1-1 1-1 1H3s-1 0-1-1 1-4 6-4 6 3 6 4Zm-1-.004c-.001-.246-.154-.986-.832-1.664C11.516 10.68 10.289 10 8 10c-2.29 0-3.516.68-4.168 1.332-.678.678-.83 1.418-.832 1.664h10z"></path>
                    </svg>
                </div>
                <span class="user-welcome">Halo, <strong><?php echo htmlspecialchars(current_user()['username']); ?></strong></span>
            </div>
            <a href="/logout" class="btn btn-sm secondary">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                  <path fill-rule="evenodd" d="M10 12.5a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-9a.5.5 0 0 1 .5-.5h8a.5.5 0 0 1 .5.5v2a.5.5 0 0 0 1 0v-2A1.5 1.5 0 0 0 9.5 2h-8A1.5 1.5 0 0 0 0 3.5v9A1.5 1.5 0 0 0 1.5 14h8a1.5 1.5 0 0 0 1.5-1.5v-2a.5.5 0 0 0-1 0v2z"/>
                  <path fill-rule="evenodd" d="M15.854 8.354a.5.5 0 0 0 0-.708l-3-3a.5.5 0 0 0-.708.708L14.293 7.5H5.5a.5.5 0 0 0 0 1h8.793l-2.147 2.146a.5.5 0 0 0 .708.708l3-3z"/>
                </svg>
                <span>Keluar</span>
            </a>
        <?php else: ?>
            <a href="/login" class="btn btn-sm">Masuk</a>
        <?php endif; ?>
    </div>
</div>

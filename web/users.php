<?php
require_once __DIR__ . '/../config/db.php';
require_login();
require_admin();

// --- LOGIKA HAPUS PENGGUNA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    if (verify_csrf(post('csrf'))) {
        $id = (int)post('id');
        $current_user_id = (int)(current_user()['id'] ?? 0);
        
        // Mencegah pengguna menghapus diri sendiri
        if ($id > 0 && $id !== $current_user_id) {
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$id]);
                app_log('user_delete', 'Menghapus pengguna id=' . $id, $current_user_id, $id);
                header("Location: /users?status=deleted");
                exit;
            } catch (PDOException $e) {
                header("Location: /users?error=delete_failed");
                exit;
            }
        } elseif ($id === $current_user_id) {
            // Jika mencoba menghapus diri sendiri, redirect dengan error
            header("Location: /users?error=self_delete");
            exit;
        }
    }
}

// --- LOGIKA FILTER DAN PENCARIAN ---
$search = get('search') ?? '';
$role = get('role') ?? '';

$sql = 'SELECT id, username, role, created_at FROM users WHERE username LIKE :search';
$params = [':search' => '%' . $search . '%'];

if ($role === 'admin' || $role === 'user') {
    $sql .= ' AND role = :role';
    $params[':role'] = $role;
}
$sql .= ' ORDER BY username';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Manajemen Pengguna</h1>
    <p class="page-subtitle">Kelola pengguna sistem dan hak aksesnya.</p>
  </div>
</div>

<?php if (get('error') === 'self_delete'): ?>
<div class="alert error">Anda tidak dapat menghapus akun Anda sendiri.</div>
<?php endif; ?>

<div class="form-container form-container-full-width">
    <form method="get" class="filter-form">
        <input type="text" name="search" placeholder="Cari username..." value="<?php echo htmlspecialchars($search); ?>">
        <select name="role">
            <option value="">Semua Peran</option>
            <option value="admin" <?php if ($role === 'admin') echo 'selected'; ?>>Admin</option>
            <option value="user" <?php if ($role === 'user') echo 'selected'; ?>>User</option>
        </select>
        <button type="submit" class="btn">Filter</button>
        <a href="/users" class="btn secondary">Reset</a>
    </form>
</div>

<div class="table-container">
  <div class="table-header">
    <h2 class="table-title">Daftar Pengguna (<?php echo count($users); ?> ditemukan)</h2>
    <a class="btn btn-sm" href="/add_user">Tambah Pengguna</a>
  </div>
  <table class="modern-table">
    <thead>
      <tr>
        <th>Username</th>
        <th>Role</th>
        <th>Dibuat</th>
        <th>Aksi</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($users)): ?>
          <tr><td colspan="4" class="table-cell-center">Tidak ada pengguna yang cocok dengan kriteria.</td></tr>
      <?php else: ?>
          <?php foreach ($users as $u): ?>
            <tr>
              <td>
                <div class="user-info">
                  <div class="user-avatar">
                    <?php echo htmlspecialchars(strtoupper(substr($u['username'], 0, 1))); ?>
                  </div>
                  <strong><?php echo htmlspecialchars($u['username']); ?></strong>
                </div>
              </td>
              <td>
                <span class="badge <?php echo $u['role'] === 'admin' ? 'blue' : 'gray'; ?>">
                  <?php echo htmlspecialchars(ucfirst($u['role'])); ?>
                </span>
              </td>
              <td>
                <span class="text-muted">
                  <?php echo date('d M Y', strtotime($u['created_at'])); ?>
                </span>
              </td>
              <td>
                <div class="action-buttons">
                  <a class="btn btn-sm secondary" href="/edit_user?id=<?php echo (int)$u['id']; ?>">Edit</a>
                  <?php if ((int)$u['id'] !== (int)(current_user()['id'] ?? 0)): // Jangan tampilkan tombol hapus untuk diri sendiri ?>
                  <form method="POST" action="/users" onsubmit="return confirm('Anda yakin ingin menghapus pengguna ini secara permanen?');" class="inline">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                      <button type="submit" class="btn btn-sm danger" title="Hapus Pengguna">Hapus</button>
                  </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>


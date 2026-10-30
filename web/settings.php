<?php
/**
 * File: web/settings.php
 * Halaman Pengaturan Global: Menggabungkan Manajemen Pengguna Sistem (Atas)
 * dan Konfigurasi Telegram Bot via Database sys_settings (Bawah) secara Vertikal.
 * Menerapkan penyamaran setengah (partial masking) pada Token dan Chat ID demi keamanan.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_login();
require_admin();

$error = '';
$success = '';
$test_message = '';
$test_status = null;

// Fungsi untuk menyamarkan nilai setengah (Hanya menampilkan 4 karakter di awal)
function mask_half(string $value): string {
    if (empty($value)) return '';
    $len = strlen($value);
    if ($len <= 4) return str_repeat('*', $len);
    return substr($value, 0, 4) . str_repeat('*', $len - 4);
}

// --- LOGIKA HAPUS PENGGUNA (Manajemen User) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete_user') {
    if (verify_csrf(post('csrf'))) {
        $user_id_del = (int)post('id');
        $current_user_id = (int)(current_user()['id'] ?? 0);
        
        if ($user_id_del > 0 && $user_id_del !== $current_user_id) {
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$user_id_del]);
                app_log('user_delete', 'Menghapus pengguna id=' . $user_id_del, $current_user_id, $user_id_del);
                $success = 'Pengguna berhasil dihapus secara permanen';
            } catch (PDOException $e) {
                app_log('user_delete_fail', 'Gagal menghapus pengguna id=' . $user_id_del . ': ' . $e->getMessage(), $current_user_id, $user_id_del);
                $error = 'Gagal menghapus pengguna dari database';
            }
        } elseif ($user_id_del === $current_user_id) {
            $error = 'Anda tidak dapat menghapus akun Anda sendiri';
        }
    }
}

// --- LOGIKA PROSES FORM SUBMIT TELEGRAM BOT & TEST KONEKSI ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('action'), ['save_telegram', 'test_telegram', 'save_gdrive'], true)) {
    if (!verify_csrf(post('csrf'))) {
        $error = 'Invalid CSRF token';
    } else {
        $action = post('action');

        // Ambil data asli saat ini di database sebagai pembanding masking
        $stmt_check = $pdo->query("SELECT setting_key, setting_value FROM sys_settings WHERE setting_key IN ('telegram_bot_token', 'telegram_chat_id')");
        $old_settings = $stmt_check->fetchAll(PDO::FETCH_KEY_PAIR);
        $old_bot_token = $old_settings['telegram_bot_token'] ?? '';
        $old_chat_id = $old_settings['telegram_chat_id'] ?? '';

        // AKSI A: SIMPAN PERUBAHAN TELEGRAM
        if ($action === 'save_telegram') {
            $new_bot_token = post('bot_token') ?? '';
            $new_chat_id = post('chat_id') ?? '';

            // Jika input mengandung tanda bintang, artinya user tidak mengedit bagian itu, gunakan nilai lama
            if (str_contains($new_bot_token, '*')) {
                $new_bot_token = $old_bot_token;
            }
            if (str_contains($new_chat_id, '*')) {
                $new_chat_id = $old_chat_id;
            }

            try {
                $pdo->beginTransaction();

                $stmt1 = $pdo->prepare('UPDATE sys_settings SET setting_value = ? WHERE setting_key = "telegram_bot_token"');
                $stmt1->execute([$new_bot_token]);

                $stmt2 = $pdo->prepare('UPDATE sys_settings SET setting_value = ? WHERE setting_key = "telegram_chat_id"');
                $stmt2->execute([$new_chat_id]);

                $pdo->commit();

                $success = 'Pengaturan bot telegram berhasil diperbarui';
                app_log('settings_edit', 'Updated telegram bot configuration in database', current_user()['id'] ?? null);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Gagal menyimpan perubahan ke database: ' . $e->getMessage();
            }
        }
        
        // AKSI B: UJI COBA KONEKSI BOT TELEGRAM VIA cURL
        elseif ($action === 'test_telegram') {
            if (empty($old_bot_token) || empty($old_chat_id)) {
                $test_status = false;
                $test_message = 'Gagal: Token atau Chat ID masih kosong di database. Silakan simpan terlebih dahulu.';
            } else {
                $msg_text = "🔹 <b>NetBackup Test Notification</b>\nKoneksi integrasi Telegram Bot berhasil dikonfigurasi! 👍";
                $tg_url = "https://api.telegram.org/bot" . $old_bot_token . "/sendMessage";
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $tg_url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                    'chat_id' => $old_chat_id,
                    'text' => $msg_text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => 'true',
                ]));
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
                curl_setopt($ch, CURLOPT_TIMEOUT, 8);
                
                $res_exec = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($http_code === 200) {
                    $test_status = true;
                    $test_message = 'Koneksi Sukses! Pesan tes berhasil dikirim ke Telegram.';
                } else {
                    $test_status = false;
                    $res_json = json_decode((string)$res_exec, true);
                    $test_message = 'Koneksi Gagal (HTTP ' . $http_code . '): ' . ($res_json['description'] ?? 'Periksa kembali parameter Token & Chat ID.');
                }
            }
        }

        elseif ($action === 'save_gdrive') {
            $folder_id = trim((string)(post('google_drive_folder_id') ?? ''));
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
                );
                $stmt->execute(['google_drive_folder_id', $folder_id]);
                $success = 'Google Drive Folder ID berhasil disimpan';
                app_log('settings_edit', 'Updated google_drive_folder_id', current_user()['id'] ?? null);
            } catch (Throwable $e) {
                $error = 'Gagal menyimpan Folder ID: ' . $e->getMessage();
            }
        }
    }
}

// --- BACA DATA UNTUK DITAMPILKAN ---

// 1. Ambil Pengguna Sistem (Atas)
$search = get('search') ?? '';
$role = get('role') ?? '';
$user_sort = get('sort') ?? 'username';
$user_order = get('order') ?? 'asc';
$allowed_user_sort = ['username', 'role', 'created_at'];
$user_sort_col = in_array($user_sort, $allowed_user_sort, true) ? $user_sort : 'username';
$user_sql_order = (strtolower($user_order) === 'desc') ? 'DESC' : 'ASC';

$sql_users = 'SELECT id, username, role, created_at FROM users WHERE username LIKE :search';
$user_params = [':search' => '%' . $search . '%'];

if ($role === 'admin' || $role === 'user') {
    $sql_users .= ' AND role = :role';
    $user_params[':role'] = $role;
}
$sql_users .= ' ORDER BY ' . $user_sort_col . ' ' . $user_sql_order;

$stmt_users = $pdo->prepare($sql_users);
$stmt_users->execute($user_params);
$users = $stmt_users->fetchAll();

// 2. Ambil Kredensial Telegram (Bawah)
$db_bot_token = '';
$db_chat_id = '';
$db_gdrive_folder = '';
try {
    $stmt_load = $pdo->query("SELECT setting_key, setting_value FROM sys_settings WHERE setting_key IN ('telegram_bot_token', 'telegram_chat_id', 'google_drive_folder_id')");
    $active_settings = $stmt_load->fetchAll(PDO::FETCH_KEY_PAIR);
    $db_bot_token = $active_settings['telegram_bot_token'] ?? '';
    $db_chat_id = $active_settings['telegram_chat_id'] ?? '';
    $db_gdrive_folder = $active_settings['google_drive_folder_id'] ?? '';
} catch (Throwable $e) {
    $error = 'Gagal memuat konfigurasi dari database: ' . $e->getMessage();
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header-container m-item motion-fade-up motion-fade-up">
  <h1 class="page-header m-item-title">Pengaturan Sistem Global</h1>
  <p class="page-subtitle m-item-extra">Kelola hak akses pengguna sistem dan konfigurasi integrasi laporan otomatisasi pencadangan</p>
</div>

<?php if ($success): ?>
  <div class="alert success alert-success-soft">
      💾 <?php echo htmlspecialchars($success); ?>
  </div>
<?php endif; ?>

<?php if ($error): ?>
  <div class="alert error alert-error-spaced">⚠️ <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($test_status === true): ?>
  <div class="alert success" style="background: #e6f4ea; color: #137333; border: 1px solid #ceead6; padding: 12px; border-radius: 6px; margin-bottom: 24px;">
      ✅ <strong>Telegram Test:</strong> <?php echo htmlspecialchars($test_message); ?>
  </div>
<?php elseif ($test_status === false): ?>
  <div class="alert error alert-error-spaced">
      ❌ <strong>Telegram Test:</strong> <?php echo htmlspecialchars($test_message); ?>
  </div>
<?php endif; ?>


<div class="form-container motion-fade-up form-container--filter">
    <?php $user_filter_active = (trim($search ?? '') !== '' ? 1 : 0) + (trim($role ?? '') !== '' ? 1 : 0); ?>
    <button type="button" class="m-mobile-only m-filter-head" data-toggle="filter-settings-users" aria-expanded="false">
        <span>Filter</span>
        <?php if ($user_filter_active > 0): ?><span class="m-filter-head-count"><?= (int)$user_filter_active ?> aktif</span><?php endif; ?>
    </button>
    <form method="get" class="auto-filter-form">
        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($user_sort); ?>">
        <input type="hidden" name="order" value="<?php echo htmlspecialchars($user_order); ?>">
        <div class="filter-grid filter-grid--toolbar-2">
            <div class="field">
                <label for="user-search">Cari pengguna</label>
                <input type="text" id="user-search" name="search" placeholder="Username…" value="<?php echo htmlspecialchars($search); ?>" enterkeyhint="search">
            </div>
            <div class="m-collapsible m-filter-more" id="filter-settings-users">
            <div class="field">
                <label for="user-role">Peran akses</label>
                <select id="user-role" name="role">
                    <option value="">Semua peran</option>
                    <option value="admin" <?php if ($role === 'admin') echo 'selected'; ?>>Administrator</option>
                    <option value="user" <?php if ($role === 'user') echo 'selected'; ?>>Pengguna</option>
                </select>
            </div>
            <div class="filter-grid-actions">
                <?= filter_reset_link('settings.php') ?>
            </div>
            </div>
        </div>
    </form>
</div>

<div class="table-container motion-fade-up table-container-spaced">
  <div class="table-header">
    <h2 class="table-title">Daftar Pengguna Sistem (<?php echo count($users); ?>)</h2>
    <a class="btn" href="add_user.php">Tambah Pengguna</a>
  </div>
  <div class="table-scroll-wrap">
  <table class="modern-table">
    <thead>
      <tr>
        <?php echo table_sort_link('username', 'Username', $user_sort, $user_order); ?>
        <?php echo table_sort_link('role', 'Role', $user_sort, $user_order); ?>
        <?php echo table_sort_link('created_at', 'Dibuat', $user_sort, $user_order); ?>
        <th class="th-actions">Aksi</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($users)): ?>
          <tr><td colspan="4" class="table-cell-center">Tidak ada pengguna yang cocok dengan kriteria.</td></tr>
      <?php else: ?>
          <?php foreach ($users as $u): ?>
            <tr>
              <td class="td-primary" data-label="Username">
                <div class="user-info">
                  <div class="user-avatar">
                    <?php echo htmlspecialchars(strtoupper(substr($u['username'], 0, 1))); ?>
                  </div>
                  <strong><?php echo htmlspecialchars($u['username']); ?></strong>
                </div>
                <span class="m-collapsed-extra">
                  <span class="badge <?php echo $u['role'] === 'admin' ? 'blue' : 'gray'; ?>">
                    <?php echo htmlspecialchars(ucfirst($u['role'])); ?>
                  </span>
                </span>
              </td>
              <td data-label="Role">
                <span class="badge <?php echo $u['role'] === 'admin' ? 'blue' : 'gray'; ?>">
                  <?php echo htmlspecialchars(ucfirst($u['role'])); ?>
                </span>
              </td>
              <td data-label="Dibuat">
                <span class="small-text-muted">
                  <?php echo date('d M Y', strtotime($u['created_at'])); ?>
                </span>
              </td>
              <td class="td-actions" data-label="Aksi">
                <div class="action-buttons">
                  <a class="btn btn-sm secondary" href="edit_user.php?id=<?php echo (int)$u['id']; ?>">Edit</a>
                  <?php if ((int)$u['id'] !== (int)(current_user()['id'] ?? 0)): ?>
                  <form method="POST" onsubmit="return mConfirmSubmit(event, this, 'Anda yakin ingin menghapus pengguna ini secara permanen?', 'Hapus');" class="inline">
                      <input type="hidden" name="action" value="delete_user">
                      <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                      <button type="submit" class="btn btn-sm danger">Hapus</button>
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
</div>


<div class="form-container motion-fade-up form-container-full-width">
  <h2 class="form-title">Konfigurasi Telegram Bot</h2>
  
  <form method="post">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="action" value="save_telegram">
    
    <div class="form-grid">
      <div class="field">
        <label>Bot Token Telegram *</label>
        <input name="bot_token" value="<?php echo htmlspecialchars(mask_half($db_bot_token)); ?>" required placeholder="Masukkan bot token dari BotFather" onfocus="if(this.value.includes('***')) this.value='';">
        <small class="help-text-xs">Disamarkan separuh demi keamanan. Klik & timpa langsung untuk mengubah data.</small>
      </div>
      <div class="field">
        <label>Telegram Chat ID / Group ID *</label>
        <input name="chat_id" value="<?php echo htmlspecialchars(mask_half($db_chat_id)); ?>" required placeholder="Masukkan Chat ID atau Group ID target laporan">
        <small class="help-text-xs">ID disamarkan separuh. Biarkan atau ketik baru jika ada pembaruan.</small>
      </div>
    </div>
    
    <div class="action-buttons mt-3">
      <button class="btn btn-lg" type="submit">
        Simpan Perubahan Telegram
      </button>
      <a href="dashboard.php" class="btn btn-lg secondary">Batal</a>
    </div>
  </form>

  <form method="post" class="form-divider-top">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="action" value="test_telegram">
      <button type="submit" class="btn secondary">
          Uji Coba Kirim Notifikasi Telegram
      </button>
  </form>
</div>

<div class="form-container motion-fade-up form-container-full-width">
  <h2 class="form-title">Google Drive — Backup Database</h2>
  <p class="help-text-xs mb-2">Folder ID dari URL Google Drive. Butuh <code>config/client_secrets.json</code> dan OAuth (<a href="Otomatisasi_Backup_Database_ke_Google_Drive.md">panduan</a>).</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="action" value="save_gdrive">
    <div class="field">
      <label>Google Drive Folder ID</label>
      <input name="google_drive_folder_id" value="<?php echo htmlspecialchars($db_gdrive_folder); ?>" placeholder="Contoh: 1A2B3C4D5E6F7G8H9I0J">
    </div>
    <div class="action-buttons mt-3">
      <button class="btn btn-lg" type="submit">Simpan Folder ID</button>
    </div>
  </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
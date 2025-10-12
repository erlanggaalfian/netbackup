<?php
require_once __DIR__ . '/../config/db.php';
require_login();

$is_admin = (current_user()['role'] ?? '') === 'admin';

// --- LOGIKA HAPUS BACKUP ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    if ($is_admin && verify_csrf(post('csrf'))) {
        $backup_id = (int)(post('backup_id') ?? 0);
        if ($backup_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM backups WHERE id = ?");
            $stmt->execute([$backup_id]);
            app_log('backup_delete', 'Menghapus riwayat backup id=' . $backup_id, current_user()['id'], $backup_id);
            header("Location: /backups?status=deleted");
            exit;
        }
    }
}

// --- LOGIKA BULK ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'bulk_action') {
    if ($is_admin && verify_csrf(post('csrf'))) {
        $bulk_action = post('bulk_action_type');
        $selected_backups = $_POST['selected_backups'] ?? [];
        
        if (!empty($selected_backups) && in_array($bulk_action, ['delete', 'download'])) {
            $ids = array_map('intval', $selected_backups);
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';

            if ($bulk_action === 'delete') {
                $stmt = $pdo->prepare("DELETE FROM backups WHERE id IN ($placeholders)");
                $stmt->execute($ids);
                app_log('backup_bulk_delete', 'Menghapus ' . count($ids) . ' riwayat backup', current_user()['id']);
                header("Location: /backups?status=bulk_deleted&count=" . count($ids));
                exit;
            } elseif ($bulk_action === 'download' && class_exists('ZipArchive')) {
                $stmt = $pdo->prepare("SELECT filename, config FROM backups WHERE id IN ($placeholders)");
                $stmt->execute($ids);
                $backups_to_zip = $stmt->fetchAll();
                
                $zip_filename = 'netbackup_' . date('Ymd_His') . '.zip';
                $zip_path = sys_get_temp_dir() . '/' . $zip_filename;
                
                $zip = new ZipArchive();
                if ($zip->open($zip_path, ZipArchive::CREATE) === TRUE) {
                    foreach ($backups_to_zip as $backup) {
                        $zip->addFromString($backup['filename'], $backup['config']);
                    }
                    $zip->close();
                    
                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
                    header('Content-Length: ' . filesize($zip_path));
                    readfile($zip_path);
                    unlink($zip_path);
                    exit;
                }
            }
        }
    }
}

// --- LOGIKA FILTER, SORT, DAN LIMIT ---
$search = get('search') ?? '';
$device_id = (int)(get('device_id') ?? 0);
$sort_by = get('sort') ?? 'timestamp';
$order = get('order') ?? 'desc';
$limit = in_array((int)(get('limit') ?? 50), [25, 50, 100, 200, 500]) ? (int)(get('limit') ?? 50) : 50;

$allowed_sort = ['timestamp', 'device_name', 'status'];
$allowed_order = ['asc', 'desc'];
if (!in_array($sort_by, $allowed_sort)) $sort_by = 'timestamp';
if (!in_array($order, $allowed_order)) $order = 'desc';
$order_by_clause = "ORDER BY {$sort_by} {$order}";

$sql = "SELECT b.*, d.name AS device_name, d.ip 
        FROM backups b 
        JOIN devices d ON d.id=b.device_id 
        WHERE (b.filename LIKE ? OR d.name LIKE ? OR d.ip LIKE ?)";
$params = ['%' . $search . '%', '%' . $search . '%', '%' . $search . '%'];

if ($device_id > 0) {
    $sql .= ' AND b.device_id = ?';
    $params[] = $device_id;
}
$sql .= " {$order_by_clause} LIMIT " . $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Helper function untuk generate link sorting
function sort_link($column, $text, $current_sort, $current_order) {
    $order = ($current_sort === $column && $current_order === 'asc') ? 'desc' : 'asc';
    $params = http_build_query(array_merge($_GET, ['sort' => $column, 'order' => $order]));
    $class = ($current_sort === $column) ? "sortable active {$current_order}" : "sortable";
    return "<th class=\"{$class}\"><a href=\"?{$params}\">{$text}<span class=\"sort-arrow\"></span></a></th>";
}

$devices = $pdo->query("SELECT id, name FROM devices ORDER BY name")->fetchAll();
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1 class="page-title">Riwayat Backup</h1>
  <p class="page-subtitle">Cari, urutkan, dan kelola semua riwayat backup perangkat.</p>
</div>

<div class="form-container" style="max-width: none; margin-bottom: 24px;">
    <form method="get">
        <div class="filter-form" style="display: flex; gap: 12px; align-items: flex-end;">
            <div class="field" style="flex-grow: 1;">
                <label>Pencarian</label>
                <input type="text" name="search" placeholder="Cari nama file, perangkat, atau IP..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="field" style="flex-grow: 1;">
                <label>Perangkat</label>
                <select name="device_id">
                    <option value="">Semua Perangkat</option>
                    <?php foreach ($devices as $device): ?>
                        <option value="<?php echo $device['id']; ?>" <?php if ($device['id'] == $device_id) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($device['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Limit</label>
                <select name="limit">
                    <option value="25" <?php if ($limit === 25) echo 'selected'; ?>>25</option>
                    <option value="50" <?php if ($limit === 50) echo 'selected'; ?>>50</option>
                    <option value="100" <?php if ($limit === 100) echo 'selected'; ?>>100</option>
                    <option value="200" <?php if ($limit === 200) echo 'selected'; ?>>200</option>
                    <option value="500" <?php if ($limit === 500) echo 'selected'; ?>>500</option>
                </select>
            </div>
        </div>
        <div class="action-buttons" style="margin-top: 12px;">
            <button type="submit" class="btn">Filter</button>
            <a href="/backups" class="btn secondary">Reset</a>  
        </div>
    </form>
</div>

<div class="table-container">
  <div class="table-header">
    <h2 class="table-title">Riwayat (<?php echo count($rows); ?> ditemukan)</h2>
    <?php if ($is_admin): ?>
    <div class="bulk-actions">
      <button onclick="bulkAction('delete')" class="btn btn-sm danger" disabled id="bulk-delete-btn">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/><path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/></svg>
        <span>Hapus</span>
      </button>
      <button onclick="bulkAction('download')" class="btn btn-sm" disabled id="bulk-download-btn">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/><path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/></svg>
        <span>Download</span>
      </button>
    </div>
    <?php endif; ?>
  </div>
  <form id="bulk-form" method="post">
    <input type="hidden" name="action" value="bulk_action">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="bulk_action_type" id="bulk-action-type">
    <!-- Checkbox values will be appended here by JS -->
  </form>
  
  <?php if (empty($rows)): ?>
    <div style="text-align: center; padding: 64px 32px;">
        <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#e5e7eb" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path>
            <polyline points="13 2 13 9 20 9"></polyline>
        </svg>
        <h3 style="margin-top: 16px; font-size: 18px; color: var(--color-text-header);">Tidak Ada Riwayat Ditemukan</h3>
        <p style="color: var(--color-text-muted); max-width: 400px; margin: 8px auto 0;">Tidak ada data yang cocok dengan kriteria filter Anda. Coba reset filter untuk melihat semua riwayat.</p>
    </div>
  <?php else: ?>
    <table class="modern-table">
        <thead>
        <tr>
            <?php if ($is_admin): ?><th width="40"><input type="checkbox" id="select-all-checkbox" title="Pilih Semua"></th><?php endif; ?>
            <?php echo sort_link('timestamp', 'Waktu', $sort_by, $order); ?>
            <?php echo sort_link('device_name', 'Perangkat', $sort_by, $order); ?>
            <?php echo sort_link('status', 'Status', $sort_by, $order); ?>
            <th>File</th>
            <th>Aksi</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
            <?php if ($is_admin): ?>
            <td><input type="checkbox" class="backup-checkbox" name="selected_backups[]" value="<?php echo (int)$r['id']; ?>"></td>
            <?php endif; ?>
            <td><span style="color: var(--color-text-muted); font-size: 14px;"><?php echo date('d M Y, H:i', strtotime($r['timestamp'])); ?></span></td>
            <td>
                <strong><?php echo htmlspecialchars($r['device_name']); ?></strong><br>
                <small style="color: var(--color-text-muted);"><?php echo htmlspecialchars($r['ip']); ?></small>
            </td>
            <td>
                <span class="badge <?php echo $r['status']==='success' ? 'success' : 'danger'; ?>">
                    <?php echo $r['status']==='success' ? 'Berhasil' : 'Gagal'; ?>
                </span>
            </td>
            <td>
                <code style="font-family: monospace; background-color: #f3f4f6; padding: 2px 6px; border-radius: 4px; font-size: 13px; color: #4b5563;">
                    <?php echo htmlspecialchars($r['filename']); ?>
                </code>
            </td>
            <td>
                <div class="action-buttons">
                    <a class="btn btn-sm secondary" href="/view_backup?id=<?php echo (int)$r['id']; ?>">Lihat</a>
                    <?php if ($r['status'] === 'success'): ?>
                        <a class="btn btn-sm" href="/download?id=<?php echo (int)$r['id']; ?>">Download</a>
                    <?php endif; ?>
                    
                    <?php if ($is_admin): ?>
                    <form method="POST" onsubmit="return confirm('Anda yakin ingin menghapus backup ini?');" style="display: inline;">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="backup_id" value="<?php echo (int)$r['id']; ?>">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <button type="submit" class="btn btn-sm danger" title="Hapus Backup">Hapus</button>
                    </form>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
  <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('select-all-checkbox');
    const itemCheckboxes = document.querySelectorAll('.backup-checkbox');
    const deleteBtn = document.getElementById('bulk-delete-btn');
    const downloadBtn = document.getElementById('bulk-download-btn');

    function updateBulkButtons() {
        const anyChecked = document.querySelectorAll('.backup-checkbox:checked').length > 0;
        deleteBtn.disabled = !anyChecked;
        downloadBtn.disabled = !anyChecked;
    }

    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            itemCheckboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });
            updateBulkButtons();
        });
    }

    itemCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            if (!this.checked) {
                if(selectAllCheckbox) selectAllCheckbox.checked = false;
            }
            updateBulkButtons();
        });
    });

    // Initial check
    updateBulkButtons();
});

function bulkAction(action) {
    const form = document.getElementById('bulk-form');
    // Clear previous selections from the form
    form.innerHTML = '';
    
    // Add back the static hidden inputs
    form.insertAdjacentHTML('beforeend', `
        <input type="hidden" name="action" value="bulk_action">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="bulk_action_type" id="bulk-action-type">
    `);

    const checkedCheckboxes = document.querySelectorAll('.backup-checkbox:checked');
    
    if (checkedCheckboxes.length === 0) {
        alert('Pilih minimal satu backup untuk melakukan aksi ini.');
        return;
    }
    
    const actionText = action === 'delete' ? 'menghapus' : 'mendownload';
    if (!confirm(`Anda yakin ingin ${actionText} ${checkedCheckboxes.length} backup yang dipilih?`)) {
        return;
    }
    
    document.getElementById('bulk-action-type').value = action;
    
    // Append checked checkboxes to the form for submission
    checkedCheckboxes.forEach(checkbox => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_backups[]';
        input.value = checkbox.value;
        form.appendChild(input);
    });
    
    form.submit();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

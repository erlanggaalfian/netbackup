<?php
/**
 * File: web/backups.php
 * Halaman untuk mengelola riwayat backup.
 * Versi Final: Struktur Modular + UI Tanpa Ikon + Performa Tinggi.
 */
require_once __DIR__ . '/../config/db.php';
require_login();

// --- LOGIKA HAPUS SATU BACKUP ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    if (verify_csrf(post('csrf'))) {
        $backup_id = (int)(post('backup_id') ?? 0);
        if ($backup_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM backups WHERE id = ?");
            $stmt->execute([$backup_id]);
            if (function_exists('app_log')) {
                app_log('backup_delete', 'Menghapus riwayat backup id=' . $backup_id, current_user()['id'] ?? null, $backup_id);
            }
            header("Location: backups.php?status=deleted");
            exit;
        }
    }
}

// --- LOGIKA AKSI MASSAL (BULK ACTIONS) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'bulk_action') {
    if (verify_csrf(post('csrf'))) {
        $bulk_action = post('bulk_action_type');
        $selected_backups = $_POST['selected_backups'] ?? [];
        
        if (!empty($selected_backups) && in_array($bulk_action, ['delete', 'download'])) {
            $ids = array_map('intval', $selected_backups);
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';

            if ($bulk_action === 'delete') {
                $stmt = $pdo->prepare("DELETE FROM backups WHERE id IN ($placeholders)");
                $stmt->execute($ids);
                if (function_exists('app_log')) {
                    app_log('backup_bulk_delete', 'Menghapus ' . count($ids) . ' riwayat backup', current_user()['id'] ?? null);
                }
                header("Location: backups.php?status=bulk_deleted&count=" . count($ids));
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

// --- LOGIKA FILTER, PENGURUTAN, DAN LIMIT (dioptimasi ribuan baris) ---
$filter_type = $_GET['type'] ?? '';
$filter_id = (int)($_GET['id'] ?? 0);
// Tanpa filter site/tipe → langsung tampilkan semua data (pagination tetap per limit)
$view_all = ($filter_id <= 0);
$search = trim(get('search') ?? '');
$search_active = (strlen($search) >= 2);
$sort_by = get('sort') ?? 'timestamp';
$order = get('order') ?? 'desc';
$allowed_limits = [25, 50, 100];
$limit = in_array((int)(get('limit') ?? 25), $allowed_limits, true) ? (int)(get('limit') ?? 25) : 25;

$page = max(1, (int)(get('page') ?? 1));
$max_offset = 5000;
$offset = min(($page - 1) * $limit, $max_offset);

// Hitung ringan: jumlah perangkat per site/role (bukan JOIN ke seluruh tabel backups)
$all_sites = $pdo->query(
    'SELECT s.id, s.name, COUNT(d.id) AS item_count
     FROM sites s
     LEFT JOIN devices d ON d.site_id = s.id
     GROUP BY s.id, s.name
     ORDER BY s.name'
)->fetchAll();
$all_roles = $pdo->query(
    'SELECT r.id, r.name, COUNT(d.id) AS item_count
     FROM roles r
     LEFT JOIN devices d ON d.role_id = r.id
     GROUP BY r.id, r.name
     ORDER BY r.name'
)->fetchAll();

// Mapping kolom sorting (hanya dipakai saat bukan mode ringan)
$allowed_sort = ['timestamp', 'device_name', 'filename', 'status'];
$sort_col = in_array($sort_by, $allowed_sort, true) ? $sort_by : 'timestamp';

// Inisialisasi variabel
$backups = [];
$total_rows = 0;
$total_pages = 0;
$view_title = "Riwayat Backup";
$last_page = 1;

// Kondisi WHERE — tanpa LIKE '%%' agar index timestamp terpakai
$where_clauses = ['1=1'];
$params = [];

if ($search_active) {
    $like = '%' . $search . '%';
    $where_clauses[] = '(d.name LIKE ? OR b.filename LIKE ? OR d.ip LIKE ?)';
    array_push($params, $like, $like, $like);
}

if (!$view_all && $filter_id > 0) {
    if ($filter_type === 'site') {
        $where_clauses[] = 'd.site_id = ?';
        $params[] = $filter_id;
        foreach ($all_sites as $s) {
            if ((int)$s['id'] === $filter_id) {
                $view_title = 'Backup Site: ' . $s['name'];
            }
        }
    } elseif ($filter_type === 'role') {
        $where_clauses[] = 'd.role_id = ?';
        $params[] = $filter_id;
        foreach ($all_roles as $r) {
            if ((int)$r['id'] === $filter_id) {
                $view_title = 'Backup Tipe: ' . $r['name'];
            }
        }
    }
} elseif ($view_all) {
    $view_title = 'Semua Riwayat Backup';
}

$where_sql = 'WHERE ' . implode(' AND ', $where_clauses);

$count_stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM backups b INNER JOIN devices d ON b.device_id = d.id ' . $where_sql
);
$count_stmt->execute($params);
$total_rows = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $limit));
$last_page = min($total_pages, (int)floor($max_offset / $limit) + 1);
if ($page > $last_page) {
    $page = $last_page;
}
$offset = min(($page - 1) * $limit, $max_offset);

// Urut — selalu aktif (index DB mendukung kolom status & timestamp)
$allowed_sort = ['timestamp', 'device_name', 'filename', 'status'];
$sort_col = in_array($sort_by, $allowed_sort, true) ? $sort_by : 'timestamp';
$sql_sort = ($sort_col === 'device_name') ? 'd.name' : "b.$sort_col";
$sql_order = ($order === 'asc') ? 'ASC' : 'DESC';
$order_sql = "$sql_sort $sql_order, b.id DESC";

$sql = "SELECT b.id, b.filename, b.timestamp, b.status, d.name AS device_name, d.ip
        FROM backups b
        INNER JOIN devices d ON b.device_id = d.id
        $where_sql
        ORDER BY $order_sql
        LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$backups = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header-container motion-fade-up motion-fade-up">
    <div class="page-header-row">
        <div>
            <h1 class="page-header"><?= htmlspecialchars($view_title) ?></h1>
            <p class="page-subtitle">Pusat data backup jaringan yang tersimpan di server secara aman.</p>
        </div>
        <div class="page-actions">
            <a href="devices.php" class="btn secondary">Ke Perangkat</a>
        </div>
    </div>
</div>

<div class="form-container motion-fade-up form-container--filter">
    <?php $filter_active_count = (isset($view_all) && !$view_all ? 1 : 0) + (trim($search ?? '') !== '' ? 1 : 0); ?>
    <button type="button" class="m-mobile-only m-filter-head" data-toggle="filter-backups-scope filter-backups-main" aria-expanded="false">
        <span>Filter</span>
        <?php if ($filter_active_count > 0): ?><span class="m-filter-head-count"><?= (int)$filter_active_count ?> aktif</span><?php endif; ?>
    </button>
    <div class="m-collapsible m-filter-more" id="filter-backups-scope">
    <div class="filter-grid filter-grid--scope">
        <div class="field">
            <label>Site</label>
            <select onchange="if(this.value){ window.location.href=this.value; }">
                <option value="backups.php" <?= $view_all ? 'selected' : '' ?>>Semua site</option>
                <?php foreach ($all_sites as $s): ?>
                    <option value="backups.php?type=site&id=<?= $s['id'] ?>" <?= ($filter_type == 'site' && $filter_id == $s['id'] && !$view_all) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['name']) ?> (<?= (int)$s['item_count'] ?> Perangkat)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Tipe perangkat</label>
            <select onchange="if(this.value){ window.location.href=this.value; }">
                <option value="backups.php" <?= $view_all ? 'selected' : '' ?>>Semua tipe</option>
                <?php foreach ($all_roles as $r): ?>
                    <option value="backups.php?type=role&id=<?= $r['id'] ?>" <?= ($filter_type == 'role' && $filter_id == $r['id'] && !$view_all) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($r['name']) ?> (<?= (int)$r['item_count'] ?> Perangkat)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <hr class="filter-divider">
    </div>
    <form method="get" class="auto-filter-form" data-debounce="700" data-min-chars="2">
        <?php if (!$view_all): ?>
            <input type="hidden" name="type" value="<?= htmlspecialchars($filter_type) ?>">
            <input type="hidden" name="id" value="<?= $filter_id ?>">
        <?php endif; ?>
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort_by) ?>">
        <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
        <input type="hidden" name="page" value="<?= (int)$page ?>">
        <div class="filter-grid filter-grid--toolbar">
            <div class="field">
                <label for="backup-search">Cari riwayat</label>
                <input type="text" id="backup-search" name="search" enterkeyhint="search" placeholder="Nama file, perangkat, atau IP…" value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="m-collapsible m-filter-more" id="filter-backups-main">
            <div class="field">
                <label for="backup-limit">Baris per halaman</label>
                <select id="backup-limit" name="limit">
                    <?= limit_select_options($allowed_limits, $limit) ?>
                </select>
            </div>
            <div class="filter-grid-actions">
                <?= filter_reset_link($view_all ? 'backups.php' : "backups.php?type=$filter_type&id=$filter_id") ?>
            </div>
            </div>
        </div>
    </form>
</div>

    <div class="table-container motion-fade-up motion-fade-up">
        <div class="table-header">
            <h2 class="table-title">Riwayat Berkas <br><p class="page-subtitle">(
                Menampilkan <?= count($backups) ?> dari <?= number_format($total_rows) ?> data
            )</p></h2>
            <div class="bulk-actions">
                <button type="button" class="btn btn-sm secondary m-mobile-only" id="mobile-select-all-btn">Pilih</button>
                <button onclick="bulkAction('delete')" class="btn btn-sm danger" disabled id="bulk-delete-btn">
                    <span class="m-desktop-only">Hapus Terpilih</span><span class="m-mobile-only">Hapus</span>
                </button>
                <button onclick="bulkAction('download')" class="btn btn-sm" disabled id="bulk-download-btn">
                    <span class="m-desktop-only">Download Terpilih</span><span class="m-mobile-only">Unduh</span>
                </button>
            </div>
        </div>

        <form id="bulk-form" method="post">
            <input type="hidden" name="action" value="bulk_action">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="bulk_action_type" id="bulk-action-type">
        </form>

        <div class="table-scroll-wrap">
        <table class="modern-table">
            <thead>
                <tr>
                    <th width="40"><input type="checkbox" id="select-all-checkbox"></th>
                    <?= table_sort_link('timestamp', 'Waktu', $sort_by, $order) ?>
                    <?= table_sort_link('device_name', 'Perangkat', $sort_by, $order) ?>
                    <?= table_sort_link('status', 'Status', $sort_by, $order) ?>
                    <?= table_sort_link('filename', 'Nama File', $sort_by, $order) ?>
                    <th style="text-align: right; width: 220px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($backups)): ?>
                    <tr><td colspan="6" class="table-cell-center">Tidak ada riwayat backup ditemukan.</td></tr>
                <?php else: ?>
                    <?php foreach ($backups as $b): ?>
                        <tr>
                            <td class="td-check" data-label="Pilih"><input type="checkbox" class="backup-checkbox" name="selected_backups[]" value="<?= (int)$b['id'] ?>"></td>
                            <td data-label="Waktu"><span class="text-muted-sm"><?= date('d M Y, H:i', strtotime($b['timestamp'])) ?></span></td>
                            <td class="td-primary" data-label="Perangkat"><strong><?= htmlspecialchars($b['device_name']) ?></strong><br><small class="small-text-muted"><?= htmlspecialchars($b['ip']) ?></small><br><span class="m-collapsed-extra"><small class="small-text-muted"><?= date('d M Y, H:i', strtotime($b['timestamp'])) ?> · </small><span class="badge <?= $b['status']==='success' ? 'success' : 'danger' ?>"><?= $b['status']==='success' ? 'Berhasil' : 'Gagal' ?></span></span></td>
                            <td data-label="Status"><span class="badge <?= $b['status']==='success' ? 'success' : 'danger' ?>"><?= $b['status']==='success' ? 'Berhasil' : 'Gagal' ?></span></td>
                            <td class="td-mono" data-label="Nama File"><?= htmlspecialchars($b['filename']) ?></td>
                            <td class="td-actions" data-label="Aksi">
                                <div class="action-buttons">
                                    <a class="btn btn-sm secondary" href="view_backup.php?id=<?= (int)$b['id'] ?>">Lihat</a>
                                    <?php if ($b['status'] === 'success'): ?>
                                        <a class="btn btn-sm" href="download.php?id=<?= (int)$b['id'] ?>">Download</a>
                                    <?php endif; ?>
                                    <form method="POST" onsubmit="return mConfirmSubmit(event, this, 'Hapus backup ini?', 'Hapus');" class="inline">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="backup_id" value="<?= (int)$b['id'] ?>">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
                                        <button type="submit" class="btn btn-sm danger">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <?php
    $build_url = function ($p) use ($search, $search_active, $filter_type, $filter_id, $limit, $sort_by, $order, $view_all) {
        $q = ['page' => $p, 'limit' => $limit, 'sort' => $sort_by, 'order' => $order];
        if ($search_active) {
            $q['search'] = $search;
        }
        if (!$view_all) {
            $q['type'] = $filter_type;
            $q['id'] = $filter_id;
        }
        return '?' . http_build_query($q);
    };
    render_pagination(
        (int)$page,
        (int)$total_pages,
        $build_url,
        ['last_page' => (int)$last_page]
    );
?>

<script>
// Fallback until footer.php exposes the bottom-sheet confirm (parallel agent);
// the footer's window.mConfirmSubmit / window.mConfirm override these when loaded.
if (typeof window.mConfirmSubmit !== 'function') {
    window.mConfirmSubmit = function (e, form, message /*, confirmText */) {
        if (!window.confirm(message)) { if (e) e.preventDefault(); return false; }
        return true;
    };
}
function mConfirmAsync(opts) {
    if (typeof window.mConfirm === 'function') return window.mConfirm(opts);
    return Promise.resolve(window.confirm(opts && opts.message ? opts.message : 'Lanjutkan?'));
}

document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('select-all-checkbox');
    const itemCheckboxes = document.querySelectorAll('.backup-checkbox');
    const deleteBtn = document.getElementById('bulk-delete-btn');
    const downloadBtn = document.getElementById('bulk-download-btn');
    const mobileSelectAllBtn = document.getElementById('mobile-select-all-btn');

    function updateBulkButtons() {
        const boxes = document.querySelectorAll('.backup-checkbox');
        const checkedCount = document.querySelectorAll('.backup-checkbox:checked').length;
        const anyChecked = checkedCount > 0;
        if (deleteBtn) deleteBtn.disabled = !anyChecked;
        if (downloadBtn) downloadBtn.disabled = !anyChecked;
        if (mobileSelectAllBtn) mobileSelectAllBtn.textContent = (boxes.length > 0 && checkedCount === boxes.length) ? 'Batal' : 'Pilih';
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            itemCheckboxes.forEach(cb => cb.checked = selectAll.checked);
            updateBulkButtons();
        });
    }

    itemCheckboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            if (!this.checked && selectAll) selectAll.checked = false;
            updateBulkButtons();
        });
    });

    if (mobileSelectAllBtn) {
        mobileSelectAllBtn.addEventListener('click', function() {
            const boxes = document.querySelectorAll('.backup-checkbox');
            const allChecked = boxes.length > 0 && Array.from(boxes).every(function(cb){ return cb.checked; });
            boxes.forEach(function(cb){ cb.checked = !allChecked; });
            if (selectAll) selectAll.checked = !allChecked;
            updateBulkButtons();
        });
    }
});

function bulkAction(action) {
    const checked = document.querySelectorAll('.backup-checkbox:checked');
    if (!checked.length) return;
    const submitBulk = function() {
        const form = document.getElementById('bulk-form');
        document.getElementById('bulk-action-type').value = action;

        checked.forEach(cb => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_backups[]';
            input.value = cb.value;
            form.appendChild(input);
        });
        form.submit();
    };
    if (action === 'delete') {
        mConfirmAsync({ message: `Anda yakin ingin menghapus ${checked.length} backup terpilih?`, confirmText: 'Hapus', danger: true })
            .then(function(ok){ if (ok) submitBulk(); });
        return;
    }
    submitBulk();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
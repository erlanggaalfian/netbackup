<?php
require_once __DIR__ . '/../config/db.php';
require_login();

// --- LOGIKA HAPUS PERANGKAT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    if (verify_csrf(post('csrf'))) {
        $id = (int)post('id');
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM devices WHERE id = ?");
            $stmt->execute([$id]);
            if (function_exists('app_log')) {
                app_log('device_delete', 'Menghapus perangkat id=' . $id, current_user()['id'] ?? null, $id);
            }
            header("Location: devices.php?status=deleted");
            exit;
        }
    }
}

// --- LOGIKA AKSI MASSAL (BULK ACTIONS) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'bulk_action') {
    if (verify_csrf(post('csrf'))) {
        $bulk_action = post('bulk_action_type');
        $selected_ids = $_POST['selected_ids'] ?? [];
        
        if (!empty($selected_ids) && $bulk_action === 'delete') {
            $ids = array_map('intval', $selected_ids);
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            
            $stmt = $pdo->prepare("DELETE FROM devices WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            
            if (function_exists('app_log')) {
                app_log('device_bulk_delete', 'Menghapus ' . count($ids) . ' perangkat', current_user()['id'] ?? null);
            }
            header("Location: devices.php?status=bulk_deleted&count=" . count($ids));
            exit;
        }
    }
}

// Ambil parameter filter dan sorting dari URL
$filter_type = $_GET['type'] ?? ''; 
$filter_id = (int)($_GET['id'] ?? 0);
// Tanpa filter site/tipe → langsung tampilkan semua data (pagination tetap per limit)
$view_all = ($filter_id <= 0);
$search = get('search') ?? '';
$allowed_limits = [25, 50, 100, 200];
$limit = in_array((int)(get('limit') ?? 25), $allowed_limits, true) ? (int)(get('limit') ?? 25) : 25;
$sort = get('sort') ?? 'name'; 
$order = get('order') ?? 'asc';
$page = max(1, (int)(get('page') ?? 1));
$offset = ($page - 1) * $limit;

// Mapping sorting
$allowed_sort = ['name', 'ip', 'vendor', 'location'];
$sort_col = in_array($sort, $allowed_sort) ? $sort : 'name';
$sql_sort = ($sort_col === 'vendor') ? 'vendor_name' : "d.$sort_col";
$sql_order = ($order === 'desc') ? 'DESC' : 'ASC';

// Ambil daftar semua Site dan Role untuk menu utama
$all_sites = $pdo->query('SELECT s.*, COUNT(d.id) as device_count FROM sites s LEFT JOIN devices d ON s.id = d.site_id GROUP BY s.id ORDER BY s.name')->fetchAll();
$all_roles = $pdo->query('SELECT r.*, COUNT(d.id) as device_count FROM roles r LEFT JOIN devices d ON r.id = d.role_id GROUP BY r.id ORDER BY r.name')->fetchAll();

// Inisialisasi variabel
$devices = [];
$total_rows = 0;
$total_pages = 0;
$view_title = "Manajemen Perangkat";

$where_clauses = ["(d.name LIKE ? OR d.ip LIKE ? OR d.location LIKE ?)"];
$params = ['%' . $search . '%', '%' . $search . '%', '%' . $search . '%'];

if ($view_all) {
    $view_title = "Semua Perangkat";
} elseif ($filter_type === 'site') {
    $where_clauses[] = "d.site_id = ?";
    $params[] = $filter_id;
    foreach($all_sites as $s) if($s['id'] == $filter_id) $view_title = "Site: " . $s['name'];
} elseif ($filter_type === 'role') {
    $where_clauses[] = "d.role_id = ?";
    $params[] = $filter_id;
    foreach($all_roles as $r) if($r['id'] == $filter_id) $view_title = "Tipe: " . $r['name'];
}

$where_sql = "WHERE " . implode(" AND ", $where_clauses);

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM devices d JOIN vendor v ON d.vendor_id = v.id $where_sql");
$count_stmt->execute($params);
$total_rows = $count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $limit));
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $limit;

$sql = "SELECT d.*, v.name as vendor_name 
        FROM devices d 
        LEFT JOIN vendor v ON d.vendor_id = v.id 
        $where_sql 
        ORDER BY $sql_sort $sql_order LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$devices = $stmt->fetchAll();

$connectivity_snapshot = get_device_connectivity_snapshot(true);
$connectivity_by_id = is_array($connectivity_snapshot['devices'] ?? null) ? $connectivity_snapshot['devices'] : [];

$page_body_class = 'page-heavy';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header-container motion-fade-up motion-fade-up">
    <div class="page-header-row">
        <div>
            <h1 class="page-header"><?= htmlspecialchars($view_title) ?></h1>
            <p class="page-subtitle">Kelola dan pantau status konektivitas perangkat jaringan Anda.</p>
        </div>
        <div class="page-actions">
            <a href="add_device.php" class="btn">Tambah Perangkat</a>
        </div>
    </div>
</div>

<div class="form-container motion-fade-up form-container--filter">
    <?php $filter_active_count = ((isset($view_all) && !$view_all) ? 1 : 0) + (trim($search ?? '') !== '' ? 1 : 0); ?>
    <button type="button" class="m-mobile-only m-filter-head" data-toggle="filter-devices-scope filter-devices-main" aria-expanded="false">
        <span>Filter</span>
        <?php if ($filter_active_count > 0): ?><span class="m-filter-head-count"><?= (int)$filter_active_count ?> aktif</span><?php endif; ?>
    </button>
    <div class="m-collapsible m-filter-more" id="filter-devices-scope">
    <div class="filter-grid filter-grid--scope">
        <div class="field">
            <label>Site</label>
            <select onchange="if(this.value){ window.location.href=this.value; }">
                <option value="devices.php" <?= $view_all ? 'selected' : '' ?>>Semua site</option>
                <?php foreach ($all_sites as $s): ?>
                    <option value="devices.php?type=site&id=<?= $s['id'] ?>" <?= ($filter_type == 'site' && $filter_id == $s['id'] && !$view_all) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['name']) ?> (<?= $s['device_count'] ?> Perangkat)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Tipe perangkat</label>
            <select onchange="if(this.value){ window.location.href=this.value; }">
                <option value="devices.php" <?= $view_all ? 'selected' : '' ?>>Semua tipe</option>
                <?php foreach ($all_roles as $r): ?>
                    <option value="devices.php?type=role&id=<?= $r['id'] ?>" <?= ($filter_type == 'role' && $filter_id == $r['id'] && !$view_all) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($r['name']) ?> (<?= $r['device_count'] ?> Perangkat)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <hr class="filter-divider">
    </div>
    <form method="get" class="auto-filter-form">
        <?php if (!$view_all): ?>
            <input type="hidden" name="type" value="<?= htmlspecialchars($filter_type) ?>">
            <input type="hidden" name="id" value="<?= $filter_id ?>">
        <?php endif; ?>
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
        <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
        <div class="filter-grid filter-grid--toolbar">
            <div class="field">
                <label for="device-search">Cari perangkat</label>
                <input type="text" id="device-search" name="search" enterkeyhint="search" placeholder="Nama perangkat, IP, atau lokasi…" value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="m-collapsible m-filter-more" id="filter-devices-main">
            <div class="field">
                <label for="device-limit">Baris per halaman</label>
                <select id="device-limit" name="limit">
                    <?= limit_select_options($allowed_limits, $limit) ?>
                </select>
            </div>
            <div class="filter-grid-actions">
                <?= filter_reset_link($view_all ? 'devices.php' : "devices.php?type=$filter_type&id=$filter_id") ?>
            </div>
            </div>
        </div>
    </form>
</div>

    <div class="table-container motion-fade-up motion-fade-up">
        <div class="table-header">
            <h2 class="table-title">Daftar Perangkat <br><p class="page-subtitle">(Menampilkan <?= count($devices) ?> dari <?= $total_rows ?> Perangkat)</p></h2>
            <div class="bulk-actions">
                <button type="button" class="btn btn-sm secondary m-mobile-only" id="mobile-select-all-btn">Pilih</button>
                <button onclick="bulkAction('delete')" class="btn btn-sm danger" disabled id="bulk-delete-btn">
                    <span class="m-desktop-only">Hapus Terpilih</span><span class="m-mobile-only">Hapus</span>
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
                    <?= table_sort_link('name', 'Perangkat', $sort, $order) ?>
                    <?= table_sort_link('location', 'Lokasi', $sort, $order) ?>
                    <?= table_sort_link('vendor', 'Vendor', $sort, $order) ?>
                    <th width="150">Status Aksi</th>
                    <th style="text-align: right; width: 220px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($devices)): ?>
                    <tr><td colspan="6" class="table-cell-center">Tidak ada perangkat ditemukan.</td></tr>
                <?php else: ?>
                    <?php foreach ($devices as $d):
                        $protocol = (string)($d['protocol'] ?? 'SSH');
                        $connKey = (string)(int)$d['id'];
                        $connRow = $connectivity_by_id[$connKey] ?? null;
                        $icmpState = $connRow['icmp'] ?? 'unknown';
                        $icState = $connRow['interconnect'] ?? 'unknown';
                    ?>
                        <tr data-device-id="<?= (int)$d['id'] ?>">
                            <td class="td-check" data-label="Pilih"><input type="checkbox" class="device-checkbox" name="selected_ids[]" value="<?= (int)$d['id'] ?>"></td>
                            <td class="td-primary" data-label="Perangkat">
                                <strong><?= htmlspecialchars($d['name']) ?></strong><br>
                                <small class="small-text-muted"><?= htmlspecialchars($d['ip']) ?></small>
                                <?php
                                    $icmpDotClass = $icmpState === 'up' ? 'is-up' : ($icmpState === 'down' ? 'is-down' : 'is-unknown');
                                    $sshDotClass = $icState === 'up' ? 'is-up' : ($icState === 'down' ? 'is-down' : 'is-unknown');
                                ?>
                                <span class="m-collapsed-extra"><span class="m-dot <?= $icmpDotClass ?>" id="icmp-dot-<?= (int)$d['id'] ?>" title="ICMP: <?= htmlspecialchars($icmpState) ?>"></span><span class="m-dot <?= $sshDotClass ?>" id="ssh-dot-<?= (int)$d['id'] ?>" title="SSH: <?= htmlspecialchars($icState) ?>"></span></span>
                            </td>
                            <td data-label="Lokasi"><?= htmlspecialchars($d['location'] ?? '-') ?></td>
                            <td data-label="Vendor"><span class="badge blue"><?= htmlspecialchars($d['vendor_name'] ?? 'N/A') ?></span></td>
                            <td data-label="Status" id="action-status-<?= (int)$d['id'] ?>">
                                <div class="status-container">
                                    <div class="icmp-status" id="icmp-status-<?= (int)$d['id'] ?>">
                                        <?= device_icmp_badge_html($icmpState) ?>
                                    </div>
                                    <div class="ssh-status" id="ssh-status-<?= (int)$d['id'] ?>">
                                        <?= device_interconnect_badge_html($icState, $protocol) ?>
                                    </div>
                                </div>
                            </td>
                            <td class="td-actions" data-label="Aksi">
                                <div class="action-buttons">
                                    <button class="btn btn-sm" title="Refresh" onclick="checkDeviceStatus(<?= (int)$d['id'] ?>, this)">Refresh</button>
                                    <button class="btn btn-sm" title="Backup" onclick="manualBackup(<?= (int)$d['id'] ?>, '<?= htmlspecialchars(addslashes($d['name'])) ?>', this)">Backup</button>
                                    <a class="btn btn-sm secondary" href="edit_device.php?id=<?= $d['id'] ?>">Ubah</a>
                                    <form method="POST" onsubmit="return mConfirmSubmit(event, this, 'Hapus perangkat ini?', 'Hapus');" class="inline">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
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
    $build_url = function ($p) use ($search, $filter_type, $filter_id, $limit, $sort, $order, $view_all) {
        $q = ['page' => $p, 'search' => $search, 'limit' => $limit, 'sort' => $sort, 'order' => $order];
        if (!$view_all) {
            $q['type'] = $filter_type;
            $q['id'] = $filter_id;
        }
        return '?' . http_build_query($q);
    };
    render_pagination((int)$page, (int)$total_pages, $build_url);
    ?>

<input type="hidden" id="csrf-token" value="<?= htmlspecialchars(csrf_token()); ?>">

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

const CONNECTIVITY_POLL_MS = <?php echo (int)DEVICE_CONNECTIVITY_TTL * 1000; ?>;
const visibleDeviceIds = <?= json_encode(array_map(static fn($d) => (int)$d['id'], $devices), JSON_THROW_ON_ERROR) ?>;

function stateLabel(state) {
    if (state === 'up') return 'OK';
    if (state === 'down') return 'Mati';
    return 'Tidak diketahui';
}

function applyDeviceStatus(deviceId, device) {
    const icmpElement = document.getElementById(`icmp-status-${deviceId}`);
    const sshElement = document.getElementById(`ssh-status-${deviceId}`);
    if (icmpElement) {
        if (device.icmp === 'up') {
            icmpElement.innerHTML = '<span class="badge success">ICMP: OK</span>';
        } else if (device.icmp === 'down') {
            icmpElement.innerHTML = '<span class="badge danger">ICMP: Mati</span>';
        } else {
            icmpElement.innerHTML = '<span class="badge gray">ICMP: —</span>';
        }
    }
    if (sshElement) {
        const proto = device.protocol || 'SSH';
        if (device.interconnect === 'up') {
            sshElement.innerHTML = `<span class="badge success">${proto}: OK</span>`;
        } else {
            sshElement.innerHTML = `<span class="badge danger">${proto}: Gagal</span>`;
        }
    }
    const icmpDot = document.getElementById(`icmp-dot-${deviceId}`);
    if (icmpDot) {
        icmpDot.className = 'm-dot ' + (device.icmp === 'up' ? 'is-up' : (device.icmp === 'down' ? 'is-down' : 'is-unknown'));
        icmpDot.title = 'ICMP: ' + stateLabel(device.icmp);
    }
    const sshDot = document.getElementById(`ssh-dot-${deviceId}`);
    if (sshDot) {
        const proto = device.protocol || 'SSH';
        sshDot.className = 'm-dot ' + (device.interconnect === 'up' ? 'is-up' : (device.interconnect === 'down' ? 'is-down' : 'is-unknown'));
        sshDot.title = proto + ': ' + stateLabel(device.interconnect);
    }
}

async function loadPageConnectivity(showLoader = false) {
    if (!visibleDeviceIds.length) return;
    try {
        const response = await fetch(
            `api_device_connectivity.php?ids=${visibleDeviceIds.join(',')}`,
            { credentials: 'same-origin' }
        );
        const data = await response.json();
        if (!data.success || !data.devices) return;
        for (const [id, device] of Object.entries(data.devices)) {
            applyDeviceStatus(parseInt(id, 10), device);
        }
    } catch (e) {
        if (showLoader) {
            visibleDeviceIds.forEach((deviceId) => {
                const icmpElement = document.getElementById(`icmp-status-${deviceId}`);
                const sshElement = document.getElementById(`ssh-status-${deviceId}`);
                if (icmpElement) icmpElement.innerHTML = '<span class="badge gray">ICMP: —</span>';
                if (sshElement) sshElement.innerHTML = '<span class="badge gray">SSH: —</span>';
                const icmpDot = document.getElementById(`icmp-dot-${deviceId}`);
                if (icmpDot) { icmpDot.className = 'm-dot is-unknown'; icmpDot.title = 'ICMP: Tidak diketahui'; }
                const sshDot = document.getElementById(`ssh-dot-${deviceId}`);
                if (sshDot) { sshDot.className = 'm-dot is-unknown'; sshDot.title = 'SSH: Tidak diketahui'; }
            });
        }
    }
}

async function checkDeviceStatus(deviceId, button = null) {
    if (button) { button.disabled = true; button.innerHTML = '...'; }
    try {
        const response = await fetch(`api_device_connectivity.php?refresh=1&id=${deviceId}`);
        const data = await response.json();
        if (data.success && data.device) {
            applyDeviceStatus(deviceId, data.device);
        }
    } catch (e) {} finally { if (button) { button.disabled = false; button.innerHTML = 'Refresh'; } }
}

async function manualBackup(deviceId, deviceName, button) {
    if (!confirm(`Backup manual "${deviceName}"?`)) return;
    button.disabled = true; button.innerHTML = '...';
    const csrf = document.getElementById('csrf-token').value;
    const sshElement = document.getElementById(`ssh-status-${deviceId}`);
    if (sshElement) sshElement.innerHTML = '<span class="badge blue">Proses...</span>';

    try {
        setLoading(button, true);
        const response = await fetch('backup_manual.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `device_id=${deviceId}&csrf=${csrf}&api=1` 
        });
        const data = await response.json();
        showToast(data.success ? `Berhasil! File: ${data.filename}` : `Gagal: ${data.error}`, data.success ? 'success' : 'error');
    } catch (e) { showToast('Error koneksi API', 'error'); }
    finally { setLoading(button, false); button.innerHTML = 'Backup'; checkDeviceStatus(deviceId); }
}

document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('select-all-checkbox');
    const itemCheckboxes = document.querySelectorAll('.device-checkbox');
    const deleteBtn = document.getElementById('bulk-delete-btn');
    const mobileSelectAllBtn = document.getElementById('mobile-select-all-btn');

    function updateBulkButtons() {
        const boxes = document.querySelectorAll('.device-checkbox');
        const checkedCount = document.querySelectorAll('.device-checkbox:checked').length;
        if (deleteBtn) deleteBtn.disabled = !(checkedCount > 0);
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
            const boxes = document.querySelectorAll('.device-checkbox');
            const allChecked = boxes.length > 0 && Array.from(boxes).every(function(cb){ return cb.checked; });
            boxes.forEach(function(cb){ cb.checked = !allChecked; });
            if (selectAll) selectAll.checked = !allChecked;
            updateBulkButtons();
        });
    }

    setInterval(function () { loadPageConnectivity(false); }, CONNECTIVITY_POLL_MS);
});

function bulkAction(action) {
    const checked = document.querySelectorAll('.device-checkbox:checked');
    if (!checked.length) return;
    const submitBulk = function() {
        const form = document.getElementById('bulk-form');
        document.getElementById('bulk-action-type').value = action;

        checked.forEach(cb => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_ids[]';
            input.value = cb.value;
            form.appendChild(input);
        });
        form.submit();
    };
    if (action === 'delete') {
        mConfirmAsync({ message: `Anda yakin ingin menghapus ${checked.length} perangkat? Semua riwayat backup juga akan terhapus.`, confirmText: 'Hapus', danger: true })
            .then(function(ok){ if (ok) submitBulk(); });
        return;
    }
    submitBulk();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
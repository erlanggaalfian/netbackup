<?php
require_once __DIR__ . '/../config/db.php';
require_login();
require_admin(); // Hanya admin yang bisa melihat log

// --- LOGIKA FILTER & PENGURUTAN ---
$filter_type = get('type') ?? '';
$filter_device = (int)(get('device_id') ?? 0);
$filter_start_date = get('start_date') ?? '';
$filter_end_date = get('end_date') ?? '';

// PERBAIKAN: Menambahkan dukungan limit hingga 2000 data
$allowed_limits = [25, 50, 100, 200, 500, 1000, 2000];
$limit = in_array((int)(get('limit') ?? 50), $allowed_limits) ? (int)(get('limit') ?? 50) : 50;

// Logika Pagination
$page = max(1, (int)(get('page') ?? 1));
$offset = ($page - 1) * $limit;

// Logika pengurutan
$sort_by = get('sort') ?? 'timestamp';
$order = get('order') ?? 'desc';
$allowed_sort = ['timestamp', 'type', 'actor_name', 'device_name'];
$allowed_order = ['asc', 'desc'];
if (!in_array($sort_by, $allowed_sort)) $sort_by = 'timestamp';
if (!in_array($order, $allowed_order)) $order = 'desc';
$order_by_clause = "ORDER BY {$sort_by} {$order}";

// Bangun query SQL secara dinamis
$params = [];
$where_sql = ' WHERE 1=1';

if ($filter_type) {
    $where_sql .= ' AND l.type = ?';
    $params[] = $filter_type;
}
if ($filter_device > 0) {
    $where_sql .= ' AND l.target_id = ?';
    $params[] = $filter_device;
}
if ($filter_start_date) {
    $where_sql .= ' AND l.timestamp >= ?';
    $params[] = $filter_start_date . ' 00:00:00';
}
if ($filter_end_date) {
    $where_sql .= ' AND l.timestamp <= ?';
    $params[] = $filter_end_date . ' 23:59:59';
}

// 1. Hitung total data untuk pagination
$count_sql = "SELECT COUNT(*) FROM logs l $where_sql";
$stmt_count = $pdo->prepare($count_sql);
$stmt_count->execute($params);
$total_rows = $stmt_count->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// 2. Ambil data dengan LIMIT dan OFFSET
$sql = "SELECT l.*, u.username AS actor_name, d.name AS device_name 
        FROM logs l
        LEFT JOIN users u ON u.id = l.actor
        LEFT JOIN devices d ON d.id = l.target_id
        $where_sql
        $order_by_clause 
        LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$devices = $pdo->query('SELECT id, name FROM devices ORDER BY name')->fetchAll();
$log_types = $pdo->query('SELECT DISTINCT type FROM logs ORDER BY type')->fetchAll(PDO::FETCH_COLUMN);

function get_badge_class($type) {
    if (strpos($type, 'success') !== false) return 'success';
    if (strpos($type, 'fail') !== false || strpos($type, 'error') !== false) return 'danger';
    if (strpos($type, 'login') !== false || strpos($type, 'logout') !== false) return 'blue';
    return 'gray';
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header-container motion-fade-up motion-fade-up">
  <h1 class="page-header">Log Sistem</h1>
  <p class="page-subtitle">Pantau semua aktivitas yang tercatat di dalam sistem.</p>
</div>

<div class="form-container motion-fade-up form-container--filter">
  <form method="get" class="auto-filter-form">
    <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort_by); ?>">
    <input type="hidden" name="order" value="<?php echo htmlspecialchars($order); ?>">
    <div class="filter-form filter-form--logs">
      <div class="field">
        <label for="log-start">Dari tanggal</label>
        <input type="date" id="log-start" name="start_date" value="<?php echo htmlspecialchars($filter_start_date); ?>">
      </div>
      <div class="field">
        <label for="log-end">Sampai tanggal</label>
        <input type="date" id="log-end" name="end_date" value="<?php echo htmlspecialchars($filter_end_date); ?>">
      </div>
      <div class="field">
        <label for="log-type">Jenis aktivitas</label>
        <select id="log-type" name="type">
            <option value="">Semua jenis</option>
            <?php foreach ($log_types as $type): ?>
                <option value="<?php echo htmlspecialchars($type); ?>" <?php if ($type === $filter_type) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($type); ?>
                </option>
            <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="log-device">Perangkat</label>
        <select id="log-device" name="device_id">
            <option value="">Semua perangkat</option>
            <?php foreach ($devices as $device): ?>
                <option value="<?php echo (int)$device['id']; ?>" <?php if ((int)$device['id'] === $filter_device) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($device['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="log-limit">Baris per halaman</label>
        <select id="log-limit" name="limit">
            <?= limit_select_options($allowed_limits, $limit) ?>
        </select>
      </div>
      <div class="filter-grid-actions">
        <?= filter_reset_link('logs.php') ?>
      </div>
    </div>
  </form>
</div>

<div class="table-container motion-fade-up motion-fade-up">
  <div class="table-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px;">
    <div>
        <h2 class="table-title">Riwayat Aktivitas</h2>
        <p class="page-subtitle">(Menampilkan <?php echo count($logs); ?> dari <?php echo number_format($total_rows); ?> total log)</p>
    </div>
    <div style="display: flex; gap: 10px;">
        <button class="btn btn-sm danger" onclick="deleteSelectedLogs()">
            Hapus Terpilih
        </button>
        <button class="btn btn-sm danger" onclick="clearAllLogs()" style="opacity: 0.8;">
            Kosongkan Semua
        </button>
    </div>
  </div>
  
  <?php if (empty($logs)): ?>
    <div class="page-404">
      <p style="font-size: 18px; color: var(--color-text-header); font-weight: 500;">Tidak ada log ditemukan</p>
      <p class="small-text-muted">Belum ada aktivitas yang tercatat atau filter Anda tidak cocok.</p>
    </div>
  <?php else: ?>
    <div class="table-scroll-wrap">
    <table class="modern-table">
      <thead>
        <tr>
          <th style="width: 40px; text-align: center;">
            <input type="checkbox" id="selectAllCheckbox" onclick="toggleSelectAll(this)" style="cursor: pointer;">
          </th>
          <?php echo table_sort_link('timestamp', 'Waktu', $sort_by, $order); ?>
          <?php echo table_sort_link('type', 'Tipe', $sort_by, $order); ?>
          <?php echo table_sort_link('actor_name', 'Aktor', $sort_by, $order); ?>
          <?php echo table_sort_link('device_name', 'Perangkat', $sort_by, $order); ?>
          <th>Detail</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $log): ?>
          <tr>
            <td class="td-check" data-label="Pilih" style="text-align: center;">
              <input type="checkbox" class="log-checkbox" value="<?php echo (int)$log['id']; ?>" style="cursor: pointer;">
            </td>
            <td class="td-primary" data-label="Waktu"><?php echo date('d M Y, H:i:s', strtotime($log['timestamp'])); ?> &nbsp;<span class="badge <?php echo get_badge_class($log['type']); ?>"><?php echo htmlspecialchars($log['type']); ?></span></td>
            <td data-label="Aktor"><?php echo htmlspecialchars($log['actor_name'] ?? 'Sistem'); ?></td>
            <td data-label="Perangkat"><?php echo htmlspecialchars($log['device_name'] ?? '-'); ?></td>
            <td data-label="Detail" style="font-size: 13px;"><?php echo htmlspecialchars($log['details'] ?? $log['message'] ?? '-'); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
</div>

    <div>
    <?php
    $build_url = function ($p) use ($filter_type, $filter_device, $filter_start_date, $filter_end_date, $limit, $sort_by, $order) {
        return '?' . http_build_query([
            'page' => $p,
            'type' => $filter_type,
            'device_id' => $filter_device,
            'start_date' => $filter_start_date,
            'end_date' => $filter_end_date,
            'limit' => $limit,
            'sort' => $sort_by,
            'order' => $order,
        ]);
    };
    render_pagination((int)$page, (int)$total_pages, $build_url);
    ?>
    </div>

  <?php endif; ?>
</div>

<script>
const csrfToken = '<?php echo $_SESSION["csrf_token"] ?? csrf_token() ?? ""; ?>';

function toggleSelectAll(source) {
    const checkboxes = document.querySelectorAll('.log-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = source.checked;
    });
}

function deleteSelectedLogs() {
    const checkedBoxes = document.querySelectorAll('.log-checkbox:checked');
    const selectedIds = Array.from(checkedBoxes).map(cb => cb.value);

    if (selectedIds.length === 0) {
        showToast('Silakan centang setidaknya satu log yang ingin dihapus.', 'warning');
        return;
    }

    if (!confirm(`Anda yakin ingin menghapus ${selectedIds.length} log terpilih?`)) return;

    fetch('api_clear_logs', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            'action': 'delete_selected',
            'ids': selectedIds.join(','), 
            'csrf': csrfToken
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            showToast('Gagal menghapus log: ' + (data.message || 'Unknown server error.'), 'error');
        }
    })
    .catch(err => {
        console.error('Fetch error:', err);
        showToast('Terjadi kesalahan jaringan saat menghapus log terpilih.', 'error');
    });
}

function clearAllLogs() {
    if (!confirm('PERINGATAN: Anda yakin ingin mengosongkan SELURUH tabel log secara permanen? Aksi ini tidak dapat dibatalkan.')) return;
    
    fetch('api_clear_logs', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            'action': 'clear_all',
            'csrf': csrfToken
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            showToast('Gagal mengosongkan log: ' + (data.message || 'Unknown server error.'), 'error');
        }
    })
    .catch(err => {
        console.error('Fetch error:', err);
        showToast('Terjadi kesalahan jaringan saat mencoba mengosongkan log.', 'error');
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
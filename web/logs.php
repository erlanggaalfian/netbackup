<?php
require_once __DIR__ . '/../config/db.php';
require_login();
require_admin(); // Hanya admin yang bisa melihat log

// --- LOGIKA FILTER ---
$filter_type = get('type') ?? '';
$filter_device = (int)(get('device_id') ?? 0);
$limit = in_array((int)(get('limit') ?? 50), [25, 50, 100, 200]) ? (int)(get('limit') ?? 50) : 50;

// Bangun query SQL secara dinamis
$params = [];
$sql = 'SELECT l.*, u.username AS actor_name, d.name AS device_name 
        FROM logs l
        LEFT JOIN users u ON u.id = l.actor
        LEFT JOIN devices d ON d.id = l.target_id
        WHERE 1=1';

if ($filter_type) {
    $sql .= ' AND l.type = ?';
    $params[] = $filter_type;
}
if ($filter_device > 0) {
    $sql .= ' AND l.target_id = ?';
    $params[] = $filter_device;
}

$sql .= ' ORDER BY l.timestamp DESC LIMIT ' . $limit;
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

<div class="form-container form-container-full-width">
  <div>
    <h1 class="page-title">Log Sistem</h1>
    <p class="page-subtitle">Pantau semua aktivitas yang tercatat di dalam sistem.</p>
  </div>
</div>

<div class="form-container form-container-full-width">
  <form method="get" class="filter-form align-end">
    <div class="field field-grow">
        <label>Tipe Log</label>
        <select name="type">
            <option value="">Semua Tipe</option>
            <?php foreach ($log_types as $type): ?>
                <option value="<?php echo htmlspecialchars($type); ?>" <?php if ($type === $filter_type) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($type); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field field-grow">
        <label>Perangkat</label>
        <select name="device_id">
            <option value="">Semua Perangkat</option>
            <?php foreach ($devices as $device): ?>
                <option value="<?php echo (int)$device['id']; ?>" <?php if ((int)$device['id'] === $filter_device) echo 'selected'; ?>>
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
        </select>
    </div>
    <div class="action-buttons">
      <button type="submit" class="btn">Filter</button>
      <a href="/logs" class="btn secondary">Reset</a>
    </div>
  </form>
</div>

<div class="table-container">
  <div class="table-header">
    <h2 class="table-title">Riwayat Aktivitas (Menampilkan <?php echo count($logs); ?> log terbaru)</h2>
    <button class="btn btn-sm danger" onclick="clearLogs()">
      <span>🗑️</span> Hapus Semua Log
    </button>
  </div>
  <?php if (empty($logs)): ?>
    <div class="page-404">
      <p style="font-size: 18px; color: var(--color-text-header); font-weight: 500;">Tidak ada log ditemukan</p>
      <p class="small-text-muted">Belum ada aktivitas yang tercatat atau filter Anda tidak cocok.</p>
    </div>
  <?php else: ?>
    <table class="modern-table">
      <thead>
        <tr>
          <th>Waktu</th>
          <th>Tipe</th>
          <th>Aktor</th>
          <th>Perangkat</th>
          <th>Detail</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $log): ?>
          <tr>
            <td style="white-space: nowrap;"><?php echo date('d M Y, H:i:s', strtotime($log['timestamp'])); ?></td>
            <td>
              <span class="badge <?php echo get_badge_class($log['type']); ?>">
                <?php echo htmlspecialchars($log['type']); ?>
              </span>
            </td>
            <td><?php echo htmlspecialchars($log['actor_name'] ?? 'Sistem'); ?></td>
            <td><?php echo htmlspecialchars($log['device_name'] ?? '-'); ?></td>
            <td style="font-size: 13px;"><?php echo htmlspecialchars($log['details']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<script>
function clearLogs() {
    if (!confirm('Anda yakin ingin menghapus SEMUA log secara permanen? Aksi ini tidak dapat dibatalkan.')) return;
    
    // Menggunakan endpoint API absolut '/api_clear_logs'
    fetch('/api_clear_logs', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            'action': 'clear',
            'csrf': '<?php echo htmlspecialchars(csrf_token()); ?>'
        })
    })
    .then(res => {
        return res.json().then(data => ({ ok: res.ok, data }));
    })
    .then(({ ok, data }) => {
        if (ok && data.success) {
            window.location.href = '/logs?status=cleared';
        } else {
            alert('Gagal menghapus log: ' + (data.error || 'Unknown server error.'));
        }
    })
    .catch(err => {
        console.error('Fetch error:', err);
        alert('Terjadi kesalahan jaringan saat mencoba menghapus log.');
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>


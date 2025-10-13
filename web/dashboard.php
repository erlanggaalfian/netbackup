<?php
require_once __DIR__ . '/../config/db.php';
require_login();

// --- LOGIKA FILTER DAN PENCARIAN ---
$search = get('search') ?? '';
$limit = in_array((int)(get('limit') ?? 50), [25, 50, 100, 200]) ? (int)(get('limit') ?? 50) : 50;
$search_condition = '';
$search_params = [];

if (!empty($search)) {
    $search_condition = 'WHERE (d.name LIKE ? OR d.ip LIKE ? OR d.location LIKE ? OR v.name LIKE ?)';
    $search_params = ['%' . $search . '%', '%' . $search . '%', '%' . $search . '%', '%' . $search . '%'];
}

// --- Mengambil daftar perangkat (Query Dioptimalkan dengan LIMIT) ---
$sql = "
    SELECT 
        d.*, 
        v.name AS vendor_name, 
        b.status as last_backup_status, 
        b.timestamp as last_backup_time
    FROM devices d
    LEFT JOIN vendor v ON v.id = d.vendor_id
    LEFT JOIN (
        SELECT 
            device_id, 
            status, 
            timestamp,
            ROW_NUMBER() OVER(PARTITION BY device_id ORDER BY timestamp DESC) as rn
        FROM backups
    ) b ON d.id = b.device_id AND b.rn = 1
    {$search_condition}
    ORDER BY d.name
    LIMIT " . $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($search_params);
$devices = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="form-container form-container-full-width">
  <h1 class="page-title">Dashboard</h1>
  <p class="page-subtitle">Selamat datang, <?php echo htmlspecialchars(current_user()['username']); ?>. Berikut ringkasan sistem Anda.</p>
</div>

<div class="form-container form-container-full-width">
    <form method="get">
        <div class="filter-form flex-gap">
             <div class="field field-grow">
                <label>Pencarian</label>
                <input type="text" name="search" placeholder="Cari nama, IP, lokasi, atau vendor..." value="<?php echo htmlspecialchars($search); ?>">
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
        </div>
        <div class="action-buttons mt-1">
            <button type="submit" class="btn">Filter</button>
            <a href="/dashboard" class="btn secondary">Reset</a>
        </div>
    </form>
</div>

<div class="table-container">
  <div class="table-header">
      <h2 class="table-title">Status Perangkat (<?php echo count($devices); ?> ditemukan)</h2>
      <a href="/devices" class="btn btn-sm">Kelola Perangkat</a>
  </div>
  
  <?php if (empty($devices)): ?>
    <div class="page-404">
      <p style="font-size: 18px; color: var(--color-text-header); font-weight: 500;">Tidak ada perangkat ditemukan</p>
      <p class="small-text-muted">Tambahkan perangkat baru untuk memulai monitoring.</p>
      <a href="/add_device" class="btn mt-2">Tambah Perangkat</a>
    </div>
  <?php else: ?>
    <table class="modern-table">
      <thead>
        <tr>
          <th>Perangkat</th>
          <th>Lokasi</th>
          <th>Vendor</th>
          <th>Status Koneksi</th>
          <th>Backup Terakhir</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($devices as $d): ?>
        <tr data-device-id="<?php echo (int)$d['id']; ?>">
          <td>
              <strong><?php echo htmlspecialchars($d['name']); ?></strong><br>
              <small class="small-text-muted"><?php echo htmlspecialchars($d['ip']); ?></small>
          </td>
          <td><?php echo htmlspecialchars($d['location'] ?? '-'); ?></td>
          <td><span class="badge blue"><?php echo htmlspecialchars($d['vendor_name'] ?? 'N/A'); ?></span></td>
          <td id="connection-status-<?php echo (int)$d['id']; ?>">
              <span class="badge gray">Mengecek...</span>
          </td>
          <td>
            <?php if ($d['last_backup_time']): ?>
                <span class="badge <?php echo $d['last_backup_status'] === 'success' ? 'success' : 'danger'; ?>">
                    <?php echo date('d M Y, H:i', strtotime($d['last_backup_time'])); ?>
                </span>
            <?php else: ?>
                <span class="badge gray">Belum Pernah</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="action-buttons">
              <a href="/edit_device?id=<?php echo (int)$d['id']; ?>" class="btn btn-sm secondary">Edit</a>
              <a href="/backups?device_id=<?php echo (int)$d['id']; ?>" class="btn btn-sm">Riwayat</a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<script>
// JavaScript untuk cek status koneksi perangkat
async function checkDeviceConnection(deviceId) {
    const statusElement = document.getElementById(`connection-status-${deviceId}`);
    if (!statusElement) return;

    try {
        const response = await fetch(`api_check_status.php?id=${deviceId}`);
        const data = await response.json();
        
        if (data.success) {
            if (data.tcp_status === true) {
                statusElement.innerHTML = `<span class="badge success">${data.protocol} Terhubung</span>`;
            } else {
                statusElement.innerHTML = `<span class="badge danger">${data.protocol} Gagal</span>`;
            }
        } else {
             statusElement.innerHTML = `<span class="badge danger">Error</span>`;
        }
    } catch (error) {
        statusElement.innerHTML = `<span class="badge danger">Error</span>`;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const deviceRows = document.querySelectorAll('tr[data-device-id]');
    
    function checkAllDevices() {
        deviceRows.forEach(element => {
            const deviceId = element.getAttribute('data-device-id');
            if (deviceId) {
                checkDeviceConnection(deviceId);
            }
        });
    }

    checkAllDevices();
    setInterval(checkAllDevices, 60000); // Refresh setiap 1 menit
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

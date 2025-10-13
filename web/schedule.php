<?php
/**
 * File: web/schedule.php
 * Halaman untuk mengelola jadwal backup otomatis.
 * Versi Final & Stabil.
 */
require_once __DIR__ . '/../config/db.php';
require_login(); // Semua pengguna yang login bisa mengakses halaman ini.

// --- LOGIKA SIMPAN PERUBAHAN JADWAL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (verify_csrf(post('csrf'))) {
        $device_id = (int)(post('device_id') ?? 0);
        $schedule = post('schedule');
        $run_hour = (int)(post('run_hour') ?? 2);
        $run_day = (int)(post('run_day') ?? 1);
        $active = (int)(post('active') ?? 0);

        // Validasi input sebelum menyimpan ke database
        if ($device_id > 0 && in_array($schedule, ['daily', 'weekly', 'monthly']) && $run_hour >= 0 && $run_hour <= 23 && $run_day >= 1 && $run_day <= 28) {
            // Menggunakan "ON DUPLICATE KEY UPDATE" untuk menyederhanakan logika insert/update
            $sql = "INSERT INTO settings (device_id, schedule, run_hour, run_day, active) 
                    VALUES (?, ?, ?, ?, ?) 
                    ON DUPLICATE KEY UPDATE schedule=VALUES(schedule), run_hour=VALUES(run_hour), run_day=VALUES(run_day), active=VALUES(active)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$device_id, $schedule, $run_hour, $run_day, $active]);
            app_log('schedule_update', "Jadwal diperbarui untuk device id={$device_id}", current_user()['id'], $device_id);
            header('Location: /schedule?status=success');
            exit;
        }
    }
}

// --- LOGIKA FILTER DAN PENCARIAN ---
$search = get('search') ?? '';
$schedule_filter = get('schedule_filter') ?? '';
$status_filter = get('status_filter') ?? '';
$limit = in_array((int)(get('limit') ?? 50), [25, 50, 100, 200]) ? (int)(get('limit') ?? 50) : 50;

// Persiapan query utama untuk mengambil data jadwal dan status backup terakhir
$sql = "
    SELECT 
        d.id, d.name, d.ip, 
        s.schedule, s.run_hour, s.run_day, s.active,
        b.timestamp as last_backup_time, 
        b.status as last_backup_status
    FROM devices d
    LEFT JOIN settings s ON s.device_id = d.id
    LEFT JOIN (
        SELECT 
            device_id, 
            timestamp, 
            status,
            ROW_NUMBER() OVER(PARTITION BY device_id ORDER BY timestamp DESC) as rn
        FROM backups
    ) b ON d.id = b.device_id AND b.rn = 1
    WHERE (d.name LIKE ? OR d.ip LIKE ?)
";
$params = ['%' . $search . '%', '%' . $search . '%'];

if (in_array($schedule_filter, ['daily', 'weekly', 'monthly'])) {
    $sql .= " AND s.schedule = ?";
    $params[] = $schedule_filter;
}
if ($status_filter !== '' && in_array((int)$status_filter, [0, 1])) {
    $sql .= " AND s.active = ?";
    $params[] = (int)$status_filter;
}
$sql .= " ORDER BY d.name LIMIT " . $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$devices = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="form-container form-container-full-width">
  <h1 class="page-title">Jadwal Backup Otomatis</h1>
  <p class="page-subtitle">Atur jadwal backup harian, mingguan, atau bulanan untuk setiap perangkat.</p>
  <div class="alert info">
    <strong>💡 Info Penjadwalan:</strong> Backup otomatis dijalankan oleh scheduler di server (cron job) setiap jam. Sistem akan mengeksekusi backup jika waktunya cocok dengan jadwal yang Anda atur di bawah.
  </div>
</div>

<!-- Formulir Filter -->
<div class="form-container form-container-full-width">
    <form method="get">
        <div class="filter-form flex-gap">
            <div class="field field-grow">
                <label>Pencarian</label>
                <input type="text" name="search" placeholder="Cari nama atau IP perangkat..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="field">
                <label>Tipe Jadwal</label>
                <select name="schedule_filter">
                    <option value="">Semua Tipe</option>
                    <option value="daily" <?php if ($schedule_filter === 'daily') echo 'selected'; ?>>Harian</option>
                    <option value="weekly" <?php if ($schedule_filter === 'weekly') echo 'selected'; ?>>Mingguan</option>
                    <option value="monthly" <?php if ($schedule_filter === 'monthly') echo 'selected'; ?>>Bulanan</option>
                </select>
            </div>
            <div class="field">
                <label>Status Jadwal</label>
                <select name="status_filter">
                    <option value="">Semua Status</option>
                    <option value="1" <?php if ($status_filter === '1') echo 'selected'; ?>>Aktif</option>
                    <option value="0" <?php if ($status_filter === '0') echo 'selected'; ?>>Nonaktif</option>
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
        </div>
        <div class="action-buttons mt-1">
            <button type="submit" class="btn">Filter</button>
            <a href="/schedule" class="btn secondary">Reset</a>
        </div>
    </form>
</div>

<!-- Kontainer Tabel -->
<div class="table-container">
  <div class="table-header">
    <h2 class="table-title">Pengaturan Jadwal (Menampilkan <?php echo count($devices); ?> perangkat)</h2>
  </div>
  
  <table class="modern-table">
    <thead>
      <tr>
        <th>Perangkat</th>
        <th>Jadwal</th>
        <th>Jam</th>
        <th>Tanggal (Bulanan)</th>
        <th>Status</th>
        <th>Backup Terakhir</th>
        <th>Aksi</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($devices as $r): ?>
        <tr class="schedule-row" data-schedule="<?php echo htmlspecialchars($r['schedule'] ?? 'daily'); ?>">
          <form method="post" action="/schedule">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="device_id" value="<?php echo (int)$r['id']; ?>">

            <td>
              <strong><?php echo htmlspecialchars($r['name']); ?></strong><br>
              <small class="small-text-muted"><?php echo htmlspecialchars($r['ip']); ?></small>
            </td>
            <td>
              <select name="schedule" class="schedule-select">
                <option value="daily" <?php if (($r['schedule'] ?? 'daily') === 'daily') echo 'selected'; ?>>Harian</option>
                <option value="weekly" <?php if (($r['schedule'] ?? '') === 'weekly') echo 'selected'; ?>>Mingguan</option>
                <option value="monthly" <?php if (($r['schedule'] ?? '') === 'monthly') echo 'selected'; ?>>Bulanan</option>
              </select>
            </td>
            <td>
              <select name="run_hour">
                <?php for ($i = 0; $i < 24; $i++): ?>
                  <option value="<?php echo $i; ?>" <?php if ((int)($r['run_hour'] ?? 2) === $i) echo 'selected'; ?>>
                    <?php echo str_pad((string)$i, 2, '0', STR_PAD_LEFT); ?>:00
                  </option>
                <?php endfor; ?>
              </select>
            </td>
            <td class="run-day-cell">
               <input type="number" name="run_day" min="1" max="28" value="<?php echo (int)($r['run_day'] ?? 1); ?>">
            </td>
            <td>
              <select name="active">
                  <option value="1" <?php if ((int)($r['active'] ?? 1) === 1) echo 'selected'; ?>>Aktif</option>
                  <option value="0" <?php if ((int)($r['active'] ?? 1) === 0) echo 'selected'; ?>>Nonaktif</option>
              </select>
            </td>
            <td>
              <?php if ($r['last_backup_time']): ?>
                <span class="badge <?php echo $r['last_backup_status'] === 'success' ? 'success' : 'danger'; ?>">
                  <?php echo date('d M Y, H:i', strtotime($r['last_backup_time'])); ?>
                </span>
              <?php else: ?>
                <span class="badge gray">Belum ada</span>
              <?php endif; ?>
            </td>
            <td>
              <button class="btn btn-sm" type="submit">Simpan</button>
            </td>
          </form>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
// Script untuk menampilkan/menyembunyikan input tanggal (khusus jadwal bulanan)
document.addEventListener('DOMContentLoaded', function() {
    function toggleRunDayInput(row) {
        const select = row.querySelector('.schedule-select');
        const runDayCell = row.querySelector('.run-day-cell');
        if (select.value === 'monthly') {
            runDayCell.style.visibility = 'visible';
        } else {
            runDayCell.style.visibility = 'hidden';
        }
    }

    const rows = document.querySelectorAll('.schedule-row');
    rows.forEach(row => {
        toggleRunDayInput(row);
        const select = row.querySelector('.schedule-select');
        select.addEventListener('change', function() {
            toggleRunDayInput(row);
        });
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>


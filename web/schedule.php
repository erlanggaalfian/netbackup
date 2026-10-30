<?php
/**
 * File: web/schedule.php
 * Halaman untuk mengelola jadwal backup otomatis dengan fitur Single Update & Smart Bulk Update.
 * Memiliki kolom terpisah untuk Hari (Mingguan) dan Tanggal (Bulanan).
 * Versi Final & Stabil.
 */
require_once __DIR__ . '/../config/db.php';
require_login(); // Semua pengguna yang login bisa mengakses halaman ini.

// Array label hari untuk dropdown mingguan
$hari_labels = [0 => 'Senin', 1 => 'Selasa', 2 => 'Rabu', 3 => 'Kamis', 4 => 'Jumat', 5 => 'Sabtu', 6 => 'Minggu'];

// --- LOGIKA SIMPAN PERUBAHAN JADWAL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (verify_csrf(post('csrf'))) {
        $schedules    = $_POST['schedule'] ?? [];
        $run_hours    = $_POST['run_hour'] ?? [];
        $run_weekdays = $_POST['run_weekday'] ?? []; 
        $run_days     = $_POST['run_day'] ?? [];     
        $actives      = $_POST['active'] ?? [];
        
        $single_id = isset($_POST['single_update']) ? (int)$_POST['single_update'] : 0;

        if (!empty($schedules) && is_array($schedules)) {
            $sql = "INSERT INTO settings (device_id, schedule, run_hour, run_weekday, run_day, active) 
                    VALUES (?, ?, ?, ?, ?, ?) 
                    ON DUPLICATE KEY UPDATE schedule=VALUES(schedule), run_hour=VALUES(run_hour), run_weekday=VALUES(run_weekday), run_day=VALUES(run_day), active=VALUES(active)";
            
            $stmt = $pdo->prepare($sql);
            $updated_count = 0;

            $pdo->beginTransaction();
            try {
                if ($single_id > 0) {
                    // Simpan per perangkat (Single Update)
                    if (isset($schedules[$single_id])) {
                        $schedule  = $schedules[$single_id];
                        $r_hour    = (int)($run_hours[$single_id] ?? 2);
                        $r_weekday = (int)($run_weekdays[$single_id] ?? 6); // Default 6 (Minggu)
                        $r_day     = (int)($run_days[$single_id] ?? 1);
                        $is_active = (int)($actives[$single_id] ?? 0);
                        
                        if (in_array($schedule, ['daily', 'weekly', 'monthly']) && $r_hour >= 0 && $r_hour <= 23 && $r_day >= 1 && $r_day <= 28) {
                            $stmt->execute([$single_id, $schedule, $r_hour, $r_weekday, $r_day, $is_active]);
                            app_log('schedule_update', "Jadwal diperbarui untuk device id={$single_id}", current_user()['id'], $single_id);
                        }
                    }
                } else {
                    // Simpan semua yang ada di form (Apply All)
                    foreach ($schedules as $device_id => $schedule) {
                        $device_id = (int)$device_id;
                        $r_hour    = (int)($run_hours[$device_id] ?? 2);
                        $r_weekday = (int)($run_weekdays[$device_id] ?? 6);
                        $r_day     = (int)($run_days[$device_id] ?? 1);
                        $is_active = (int)($actives[$device_id] ?? 0);

                        if ($device_id > 0 && in_array($schedule, ['daily', 'weekly', 'monthly']) && $r_hour >= 0 && $r_hour <= 23 && $r_day >= 1 && $r_day <= 28) {
                            $stmt->execute([$device_id, $schedule, $r_hour, $r_weekday, $r_day, $is_active]);
                            $updated_count++;
                        }
                    }
                    if ($updated_count > 0) {
                        app_log('schedule_bulk_update', "Jadwal diperbarui massal untuk {$updated_count} perangkat", current_user()['id']);
                    }
                }
                
                $pdo->commit();
                header('Location: /schedule?status=success');
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Gagal melakukan update jadwal: " . $e->getMessage());
            }
        }
    }
}

// --- LOGIKA FILTER, PENGURUTAN, DAN LIMIT ---
$search = get('search') ?? '';
$schedule_filter = get('schedule_filter') ?? '';
$status_filter = get('status_filter') ?? '';
$allowed_limits = [25, 50, 100, 200, 500];
$limit = in_array((int)(get('limit') ?? 25), $allowed_limits) ? (int)(get('limit') ?? 25) : 25;

$sort_by = get('sort') ?? 'device_name';
$order = get('order') ?? 'asc';

$page = max(1, (int)(get('page') ?? 1));
$offset = ($page - 1) * $limit;

$params = ['%' . $search . '%', '%' . $search . '%'];
$extra_filters = "";

if (in_array($schedule_filter, ['daily', 'weekly', 'monthly'])) {
    $extra_filters .= " AND s.schedule = ?";
    $params[] = $schedule_filter;
}
if ($status_filter !== '' && in_array((int)$status_filter, [0, 1])) {
    $extra_filters .= " AND s.active = ?";
    $params[] = (int)$status_filter;
}

// Tambahkan run_weekday ke daftar sorting yang diizinkan
$allowed_sort = ['device_name', 'schedule', 'run_hour', 'run_weekday', 'run_day', 'active', 'last_backup'];
$sort_col = in_array($sort_by, $allowed_sort) ? $sort_by : 'device_name';

switch ($sort_col) {
    case 'device_name': $sql_sort = 'd.name'; break;
    case 'schedule':    $sql_sort = 'COALESCE(s.schedule, \'daily\')'; break;
    case 'run_hour':    $sql_sort = 'COALESCE(s.run_hour, 2)'; break;
    case 'run_weekday': $sql_sort = 'COALESCE(s.run_weekday, 6)'; break;
    case 'run_day':     $sql_sort = 'COALESCE(s.run_day, 1)'; break;
    case 'active':      $sql_sort = 'COALESCE(s.active, 1)'; break;
    case 'last_backup': $sql_sort = 'b.timestamp'; break;
    default:            $sql_sort = 'd.name';
}
$sql_order = ($order === 'desc') ? 'DESC' : 'ASC';

// Hitung total
$count_sql = "SELECT COUNT(*) FROM devices d LEFT JOIN settings s ON s.device_id = d.id WHERE (d.name LIKE ? OR d.ip LIKE ?) {$extra_filters}";
$stmt_count = $pdo->prepare($count_sql);
$stmt_count->execute($params);
$total_rows = $stmt_count->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// Query Data Utama
$sql = "
    SELECT 
        d.id, d.name as device_name, d.ip, 
        s.schedule, s.run_hour, s.run_weekday, s.run_day, s.active,
        b.timestamp as last_backup_time, 
        b.status as last_backup_status
    FROM devices d
    LEFT JOIN settings s ON s.device_id = d.id
    LEFT JOIN (
        SELECT device_id, timestamp, status, ROW_NUMBER() OVER(PARTITION BY device_id ORDER BY timestamp DESC) as rn FROM backups
    ) b ON d.id = b.device_id AND b.rn = 1
    WHERE (d.name LIKE ? OR d.ip LIKE ?) {$extra_filters}
    ORDER BY $sql_sort $sql_order 
    LIMIT {$limit} OFFSET {$offset}";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(); 

include __DIR__ . '/includes/header.php';
?>

<div class="page-header-container">
  <h1 class="page-header">Jadwal Backup Otomatis</h1>
  <p class="page-subtitle">Atur jadwal backup harian, mingguan, atau bulanan untuk setiap perangkat.</p>
  <div class="alert info alert-info-spaced">
    <strong>💡 Info Penjadwalan:</strong> Backup otomatis dijalankan oleh scheduler di server (cron job) setiap jam. Sistem akan mengeksekusi backup jika waktunya cocok dengan jadwal yang Anda atur di bawah.
  </div>
</div>

<div class="form-container form-container--filter">
    <?php $sched_active = (trim($search ?? '') !== '' ? 1 : 0) + (trim($schedule_filter ?? '') !== '' ? 1 : 0) + (trim($status_filter ?? '') !== '' ? 1 : 0); ?>
    <button type="button" class="m-mobile-only m-filter-head" data-toggle="filter-schedule" aria-expanded="false">
        <span>Filter</span>
        <?php if ($sched_active > 0): ?><span class="m-filter-head-count"><?= (int)$sched_active ?> aktif</span><?php endif; ?>
    </button>
    <form method="get" class="auto-filter-form">
        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort_by); ?>">
        <input type="hidden" name="order" value="<?php echo htmlspecialchars($order); ?>">
        <div class="filter-grid filter-grid--toolbar-wide">
            <div class="field">
                <label for="schedule-search">Cari jadwal</label>
                <input type="text" id="schedule-search" name="search" placeholder="Nama perangkat atau alamat IP…" value="<?php echo htmlspecialchars($search); ?>" enterkeyhint="search">
            </div>
            <div class="m-collapsible m-filter-more" id="filter-schedule">
            <div class="field">
                <label for="schedule-freq">Frekuensi</label>
                <select id="schedule-freq" name="schedule_filter">
                    <option value="">Semua frekuensi</option>
                    <option value="daily" <?php if ($schedule_filter === 'daily') echo 'selected'; ?>>Harian</option>
                    <option value="weekly" <?php if ($schedule_filter === 'weekly') echo 'selected'; ?>>Mingguan</option>
                    <option value="monthly" <?php if ($schedule_filter === 'monthly') echo 'selected'; ?>>Bulanan</option>
                </select>
            </div>
            <div class="field">
                <label for="schedule-status">Status</label>
                <select id="schedule-status" name="status_filter">
                    <option value="">Semua status</option>
                    <option value="1" <?php if ($status_filter === '1') echo 'selected'; ?>>Aktif</option>
                    <option value="0" <?php if ($status_filter === '0') echo 'selected'; ?>>Nonaktif</option>
                </select>
            </div>
            <div class="field">
                <label for="schedule-limit">Baris per halaman</label>
                <select id="schedule-limit" name="limit">
                    <?= limit_select_options($allowed_limits, $limit) ?>
                </select>
            </div>
            <div class="filter-grid-actions">
                <?= filter_reset_link('/schedule') ?>
            </div>
            </div>
        </div>
    </form>
</div>

<form method="post" action="/schedule" id="scheduleForm">
  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
  
  <div class="table-container">
    <div class="table-header">
      <div>
        <h2 class="table-title">Pengaturan Jadwal<br><p class="page-subtitle">(Menampilkan <?php echo count($rows); ?> Dari <?php echo $total_rows; ?> Perangkat)</p></h2>
      </div>
      
      <button type="submit" id="btnApplyAll" class="btn success is-hidden">
        Simpan Semua Perubahan
      </button>
    </div>
    
    <div class="table-scroll-wrap">
    <table class="modern-table">
      <thead>
        <tr>
          <?php echo table_sort_link('device_name', 'Perangkat', $sort_by, $order); ?>
          <?php echo table_sort_link('schedule', 'Jadwal', $sort_by, $order); ?>
          <?php echo table_sort_link('run_hour', 'Jam', $sort_by, $order); ?>
          
          <?php echo table_sort_link('run_weekday', 'Hari (Mingguan)', $sort_by, $order); ?>
          <?php echo table_sort_link('run_day', 'Tgl (Bulanan)', $sort_by, $order); ?>
          
          <?php echo table_sort_link('active', 'Status', $sort_by, $order); ?>
          <?php echo table_sort_link('last_backup', 'Backup Terakhir', $sort_by, $order); ?>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php $did = (int)$r['id']; ?>
          <tr class="schedule-row" data-id="<?php echo $did; ?>">
            
            <td class="td-primary" data-label="Perangkat">
              <strong><?php echo htmlspecialchars($r['device_name']); ?></strong><br>
              <small class="small-text-muted"><?php echo htmlspecialchars($r['ip']); ?></small>
              <?php
                $schedLabels = ['daily' => 'Harian', 'weekly' => 'Mingguan', 'monthly' => 'Bulanan'];
                $schedKey = (string)($r['schedule'] ?? 'daily');
                $schedLabel = $schedLabels[$schedKey] ?? $schedKey;
                $hourLabel = str_pad((string)(int)($r['run_hour'] ?? 2), 2, '0', STR_PAD_LEFT) . ':00';
                $activeLabel = ((int)($r['active'] ?? 1) === 1) ? 'Aktif' : 'Nonaktif';
              ?>
              <span class="m-collapsed-extra"><br><?php echo htmlspecialchars($schedLabel . ' · ' . $hourLabel . ' · ' . $activeLabel); ?></span>
            </td>
            <td class="td-field" data-label="Frekuensi">
              <select name="schedule[<?php echo $did; ?>]" class="schedule-select track-input">
                <option value="daily" <?php if (($r['schedule'] ?? 'daily') === 'daily') echo 'selected'; ?>>Harian</option>
                <option value="weekly" <?php if (($r['schedule'] ?? '') === 'weekly') echo 'selected'; ?>>Mingguan</option>
                <option value="monthly" <?php if (($r['schedule'] ?? '') === 'monthly') echo 'selected'; ?>>Bulanan</option>
              </select>
            </td>
            <td class="td-field" data-label="Jam">
              <select name="run_hour[<?php echo $did; ?>]" class="track-input">
                <?php for ($i = 0; $i < 24; $i++): ?>
                  <option value="<?php echo $i; ?>" <?php if ((int)($r['run_hour'] ?? 2) === $i) echo 'selected'; ?>>
                    <?php echo str_pad((string)$i, 2, '0', STR_PAD_LEFT); ?>:00
                  </option>
                <?php endfor; ?>
              </select>
            </td>
            
            <td class="td-field" data-label="Hari (Mingguan)">
                <select name="run_weekday[<?php echo $did; ?>]" class="run-weekday-input track-input is-hidden">
                    <?php foreach($hari_labels as $val => $label): ?>
                        <option value="<?php echo $val; ?>" <?php if ((int)($r['run_weekday'] ?? 6) === $val) echo 'selected'; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </td>

            <td class="td-field" data-label="Tanggal (Bulanan)">
                <input type="number" 
                       class="run-day-input track-input is-hidden" 
                       name="run_day[<?php echo $did; ?>]" 
                       min="1" max="28" 
                       value="<?php echo (int)($r['run_day'] ?? 1); ?>"
                       placeholder="1-28">
            </td>

            <td class="td-field" data-label="Status Aktif">
              <select name="active[<?php echo $did; ?>]" class="track-input">
                  <option value="1" <?php if ((int)($r['active'] ?? 1) === 1) echo 'selected'; ?>>Aktif</option>
                  <option value="0" <?php if ((int)($r['active'] ?? 1) === 0) echo 'selected'; ?>>Nonaktif</option>
              </select>
            </td>
            <td data-label="Backup Terakhir">
              <?php if ($r['last_backup_time']): ?>
                <span class="badge <?php echo $r['last_backup_status'] === 'success' ? 'success' : 'danger'; ?>">
                  <?php echo date('d M Y, H:i', strtotime($r['last_backup_time'])); ?>
                </span>
              <?php else: ?>
                <span class="badge gray">Belum ada</span>
              <?php endif; ?>
            </td>
            <td class="td-actions" data-label="Aksi">
              <button class="btn btn-sm" type="submit" name="single_update" value="<?php echo $did; ?>">Simpan</button>
            </td>
            
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</form>

<div>
    <?php
    $build_url = function ($p) use ($search, $limit, $sort_by, $order, $schedule_filter, $status_filter) {
        return '?' . http_build_query([
            'page' => $p,
            'search' => $search,
            'limit' => $limit,
            'sort' => $sort_by,
            'order' => $order,
            'schedule_filter' => $schedule_filter,
            'status_filter' => $status_filter,
        ]);
    };
    render_pagination((int)$page, (int)$total_pages, $build_url);
    ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('scheduleForm');
    const applyAllBtn = document.getElementById('btnApplyAll');
    
    // 1. Simpan semua nilai input awal
    const initialValues = {};
    document.querySelectorAll('.track-input').forEach(el => {
        initialValues[el.name] = el.value;
    });

    // 2. Fungsi Tampil/Sembunyi input dinamis
    function toggleDynamicInputs(row) {
        const select = row.querySelector('.schedule-select');
        const runDayInput = row.querySelector('.run-day-input');
        const runWeekdayInput = row.querySelector('.run-weekday-input');
        
        if (select && runDayInput && runWeekdayInput) {
            if (select.value === 'monthly') {
                runDayInput.style.display = 'inline-block';
                runWeekdayInput.style.display = 'none';
            } else if (select.value === 'weekly') {
                runDayInput.style.display = 'none';
                runWeekdayInput.style.display = 'inline-block';
            } else { // daily
                runDayInput.style.display = 'none';
                runWeekdayInput.style.display = 'none';
            }
        }
    }

    // 3. Fungsi untuk mengecek berapa perangkat (baris) yang dimodifikasi
    function checkChanges() {
        const modifiedDevices = new Set();
        
        document.querySelectorAll('.track-input').forEach(el => {
            if (el.value !== initialValues[el.name]) {
                const row = el.closest('.schedule-row');
                if (row && row.dataset.id) {
                    modifiedDevices.add(row.dataset.id);
                }
            }
        });

        // Tampilkan tombol "Simpan Semua" HANYA JIKA ADA > 1 PERANGKAT yang diubah
        if (modifiedDevices.size > 1) {
            applyAllBtn.style.display = 'inline-flex';
        } else {
            applyAllBtn.style.display = 'none';
        }
    }

    // 4. Inisialisasi on-load
    const rows = document.querySelectorAll('.schedule-row');
    rows.forEach(row => {
        toggleDynamicInputs(row); 
        
        const select = row.querySelector('.schedule-select');
        if (select) {
            select.addEventListener('change', function() {
                toggleDynamicInputs(row);
            });
        }
    });

    // 5. Deteksi perubahan setiap ada input yang diganti
    if (form) {
        form.addEventListener('change', function(event) {
            if (event.target.classList.contains('track-input')) {
                checkChanges();
            }
        });
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
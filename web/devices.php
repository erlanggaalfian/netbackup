<?php
require_once __DIR__ . '/../config/db.php';
require_login();

// --- LOGIKA HAPUS PERANGKAT (Diedit agar semua user bisa) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    if (verify_csrf(post('csrf'))) { // Kondisi $is_admin dihapus
        $id = (int)post('id');
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM devices WHERE id = ?");
            $stmt->execute([$id]);
            app_log('device_delete', 'Menghapus perangkat id=' . $id, current_user()['id'], $id);
            header("Location: /devices?status=deleted");
            exit;
        }
    }
}

$vendors = $pdo->query("SELECT id, name FROM vendor ORDER BY name")->fetchAll();

// --- LOGIKA FILTER DAN PENCARIAN ---
$search = get('search') ?? '';
$vendor_id = (int)(get('vendor_id') ?? 0);
$limit = in_array((int)(get('limit') ?? 50), [25, 50, 100, 200]) ? (int)(get('limit') ?? 50) : 50;

$sql = 'SELECT d.id, d.name, d.ip, d.location, v.name AS vendor_name 
        FROM devices d 
        LEFT JOIN vendor v ON v.id=d.vendor_id 
        WHERE (d.name LIKE ? OR d.ip LIKE ? OR d.location LIKE ?)';
$params = ['%' . $search . '%', '%' . $search . '%', '%' . $search . '%'];

if ($vendor_id > 0) {
    $sql .= ' AND d.vendor_id = ?';
    $params[] = $vendor_id;
}
$sql .= ' ORDER BY d.name LIMIT ' . $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$devices = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1 class="page-title">Manajemen Perangkat</h1>
  <p class="page-subtitle">Cari, filter, dan pantau status konektivitas perangkat Anda secara real-time.</p>
</div>

<div class="form-container form-container-full-width">
    <form method="get">
        <div class="filter-form flex-gap">
            <div class="field field-grow">
                <label>Pencarian</label>
                <input type="text" name="search" placeholder="Cari nama, IP, atau lokasi..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="field field-grow">
                <label>Vendor</label>
                <select name="vendor_id">
                    <option value="">Semua Vendor</option>
                    <?php foreach ($vendors as $vendor): ?>
                        <option value="<?php echo $vendor['id']; ?>" <?php if ($vendor['id'] == $vendor_id) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($vendor['name']); ?>
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
        </div>
        <div class="action-buttons mt-1">
            <button type="submit" class="btn">Filter</button>
            <a href="/devices" class="btn secondary">Reset</a>
        </div>
    </form>
</div>

<div class="table-container">
  <div class="table-header">
    <h2 class="table-title">Daftar Perangkat (<?php echo count($devices); ?> ditemukan)</h2>
    <!-- Tombol Tambah Perangkat ditampilkan untuk semua user -->
    <a class="btn btn-sm" href="/add_device">Tambah Perangkat</a>
  </div>
  <table class="modern-table">
    <thead>
      <tr>
        <th>Perangkat</th>
        <th>Lokasi</th>
        <th>Vendor</th>
        <th>Status Aksi</th>
        <th>Aksi</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($devices)): ?>
        <tr><td colspan="5" class="table-cell-center">Tidak ada perangkat yang cocok dengan kriteria.</td></tr>
      <?php else: ?>
        <?php foreach ($devices as $d): ?>
          <tr data-device-id="<?php echo (int)$d['id']; ?>">
            <td>
              <strong><?php echo htmlspecialchars($d['name']); ?></strong><br>
              <small class="small-text-muted"><?php echo htmlspecialchars($d['ip']); ?></small>
            </td>
            <td><?php echo htmlspecialchars($d['location'] ?? '-'); ?></td>
            <td><span class="badge blue"><?php echo htmlspecialchars($d['vendor_name'] ?? 'N/A'); ?></span></td>
            <td id="action-status-<?php echo (int)$d['id']; ?>">
              <div class="status-container">
                <div class="icmp-status" id="icmp-status-<?php echo (int)$d['id']; ?>">
                  <span class="badge gray">ICMP: Mengecek...</span>
                </div>
                <div class="ssh-status" id="ssh-status-<?php echo (int)$d['id']; ?>">
                  <span class="badge gray">SSH/Telnet: Siap</span>
                </div>
              </div>
            </td>
            <td>
              <div class="action-buttons">
                 <button class="btn btn-sm" title="Segarkan Status" onclick="checkDeviceStatus(<?php echo (int)$d['id']; ?>, this)">Refresh</button>
                 <button class="btn btn-sm" title="Backup Manual" onclick="manualBackup(<?php echo (int)$d['id']; ?>, '<?php echo htmlspecialchars(addslashes($d['name'])); ?>', this)">Backup</button>
                 <a class="btn btn-sm secondary" href="/edit_device?id=<?php echo (int)$d['id']; ?>">Ubah</a>
                 <!-- Tombol Hapus ditampilkan untuk semua user -->
                 <form method="POST" onsubmit="return confirm('Anda yakin ingin menghapus perangkat ini dan semua riwayat backup-nya?');" class="inline">
                     <input type="hidden" name="action" value="delete">
                     <input type="hidden" name="id" value="<?php echo (int)$d['id']; ?>">
                     <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                     <button type="submit" class="btn btn-sm danger" title="Hapus Perangkat">Hapus</button>
                 </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
  <input type="hidden" id="csrf-token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
</div>

<script>
async function checkICMPStatus(deviceId) {
    const icmpElement = document.getElementById(`icmp-status-${deviceId}`);
    if (!icmpElement) return;
    
    try {
        const response = await fetch(`/api_check_icmp.php?id=${deviceId}`);
        const data = await response.json();
        
        if (data.success) {
            if (data.icmp_status === true) {
                icmpElement.innerHTML = '<span class="badge success">ICMP: Aktif</span>';
            } else {
                icmpElement.innerHTML = '<span class="badge danger">ICMP: Tidak Aktif</span>';
            }
        } else {
            icmpElement.innerHTML = '<span class="badge danger">ICMP: Gagal</span>';
        }
    } catch (error) {
        icmpElement.innerHTML = '<span class="badge danger">ICMP: Gagal</span>';
    }
}

function updateSSHStatus(deviceId, status, message) {
    const sshElement = document.getElementById(`ssh-status-${deviceId}`);
    if (!sshElement) return;
    sshElement.innerHTML = `<span class="badge ${status}">${message}</span>`;
}

async function checkDeviceStatus(deviceId, button = null) {
    const originalButtonContent = button ? button.innerHTML : null;
    if (button) {
        button.innerHTML = '...'; button.disabled = true;
    }

    try {
        const response = await fetch(`/api_check_status.php?id=${deviceId}`);
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.error || 'Kesalahan jaringan');
        
        if (data.tcp_status) {
            updateSSHStatus(deviceId, 'success', `${data.protocol}: Terhubung`);
        } else {
            updateSSHStatus(deviceId, 'danger', `${data.protocol}: Gagal`);
        }
    } catch (error) {
        updateSSHStatus(deviceId, 'danger', 'Galat');
    } finally {
        if (button) {
            setTimeout(() => {
                button.innerHTML = originalButtonContent; button.disabled = false;
            }, 500);
        }
    }
}

async function manualBackup(deviceId, deviceName, button) {
    if (!confirm(`Lakukan backup manual untuk perangkat "${deviceName}"?`)) return;
    
    const originalButtonContent = button.innerHTML;
    button.innerHTML = '...';
    button.disabled = true;
    updateSSHStatus(deviceId, 'blue', 'Proses backup...');
    
    const csrfToken = document.getElementById('csrf-token').value;
    const formData = new URLSearchParams();
    formData.append('device_id', deviceId);
    formData.append('csrf', csrfToken);

    try {
        const response = await fetch('/backup_manual', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        });
        const data = await response.json();
        if (data.success) {
            updateSSHStatus(deviceId, 'success', 'Backup Berhasil!');
            alert(`✅ Backup berhasil!\nFile: ${data.filename}`);
        } else {
            updateSSHStatus(deviceId, 'danger', 'Backup Gagal!');
            alert(`❌ Backup gagal!\nError: ${data.error}`);
        }
    } catch (error) {
        updateSSHStatus(deviceId, 'danger', 'Error!');
        alert(`❌ Terjadi error: ${error.message}`);
    } finally {
        button.innerHTML = originalButtonContent;
        button.disabled = false;
        
        setTimeout(() => {
            updateSSHStatus(deviceId, 'gray', 'SSH/Telnet: Siap');
        }, 3000);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const devices = document.querySelectorAll('tr[data-device-id]');
    
    function checkAllDevices() {
        devices.forEach(deviceRow => {
            const deviceId = deviceRow.getAttribute('data-device-id');
            if (deviceId) {
                checkICMPStatus(deviceId);
                checkDeviceStatus(deviceId, null);
            }
        });
    }

    checkAllDevices();
    setInterval(checkAllDevices, 60000); // Refresh setiap 1 menit
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

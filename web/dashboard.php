<?php
require_once __DIR__ . '/../config/db.php';
require_login();

// 1. Total Perangkat (ringan — tetap real-time)
$total_devices = (int)$pdo->query('SELECT COUNT(*) FROM devices')->fetchColumn();

// 2–7. Statistik backup & riwayat (cache 90 detik)
$dashStats = fetch_dashboard_stats($pdo);
$backup_metrics = $dashStats['backup_metrics'] ?? [];

$today_s = (int)($backup_metrics['today_s'] ?? 0);
$today_f = (int)($backup_metrics['today_f'] ?? 0);
$week_s = (int)($backup_metrics['week_s'] ?? 0);
$week_f = (int)($backup_metrics['week_f'] ?? 0);
$month_s = (int)($backup_metrics['month_s'] ?? 0);
$month_f = (int)($backup_metrics['month_f'] ?? 0);
$year_s = (int)($backup_metrics['year_s'] ?? 0);
$year_f = (int)($backup_metrics['year_f'] ?? 0);

// 6. Metrik Jadwal Aktif
try {
    $total_schedules = $pdo->query("SELECT COUNT(*) FROM settings WHERE active = 1")->fetchColumn();
} catch (PDOException $e) {
    $total_schedules = 0;
}

$top_errors = $dashStats['top_errors'] ?? [];
$failure_feed = $dashStats['failure_feed'] ?? ['still_failed_count' => 0, 'items' => []];
$failure_items = $failure_feed['items'] ?? [];
$still_failed_count = (int)($failure_feed['still_failed_count'] ?? 0);
$recent_backups = $dashStats['recent_backups'] ?? [];
$unscheduled_devices = $dashStats['unscheduled_devices'] ?? [];

$connectivity_snapshot = get_device_connectivity_snapshot(true);
$initial_connectivity = build_dashboard_connectivity_payload($connectivity_snapshot, $pdo);

$page_body_class = 'page-heavy';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header-container motion-fade-up dashboard-page-header">
  <h1 class="page-header">Dashboard Analitik</h1>
  <p class="page-subtitle">Ringkasan aktivitas backup jaringan Anda.</p>
</div>

<div class="form-container motion-fade-up dashboard-section scroll-reveal">
<h2 class="section-title mt-0">Keberhasilan Backup</h2>
<div class="chart-grid dashboard-chart-grid">
    
    <div class="chart-card is-chart-pending">
        <div class="chart-title"><span class="chart-label-full">Hari Ini</span><span class="chart-label-short">Hari Ini</span></div>
        <div class="chart-flex-container">
            <div class="custom-legend legend-wrapper" id="legend-today"></div>
            <div class="chart-canvas-wrapper">
                <canvas id="chartToday"></canvas>
                <div class="chart-donut-center" aria-hidden="true"></div>
            </div>
        </div>
    </div>

    <div class="chart-card is-chart-pending">
        <div class="chart-title"><span class="chart-label-full">7 Hari Terakhir</span><span class="chart-label-short">7 Hari</span></div>
        <div class="chart-flex-container">
            <div class="custom-legend legend-wrapper" id="legend-week"></div>
            <div class="chart-canvas-wrapper">
                <canvas id="chartWeek"></canvas>
                <div class="chart-donut-center" aria-hidden="true"></div>
            </div>
        </div>
    </div>

    <div class="chart-card is-chart-pending">
        <div class="chart-title"><span class="chart-label-full">30 Hari Terakhir</span><span class="chart-label-short">30 Hari</span></div>
        <div class="chart-flex-container">
            <div class="custom-legend legend-wrapper" id="legend-month"></div>
            <div class="chart-canvas-wrapper">
                <canvas id="chartMonth"></canvas>
                <div class="chart-donut-center" aria-hidden="true"></div>
            </div>
        </div>
    </div>

    <div class="chart-card is-chart-pending">
        <div class="chart-title"><span class="chart-label-full">365 Hari Terakhir</span><span class="chart-label-short">365 Hari</span></div>
        <div class="chart-flex-container">
            <div class="custom-legend legend-wrapper" id="legend-year"></div>
            <div class="chart-canvas-wrapper">
                <canvas id="chartYear"></canvas>
                <div class="chart-donut-center" aria-hidden="true"></div>
            </div>
        </div>
    </div>

</div>
</div>

<div class="form-container motion-fade-up dashboard-section scroll-reveal">
    <h2 class="section-title mt-0">Informasi Sistem & Konektivitas</h2>

    <div class="grid-row-2 metric-grid">
        <div class="metric-card motion-fade-up motion-fade-up">
            <div class="metric-title">Total Perangkat</div>
            <div class="metric-value text-primary"><?php echo number_format($total_devices); ?></div>
            <div class="metric-subtitle">Terdaftar di sistem</div>
        </div>
        
        <div class="metric-card motion-fade-up motion-fade-up">
            <div class="metric-title">Jadwal Backup</div>
            <div class="metric-value text-warning"><?php echo number_format((int)$total_schedules); ?></div>
            <div class="metric-subtitle">Rutinitas aktif</div>
        </div>
                </div>

    <div class="connectivity-panel" id="connectivity-panel">
        <div class="connectivity-panel-header">
            <div>
                <div class="connectivity-panel-title">Konektivitas Perangkat</div>
                <div class="connectivity-panel-sub" id="connectivity-updated-at"><?php
                    if (!empty($initial_connectivity['checked_at'])) {
                        echo htmlspecialchars(date('d/m/Y H:i', strtotime($initial_connectivity['checked_at'])));
                        if (!empty($initial_connectivity['stale'])) {
                            echo ' (cache)';
                        }
                    } else {
                        echo 'Menunggu pemeriksaan cron…';
                    }
                ?></div>
            </div>
            <div class="connectivity-panel-actions">
                <button type="button" class="btn btn-sm secondary" id="connectivity-refresh-btn" title="Cek ulang semua perangkat">Refresh</button>
                <div class="loading-bar connectivity-loading" id="connectivity-loading"><div class="progress"></div></div>
            </div>
        </div>

        <div class="connectivity-stats-row">
            <div class="connectivity-stat-block">
                <div class="connectivity-stat-label">Ping (ICMP)</div>
                <div class="connectivity-stat-pills" id="ping-stat-pills">
                    <span class="conn-pill conn-pill-muted">—</span>
                </div>
            </div>
            <div class="connectivity-stat-divider" aria-hidden="true"></div>
            <div class="connectivity-stat-block">
                <div class="connectivity-stat-label">SSH / Telnet</div>
                <div class="connectivity-stat-pills" id="interconnect-stat-pills">
                    <span class="conn-pill conn-pill-muted">—</span>
                </div>
            </div>
        </div>

        <div class="connectivity-fail-wrap" id="connectivity-fail-wrap"></div>
    </div>
</div>

<div class="form-container motion-fade-up dashboard-section scroll-reveal">
    <h2 class="section-title mt-0">Riwayat & Pemantauan Sistem</h2>
    
    <div class="grid-row-1">
        <div class="dashboard-panel dashboard-panel-alert dashboard-failure-feed">
            <div class="card-header-flex">
                <div>
                    <div class="chart-title">Kegagalan Backup <button type="button" class="btn btn-sm secondary m-mobile-only" data-toggle="dash-fail-feed" data-show-text="Tampilkan" data-hide-text="Sembunyikan" aria-expanded="false">Tampilkan</button></div>
                    <p class="page-subtitle dashboard-panel-sub">
                        <?php if ($still_failed_count > 0): ?>
                            <?php echo (int)$still_failed_count; ?> perangkat masih gagal ·
                        <?php endif; ?>
                        <?php echo (int)DASHBOARD_FAILURE_FEED_LIMIT; ?> kejadian terbaru (semua waktu)
                    </p>
                </div>
                <a href="/backups.php?sort=status&amp;order=asc" class="btn secondary btn-sm">Lihat Semua</a>
            </div>
            <div class="m-collapsible" id="dash-fail-feed">
            <ul class="list-group dashboard-failure-feed-list">
                <?php foreach ($failure_items as $row): ?>
                <?php
                    $isActive = !empty($row['is_still_failed']);
                    $deviceName = (string)($row['device_name'] ?? '');
                    $deviceIp = (string)($row['device_ip'] ?? '');
                ?>
                <li class="list-item dashboard-failure-feed-item<?php echo $isActive ? ' is-active' : ''; ?>">
                    <div class="dashboard-failure-feed-main">
                        <strong><?php echo htmlspecialchars($deviceName); ?></strong>
                        <span class="dashboard-failure-feed-meta">
                            <?php echo htmlspecialchars($deviceIp !== '' ? $deviceIp : '-'); ?>
                            · <?php echo date('d/m/Y H:i', strtotime($row['timestamp'])); ?>
                        </span>
                        <span class="dashboard-failure-feed-hint">
                            <?php if ($isActive): ?>
                                Backup terakhir perangkat ini masih gagal — perlu tindakan.
                            <?php else: ?>
                                Riwayat kegagalan — backup terakhir perangkat sudah sukses.
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="dashboard-failure-feed-side">
                        <span class="badge <?php echo $isActive ? 'danger' : 'gray'; ?>">
                            <?php echo $isActive ? 'Masih gagal' : 'Riwayat'; ?>
                        </span>
                        <a href="/view_backup.php?id=<?php echo (int)$row['backup_id']; ?>" class="btn btn-sm secondary">Detail</a>
                    </div>
                </li>
                <?php endforeach; ?>
                <?php if (empty($failure_items)): ?>
                <li class="list-item text-success">Tidak ada kegagalan backup tercatat.</li>
                <?php endif; ?>
            </ul>
            </div>
        </div>
    </div>

    <div class="grid-row-1">
        <div class="dashboard-panel">
                <div class="chart-title">Top 5 Sering Gagal (All Time) <button type="button" class="btn btn-sm secondary m-mobile-only" data-toggle="dash-top-fail" data-show-text="Tampilkan" data-hide-text="Sembunyikan" aria-expanded="false">Tampilkan</button></div>
            <div class="m-collapsible" id="dash-top-fail">
            <ul class="list-group">
                <?php foreach ($top_errors as $e): ?>
                <li class="list-item">
                    <span><?php echo htmlspecialchars($e['name']); ?></span>
                    <span class="badge danger"><?php echo $e['fails']; ?>x Gagal</span>
                </li>
                <?php endforeach; ?>
                <?php if(empty($top_errors)) echo '<li class="list-item text-success">Luar biasa! Tidak ada perangkat yang gagal.</li>'; ?>
            </ul>
            </div>
        </div>
    </div>

    <div class="grid-row-2 dashboard-monitor-grid">
        
        <div class="dashboard-panel">
            <div class="card-header-flex">
                <div class="chart-title">Aktivitas Backup Terbaru <button type="button" class="btn btn-sm secondary m-mobile-only" data-toggle="dash-activity" data-show-text="Tampilkan" data-hide-text="Sembunyikan" aria-expanded="false">Tampilkan</button></div>
                <a href="/backups.php" class="btn secondary btn-sm">Lihat Semua</a>
            </div>
            <div class="m-collapsible" id="dash-activity">
            <ul class="list-group dashboard-activity-list">
                <?php foreach ($recent_backups as $b): ?>
                <li class="list-item dashboard-activity-item">
                    <div class="dashboard-activity-main">
                        <strong><?php echo htmlspecialchars($b['device_name']); ?></strong>
                        <span class="dashboard-activity-time"><?php echo date('d/m/Y H:i', strtotime($b['timestamp'])); ?></span>
                    </div>
                    <span class="badge <?php echo is_backup_failed($b['status'] ?? '') ? 'danger' : 'success'; ?>">
                        <?php echo is_backup_failed($b['status'] ?? '') ? 'Gagal' : 'Berhasil'; ?>
                    </span>
                </li>
                <?php endforeach; ?>
                <?php if(empty($recent_backups)): ?>
                <li class="list-item text-success">Belum ada aktivitas.</li>
                <?php endif; ?>
            </ul>
            </div>
        </div>

        <div class="dashboard-panel">
            <div class="card-header-flex">
                <div class="chart-title">Perangkat Tanpa Jadwal Aktif <button type="button" class="btn btn-sm secondary m-mobile-only" data-toggle="dash-unscheduled" data-show-text="Tampilkan" data-hide-text="Sembunyikan" aria-expanded="false">Tampilkan</button></div>
                <a href="/schedule.php" class="btn secondary btn-sm">Atur Jadwal</a>
            </div>
            <div class="m-collapsible" id="dash-unscheduled">
            <ul class="list-group">
                <?php foreach ($unscheduled_devices as $ud): ?>
                <li class="list-item">
                    <span><?php echo htmlspecialchars($ud['name']); ?></span>
                    <span class="badge gray">Belum diatur</span>
                </li>
                <?php endforeach; ?>
                <?php if(empty($unscheduled_devices)) echo '<li class="list-item text-success">Semua perangkat memiliki jadwal aktif!</li>'; ?>
            </ul>
            </div>
        </div>

    </div>
</div>

<script>
(function () {
    const REFRESH_MS = <?php echo (int)DEVICE_CONNECTIVITY_TTL * 1000; ?>;
    const initialConnectivity = <?php echo json_encode($initial_connectivity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); ?>;
    const CHART_METRICS = {
        today: { canvas: 'chartToday', legend: 'legend-today', success: <?php echo (int)$today_s; ?>, failed: <?php echo (int)$today_f; ?> },
        week: { canvas: 'chartWeek', legend: 'legend-week', success: <?php echo (int)$week_s; ?>, failed: <?php echo (int)$week_f; ?> },
        month: { canvas: 'chartMonth', legend: 'legend-month', success: <?php echo (int)$month_s; ?>, failed: <?php echo (int)$month_f; ?> },
        year: { canvas: 'chartYear', legend: 'legend-year', success: <?php echo (int)$year_s; ?>, failed: <?php echo (int)$year_f; ?> }
    };

    const connectivityPanel = document.getElementById('connectivity-panel');
    const connectivityLoading = document.getElementById('connectivity-loading');
    const connectivityUpdatedEl = document.getElementById('connectivity-updated-at');
    const pingPillsEl = document.getElementById('ping-stat-pills');
    const icPillsEl = document.getElementById('interconnect-stat-pills');
    const failWrapEl = document.getElementById('connectivity-fail-wrap');
    const totalDevices = <?php echo (int)$total_devices; ?>;

    function setConnectivityLoading(isLoading) {
        if (connectivityPanel) {
            connectivityPanel.classList.toggle('is-loading', isLoading);
        }
        if (connectivityLoading) {
            connectivityLoading.style.display = isLoading ? 'block' : 'none';
        }
    }

    function formatCheckedAt(isoString, stale) {
        if (!isoString) return 'Belum diperiksa';
        const d = new Date(isoString);
        if (Number.isNaN(d.getTime())) return 'Diperbarui dari server';
        const prefix = stale ? 'Data cache · ' : 'Diperbarui ';
        return prefix + d.toLocaleString('id-ID') + ' · otomatis setiap <?php echo (int)(DEVICE_CONNECTIVITY_TTL / 60); ?> menit';
    }

    function renderPills(container, stats, labels) {
        if (!container) return;
        const parts = [];
        if (stats.ok > 0) {
            parts.push('<span class="conn-pill conn-pill-ok">' + stats.ok + ' ' + labels.ok + '</span>');
        }
        if (stats.failed > 0) {
            parts.push('<span class="conn-pill conn-pill-fail">' + stats.failed + ' ' + labels.fail + '</span>');
        }
        if (stats.unknown > 0) {
            parts.push('<span class="conn-pill conn-pill-unknown">' + stats.unknown + ' ' + labels.unknown + '</span>');
        }
        if (parts.length === 0) {
            container.innerHTML = '<span class="conn-pill conn-pill-muted">Tidak ada data</span>';
            return;
        }
        container.innerHTML = parts.join('');
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function deviceLink(device) {
        const q = encodeURIComponent(device.name || '');
        return 'devices.php?search=' + q;
    }

    function renderPingFailList(devices) {
        if (!devices || devices.length === 0) return '';
        const items = devices.map(function (d) {
            return '<li class="connectivity-fail-item">' +
                '<a href="' + deviceLink(d) + '" class="connectivity-fail-btn">' +
                '<span class="connectivity-fail-name">' + escapeHtml(d.name) + '</span>' +
                '<span class="connectivity-fail-meta">tidak merespons ping</span>' +
                '</a></li>';
        }).join('');
        return '<div class="connectivity-fail-section">' +
            '<div class="connectivity-fail-heading">Ping gagal (' + devices.length + ')' +
            '<button type="button" class="btn btn-sm secondary m-mobile-only" data-toggle="conn-fail-ping" data-show-text="Tampilkan" data-hide-text="Sembunyikan" aria-expanded="false">Tampilkan</button></div>' +
            '<div class="m-collapsible" id="conn-fail-ping">' +
            '<ul class="connectivity-fail-list">' + items + '</ul></div></div>';
    }

    function renderInterconnectFailList(devices) {
        if (!devices || devices.length === 0) return '';
        const items = devices.map(function (d) {
            const proto = escapeHtml(d.protocol || 'SSH');
            const port = parseInt(d.port, 10) || (proto === 'Telnet' ? 23 : 22);
            return '<li class="connectivity-fail-item">' +
                '<a href="' + deviceLink(d) + '" class="connectivity-fail-btn">' +
                '<span class="connectivity-fail-name">' + escapeHtml(d.name) + '</span>' +
                '<span class="connectivity-fail-meta">' + proto + ' port ' + port + ' gagal</span>' +
                '</a></li>';
        }).join('');
        return '<div class="connectivity-fail-section">' +
            '<div class="connectivity-fail-heading">Koneksi gagal (' + devices.length + ')' +
            '<button type="button" class="btn btn-sm secondary m-mobile-only" data-toggle="conn-fail-interconnect" data-show-text="Tampilkan" data-hide-text="Sembunyikan" aria-expanded="false">Tampilkan</button></div>' +
            '<div class="m-collapsible" id="conn-fail-interconnect">' +
            '<ul class="connectivity-fail-list">' + items + '</ul></div></div>';
    }

    function applyConnectivityData(payload) {
        const ping = payload.ping || { ok: 0, failed: 0, unknown: 0 };
        const ic = payload.interconnect || { ok: 0, failed: 0 };
        const failed = payload.failed || { ping: [], interconnect: [] };

        renderPills(pingPillsEl, ping, { ok: 'OK', fail: 'Gagal', unknown: 'Tidak diketahui' });
        renderPills(icPillsEl, ic, { ok: 'OK', fail: 'Gagal', unknown: '' });

        if (connectivityUpdatedEl) {
            if (!payload.checked_at) {
                connectivityUpdatedEl.textContent = 'Menunggu pemeriksaan background…';
            } else {
                connectivityUpdatedEl.textContent = formatCheckedAt(payload.checked_at, !!payload.stale);
            }
        }

        if (!failWrapEl) return;

        if (!payload.checked_at) {
            failWrapEl.innerHTML = '<p class="connectivity-all-ok connectivity-muted">Menunggu pemeriksaan background…</p>';
            return;
        }

        const pingFail = failed.ping || [];
        const icFail = failed.interconnect || [];
        const totalFail = pingFail.length + icFail.length;

        if (totalFail === 0 && totalDevices > 0) {
            failWrapEl.innerHTML = '<p class="connectivity-all-ok">Semua perangkat merespons ping dan koneksi SSH/Telnet.</p>';
            return;
        }
        if (totalDevices === 0) {
            failWrapEl.innerHTML = '<p class="connectivity-all-ok connectivity-muted">Belum ada perangkat terdaftar.</p>';
            return;
        }

        const html = renderPingFailList(pingFail) + renderInterconnectFailList(icFail);
        failWrapEl.innerHTML = html || '<p class="connectivity-all-ok connectivity-muted">Tidak ada gangguan terdeteksi.</p>';
    }

    async function fetchConnectivity(url, showLoader) {
        if (totalDevices === 0) {
            setConnectivityLoading(false);
            if (connectivityUpdatedEl) connectivityUpdatedEl.textContent = 'Tidak ada perangkat terdaftar';
            applyConnectivityData({ ping: { ok: 0, failed: 0, unknown: 0 }, interconnect: { ok: 0, failed: 0 }, failed: { ping: [], interconnect: [] } });
            return;
        }

        if (showLoader) setConnectivityLoading(true);
        try {
            const response = await fetch(url, { credentials: 'same-origin' });
            const payload = await response.json();
            if (!response.ok || !payload.success) {
                throw new Error(payload.error || 'Gagal memuat status');
            }
            applyConnectivityData(payload);
        } catch (err) {
            if (connectivityUpdatedEl) {
                connectivityUpdatedEl.textContent = showLoader ? 'Gagal memuat status dari server' : connectivityUpdatedEl.textContent;
            }
            if (showLoader && failWrapEl) failWrapEl.innerHTML = '';
        } finally {
            if (showLoader) setConnectivityLoading(false);
        }
    }

    async function loadConnectivityCache() {
        await fetchConnectivity('api_dashboard_connectivity.php', false);
    }

    async function refreshConnectivityManual() {
        const btn = document.getElementById('connectivity-refresh-btn');
        if (btn) btn.disabled = true;
        await fetchConnectivity('api_dashboard_connectivity.php?refresh=1', true);
        if (btn) btn.disabled = false;
    }

    if (initialConnectivity && initialConnectivity.success) {
        applyConnectivityData(initialConnectivity);
    } else {
        loadConnectivityCache();
    }
    setInterval(loadConnectivityCache, REFRESH_MS);

    const refreshBtn = document.getElementById('connectivity-refresh-btn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', refreshConnectivityManual);
    }

    function loadChartJs() {
        if (window.Chart) return Promise.resolve();
        return new Promise(function (resolve, reject) {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/chart.js';
            script.async = true;
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

const rootStyles = getComputedStyle(document.documentElement);
const COLOR_SUCCESS = rootStyles.getPropertyValue('--chart-success').trim() || '#10b981';
    const COLOR_SUCCESS_LIGHT = rootStyles.getPropertyValue('--g-green-200').trim() || '#a7f3d0';
const COLOR_FAILED = rootStyles.getPropertyValue('--chart-failed').trim() || '#ef4444';
    const COLOR_FAILED_LIGHT = rootStyles.getPropertyValue('--g-red-200').trim() || '#fecaca';
const COLOR_WAITING = rootStyles.getPropertyValue('--chart-waiting').trim() || '#e2e8f0';
    const COLOR_TRACK = rootStyles.getPropertyValue('--g-slate-100').trim() || '#f1f5f9';
const legendClasses = ['bg-chart-success', 'bg-chart-failed', 'bg-chart-waiting'];

    function successRateClass(rate) {
        if (rate >= 90) return 'is-success';
        if (rate >= 70) return 'is-good';
        if (rate >= 50) return 'is-warning';
        if (rate > 0) return 'is-danger';
        return 'is-empty';
    }

    function formatChartPercent(value, total, opts) {
        opts = opts || {};
        if (total <= 0 || value <= 0) {
            return '0%';
        }
        if (value >= total) {
            return '100%';
        }
        const pct = (value / total) * 100;
        const rounded = Math.round(pct);
        const needsDecimal = opts.forceDecimal
            || (rounded === 0 && value > 0)
            || (rounded === 100 && value < total);
        if (needsDecimal) {
            return pct.toLocaleString('id-ID', {
                minimumFractionDigits: 1,
                maximumFractionDigits: 1
            }) + '%';
        }
        return rounded + '%';
    }

    function formatSuccessRateText(successCount, failedCount) {
        const total = successCount + failedCount;
        if (total === 0) {
            return { text: '—', rate: 0 };
        }
        const rate = (successCount / total) * 100;
        if (failedCount === 0) {
            return { text: '100%', rate: 100 };
        }
        if (successCount === 0) {
            return { text: '0%', rate: 0 };
        }
        return {
            text: rate.toLocaleString('id-ID', {
                minimumFractionDigits: 1,
                maximumFractionDigits: 1
            }) + '%',
            rate: rate
        };
    }

    function updateDonutCenter(wrapper, successCount, failedCount) {
        if (!wrapper) return;
        const center = wrapper.querySelector('.chart-donut-center');
        if (!center) return;

        const total = successCount + failedCount;
        if (total === 0) {
            center.innerHTML =
                '<span class="chart-donut-rate is-empty">—</span>' +
                '<span class="chart-donut-label">Belum ada data</span>';
            return;
        }

        const formatted = formatSuccessRateText(successCount, failedCount);
        center.innerHTML =
            '<span class="chart-donut-rate ' + successRateClass(formatted.rate) + '">' + formatted.text + '</span>' +
            '<span class="chart-donut-label">Berhasil</span>' +
            '<span class="chart-donut-total">' + total + ' backup</span>';
    }

    function buildDonutDataset(successCount, failedCount) {
        const total = successCount + failedCount;
        const real = [successCount, failedCount];

        if (total === 0) {
            return {
                labels: ['Belum ada data'],
                data: [1],
                colors: [COLOR_WAITING],
                hoverColors: [COLOR_WAITING],
                real: real,
                spacing: 0,
                borderRadius: 0,
                isEmpty: true,
                isSingle: true
            };
        }

        if (successCount === 0 || failedCount === 0) {
            const isSuccess = successCount > 0;
            return {
                labels: ['Berhasil', 'Gagal'],
                data: [total],
                segmentLabels: isSuccess ? ['Berhasil'] : ['Gagal'],
                colors: isSuccess ? [COLOR_SUCCESS] : [COLOR_FAILED],
                hoverColors: isSuccess ? [COLOR_SUCCESS_LIGHT] : [COLOR_FAILED_LIGHT],
                real: real,
                spacing: 0,
                borderRadius: 0,
                isEmpty: false,
                isSingle: true,
                activeIndex: isSuccess ? 0 : 1
            };
        }

        const MIN_PCT = 5;
        let dSuccess = successCount;
        let dFailed = failedCount;
        const sPct = (successCount / total) * 100;
        const fPct = (failedCount / total) * 100;

        if (sPct < MIN_PCT || fPct < MIN_PCT) {
            const minVal = (MIN_PCT / 100) * total;
            dSuccess = successCount > 0 ? Math.max(successCount, minVal) : 0;
            dFailed = failedCount > 0 ? Math.max(failedCount, minVal) : 0;
            const sum = dSuccess + dFailed;
            dSuccess = (dSuccess / sum) * total;
            dFailed = (dFailed / sum) * total;
        }

        return {
            labels: ['Berhasil', 'Gagal'],
            data: [dSuccess, dFailed],
            colors: [COLOR_SUCCESS, COLOR_FAILED],
            hoverColors: [COLOR_SUCCESS_LIGHT, COLOR_FAILED_LIGHT],
            real: real,
            spacing: 3,
            borderRadius: 8,
            isEmpty: false,
            isSingle: false
        };
    }

    function donutTooltipLabel(context) {
        const chart = context.chart;
        const real = chart.$donutReal || [];
        const labels = chart.$donutLabels || ['Berhasil', 'Gagal'];
        let idx = context.dataIndex;
        if (chart.$donutSingleIndex !== undefined) {
            idx = chart.$donutSingleIndex;
        }
        const label = labels[idx] || context.label || '';
        const value = real[idx] !== undefined ? real[idx] : (context.raw || 0);
        const total = real.reduce(function (a, b) { return a + b; }, 0);
        const percentage = total > 0 ? ((value / total) * 100).toFixed(1) + '%' : '0%';
        return label + ': ' + value + ' (' + percentage + ')';
    }

    function bindLegendChartInteraction(chart, legendId) {
        const container = document.getElementById(legendId);
        if (!container || !chart) return;

        const real = chart.$donutReal || [];
        const total = real.reduce(function (a, b) { return a + b; }, 0);
        const labels = chart.$donutLabels || ['Berhasil', 'Gagal'];

        container.querySelectorAll('.legend-item[data-legend-index]').forEach(function (item) {
            const idx = parseInt(item.getAttribute('data-legend-index'), 10);
            const value = real[idx] || 0;
            const pct = total > 0 ? ((value / total) * 100).toFixed(1) + '%' : '0%';
            item.setAttribute('title', labels[idx] + ': ' + value + ' (' + pct + ')');

            item.addEventListener('mouseenter', function () {
                const meta = chart.getDatasetMeta(0);
                if (!meta || !meta.data.length) return;

                let arcIndex = idx;
                if (chart.$donutSingleIndex !== undefined) {
                    if (idx !== chart.$donutSingleIndex) return;
                    arcIndex = 0;
                }
                if (!meta.data[arcIndex]) return;

                const arc = meta.data[arcIndex];
                chart.setActiveElements([{ datasetIndex: 0, index: arcIndex }], { x: arc.x, y: arc.y });
                if (chart.tooltip) {
                    chart.tooltip.setActiveElements([{ datasetIndex: 0, index: arcIndex }], { x: arc.x, y: arc.y });
                    chart.tooltip.update(true);
                }
                chart.update('none');
            });

            item.addEventListener('mouseleave', function () {
                chart.setActiveElements([]);
                if (chart.tooltip) {
                    chart.tooltip.setActiveElements([]);
                    chart.tooltip.update(true);
                }
                chart.update('none');
            });
        });
    }

function updateCustomLegend(containerId, labels, data, cssClasses) {
    const container = document.getElementById(containerId);
    if (!container) return;
        const total = data.reduce(function (sum, val) { return sum + val; }, 0);
    if (total === 0) {
        container.innerHTML = '<span class="empty-data-text">Belum ada data tercatat.</span>';
        return;
    }

    let html = '';
        labels.forEach(function (label, index) {
        const value = data[index];
            const pctText = formatChartPercent(value, total);
            html +=
                '<div class="legend-item legend-item-interactive" data-legend-index="' + index + '">' +
                    '<span class="legend-dot ' + cssClasses[index] + '"></span>' +
                    '<span class="legend-copy">' +
                        '<span class="legend-name">' + label + '</span>' +
                        '<span class="legend-meta">' + value + ' · ' + pctText + '</span>' +
                    '</span>' +
                '</div>';
        });
    container.innerHTML = html;
}

function createStaticPieChart(canvasId, legendId, successCount, failedCount) {
        const canvas = document.getElementById(canvasId);
        if (!canvas || !window.Chart) return;

        const wrapper = canvas.closest('.chart-canvas-wrapper');
    const labels = ['Berhasil', 'Gagal'];
        const dataset = buildDonutDataset(successCount, failedCount);

        updateDonutCenter(wrapper, successCount, failedCount);

        const chartLabels = dataset.isSingle && !dataset.isEmpty && dataset.segmentLabels
            ? dataset.segmentLabels
            : (dataset.isEmpty ? dataset.labels : labels);

        const chart = new Chart(canvas, {
        type: 'doughnut',
        data: {
                labels: chartLabels,
            datasets: [{
                    data: dataset.data,
                    backgroundColor: dataset.colors,
                    hoverBackgroundColor: dataset.hoverColors,
                    borderWidth: 0,
                    borderRadius: dataset.borderRadius,
                    spacing: dataset.spacing,
                    hoverOffset: dataset.isEmpty ? 0 : 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
                cutout: '74%',
                layout: { padding: 2 },
                interaction: {
                    mode: 'nearest',
                    intersect: false
                },
                animation: {
                    animateRotate: true,
                    animateScale: true,
                    duration: 400,
                    easing: 'easeOutQuart'
                },
                onHover: function (event, elements) {
                    if (event.native && event.native.target) {
                        event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
                    }
                },
            plugins: {
                legend: { display: false },
                tooltip: { 
                        enabled: !dataset.isEmpty,
                        backgroundColor: '#1e293b',
                        titleFont: { size: 13, weight: '600' },
                        bodyFont: { size: 13 },
                        padding: 12,
                        cornerRadius: 8,
                        displayColors: true,
                        boxWidth: 10,
                        boxHeight: 10,
                        boxPadding: 4,
                    callbacks: {
                            title: function () {
                                return '';
                            },
                            label: donutTooltipLabel
                        }
                    }
                }
            },
            plugins: (!dataset.isEmpty && !dataset.isSingle) ? [{
                id: 'donutTrackRing',
                beforeDraw: function (c) {
                    const meta = c.getDatasetMeta(0);
                    if (!meta || !meta.data || !meta.data[0]) return;
                    const arc = meta.data[0];
                    const ctx = c.ctx;
                    ctx.save();
                    ctx.beginPath();
                    ctx.arc(arc.x, arc.y, arc.outerRadius, 0, Math.PI * 2);
                    ctx.arc(arc.x, arc.y, arc.innerRadius, 0, Math.PI * 2, true);
                    ctx.fillStyle = COLOR_TRACK;
                    ctx.fill();
                    ctx.restore();
                }
            }] : []
        });

        chart.$donutReal = dataset.real;
        chart.$donutLabels = labels;
        chart.$donutSingleIndex = (dataset.isSingle && !dataset.isEmpty) ? dataset.activeIndex : undefined;

        updateCustomLegend(legendId, labels, dataset.real, legendClasses);
        bindLegendChartInteraction(chart, legendId);

        const card = canvas.closest('.chart-card');
        if (card) card.classList.remove('is-chart-pending');
    }

    function initCharts() {
        Object.keys(CHART_METRICS).forEach(function (key) {
            const item = CHART_METRICS[key];
            createStaticPieChart(item.canvas, item.legend, item.success, item.failed);
        });
    }

    function scheduleCharts() {
        const run = function () {
            loadChartJs().then(initCharts).catch(function () {
                document.querySelectorAll('.chart-card.is-chart-pending').forEach(function (card) {
                    card.classList.remove('is-chart-pending');
                });
                });
        };
        if ('requestIdleCallback' in window) {
            requestIdleCallback(run, { timeout: 800 });
        } else {
            setTimeout(run, 0);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleCharts);
    } else {
        scheduleCharts();
    }
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
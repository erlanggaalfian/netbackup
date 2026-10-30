<?php
require_once __DIR__ . '/../config/db.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    die('ID Backup tidak valid.');
}

$stmt = $pdo->prepare('
    SELECT b.config, b.timestamp, b.filename, b.status, d.name AS device_name
    FROM backups b
    JOIN devices d ON d.id = b.device_id
    WHERE b.id = ?
');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    die('Riwayat backup tidak ditemukan.');
}

$configText = (string)($row['config'] ?? '');
$configLines = $configText === '' ? 0 : substr_count($configText, "\n") + 1;
$configBytes = strlen($configText);
$statusOk = ($row['status'] ?? '') === 'success';

$page_body_class = 'backup-view-page';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header-container motion-fade-up page-header-flex">
    <div>
        <h1 class="page-header">Detail Backup</h1>
        <p class="page-subtitle">Pratinjau dan pencarian isi konfigurasi perangkat.</p>
    </div>
    <div class="page-actions">
        <a href="/backups.php" class="btn btn-sm secondary">← Kembali</a>
        <?php if ($statusOk && $configText !== '') : ?>
            <a href="/download.php?id=<?php echo (int)$id; ?>" class="btn btn-sm">Download</a>
        <?php endif; ?>
    </div>
</div>

<div class="table-container motion-fade-up backup-view-card">
    <div class="backup-view-meta">
        <div class="backup-meta-chip">
            <span class="backup-meta-chip-label">Perangkat</span>
            <span class="backup-meta-chip-value"><?php echo htmlspecialchars($row['device_name']); ?></span>
        </div>
        <div class="backup-meta-chip">
            <span class="backup-meta-chip-label">Waktu</span>
            <span class="backup-meta-chip-value"><?php echo date('d M Y, H:i', strtotime($row['timestamp'])); ?></span>
        </div>
        <div class="backup-meta-chip">
            <span class="backup-meta-chip-label">Status</span>
            <span class="backup-meta-chip-value">
                <span class="badge <?php echo $statusOk ? 'success' : 'danger'; ?>">
                    <?php echo $statusOk ? 'Berhasil' : 'Gagal'; ?>
                </span>
            </span>
        </div>
        <button type="button" class="btn btn-sm secondary m-mobile-only" data-toggle="backup-extra-meta" data-show-text="Detail lainnya" data-hide-text="Sembunyikan" aria-expanded="false">Detail lainnya</button>
        <div class="m-collapsible" id="backup-extra-meta">
        <?php if (!empty($row['filename'])) : ?>
        <div class="backup-meta-chip backup-meta-chip-wide">
            <span class="backup-meta-chip-label">Berkas</span>
            <span class="backup-meta-chip-value backup-meta-filename"><?php echo htmlspecialchars($row['filename']); ?></span>
        </div>
        <?php endif; ?>
        <div class="backup-meta-chip">
            <span class="backup-meta-chip-label">Ukuran</span>
            <span class="backup-meta-chip-value"><?php echo number_format($configLines); ?> baris · <?php echo number_format($configBytes); ?> byte</span>
        </div>
        </div>
    </div>

    <div class="backup-view-toolbar">
        <div class="backup-view-search">
            <input type="search" id="search-input" placeholder="Cari teks dalam konfigurasi…" autocomplete="off" enterkeyhint="search">
            <button type="button" id="search-btn" class="btn btn-sm">Cari</button>
        </div>
        <button type="button" class="btn btn-sm secondary m-mobile-only" data-toggle="backup-config-body" data-show-text="Tampilkan isi konfigurasi" data-hide-text="Sembunyikan" aria-expanded="false">Tampilkan isi konfigurasi</button>
        <div class="backup-view-toolbar-actions">
            <span id="search-count" class="backup-view-count" aria-live="polite">0 / 0</span>
            <button type="button" id="prev-btn" class="btn btn-sm secondary" disabled aria-label="Hasil sebelumnya">‹</button>
            <button type="button" id="next-btn" class="btn btn-sm secondary" disabled aria-label="Hasil berikutnya">›</button>
            <button type="button" id="copy-btn" class="btn btn-sm secondary backup-copy-btn">
                <svg class="backup-copy-icon" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M4 1.5H3a2 2 0 0 0-2 2V14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V3.5a2 2 0 0 0-2-2h-1v1h1a1 1 0 0 1 1 1V14a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1h1v-1z"></path><path d="M9.5 1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-1a.5.5 0 0 1 .5-.5h3zm-3 1a.5.5 0 0 0-.5.5v1a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-1a.5.5 0 0 0-.5-.5h-3z"></path></svg>
                <span>Copy</span>
            </button>
        </div>
    </div>

    <div class="m-collapsible" id="backup-config-body">
    <div class="config-viewer backup-config-viewer">
        <?php if ($configText === '') : ?>
            <p class="backup-config-empty">Tidak ada isi konfigurasi tersimpan untuk backup ini.</p>
        <?php else : ?>
            <pre id="config-content" class="backup-config-pre"><?php echo htmlspecialchars($configText); ?></pre>
        <?php endif; ?>
    </div>
    </div>
</div>

<?php if ($configText !== '') : ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const configContent = document.getElementById('config-content');
    const searchInput = document.getElementById('search-input');
    const searchBtn = document.getElementById('search-btn');
    const prevBtn = document.getElementById('prev-btn');
    const nextBtn = document.getElementById('next-btn');
    const countEl = document.getElementById('search-count');
    const copyBtn = document.getElementById('copy-btn');
    const copyBtnSpan = copyBtn ? copyBtn.querySelector('span') : null;

    const rawText = configContent.textContent;
    let matchStarts = [];
    let activeIndex = -1;
    let lastTerm = '';

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function updateNavState() {
        const total = matchStarts.length;
        const hasMatches = total > 0;
        if (prevBtn) prevBtn.disabled = !hasMatches;
        if (nextBtn) nextBtn.disabled = !hasMatches;
        if (countEl) {
            countEl.textContent = hasMatches ? ((activeIndex + 1) + ' / ' + total) : '0 / 0';
        }
    }

    function renderContent() {
        if (!lastTerm) {
            configContent.textContent = rawText;
            matchStarts = [];
            activeIndex = -1;
            updateNavState();
            return;
        }

        const termLower = lastTerm.toLowerCase();
        const lower = rawText.toLowerCase();
        matchStarts = [];
        let idx = 0;
        while ((idx = lower.indexOf(termLower, idx)) !== -1) {
            matchStarts.push(idx);
            idx += lastTerm.length;
        }

        if (matchStarts.length === 0) {
            configContent.textContent = rawText;
            activeIndex = -1;
            updateNavState();
            return;
        }

        if (activeIndex >= matchStarts.length) {
            activeIndex = matchStarts.length - 1;
        }
        if (activeIndex < 0) {
            activeIndex = 0;
        }

        let html = '';
        let cursor = 0;
        matchStarts.forEach(function (start, i) {
            html += escapeHtml(rawText.slice(cursor, start));
            const chunk = rawText.slice(start, start + lastTerm.length);
            const cls = i === activeIndex ? 'config-search-highlight is-active' : 'config-search-highlight';
            html += '<mark class="' + cls + '">' + escapeHtml(chunk) + '</mark>';
            cursor = start + lastTerm.length;
        });
        html += escapeHtml(rawText.slice(cursor));
        configContent.innerHTML = html;
        updateNavState();

        const activeMark = configContent.querySelector('.config-search-highlight.is-active');
        if (activeMark) {
            activeMark.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    }

    function runSearch() {
        lastTerm = (searchInput.value || '').trim();
        activeIndex = lastTerm ? 0 : -1;
        renderContent();
        document.getElementById('backup-config-body')?.classList.add('is-open');
    }

    function stepMatch(delta) {
        if (matchStarts.length === 0) return;
        activeIndex = (activeIndex + delta + matchStarts.length) % matchStarts.length;
        renderContent();
    }

    if (searchBtn) searchBtn.addEventListener('click', runSearch);
    if (searchInput) {
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                runSearch();
            }
        });
    }
    if (prevBtn) prevBtn.addEventListener('click', function () { stepMatch(-1); });
    if (nextBtn) nextBtn.addEventListener('click', function () { stepMatch(1); });

    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            const textToCopy = rawText;
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(textToCopy).then(showCopySuccess, showCopyFailure);
            } else {
                fallbackCopyText(textToCopy);
            }
        });
    }

    function showCopySuccess() {
        if (!copyBtnSpan) return;
        copyBtnSpan.textContent = 'Disalin!';
        copyBtn.classList.add('copied');
        setTimeout(function () {
            copyBtnSpan.textContent = 'Copy';
            copyBtn.classList.remove('copied');
        }, 2000);
    }

    function showCopyFailure() {
        showToast('Gagal menyalin teks.', 'error');
    }

    function fallbackCopyText(text) {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.top = '0';
        textArea.style.left = '0';
        textArea.style.opacity = '0';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        try {
            if (document.execCommand('copy')) {
                showCopySuccess();
            } else {
                showCopyFailure();
            }
        } catch (err) {
            showCopyFailure();
        }
        document.body.removeChild(textArea);
    }
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>

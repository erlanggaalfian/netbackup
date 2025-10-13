<?php
require_once __DIR__ . '/../config/db.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404); die('ID Backup tidak valid.');
}

$stmt = $pdo->prepare('SELECT b.config, b.timestamp, d.name AS device_name FROM backups b JOIN devices d ON d.id=b.device_id WHERE b.id=?');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404); die('Riwayat backup tidak ditemukan.');
}

include __DIR__ . '/includes/header.php';
?>

<div class="form-container form-container-full-width">
  <h1 class="page-title">Detail Backup</h1>
  <p class="page-subtitle">
    Perangkat: <strong><?php echo htmlspecialchars($row['device_name']); ?></strong> | 
    Waktu: <strong><?php echo date('d M Y H:i', strtotime($row['timestamp'])); ?></strong>
  </p>
</div>

<div class="table-container">
  <div class="table-header search-header">
    <h2 class="table-title">Isi Konfigurasi</h2>
    <div class="config-search-wrapper">
        <input type="text" id="search-input" placeholder="Cari di dalam konfigurasi...">
        <button id="search-btn" class="btn btn-sm">Cari</button>
        <button id="prev-btn" class="btn btn-sm secondary" disabled>‹</button>
        <button id="next-btn" class="btn btn-sm secondary" disabled>›</button>
        <span id="search-count">0 / 0</span>
    </div>
  </div>
  
  <div class="config-container">
    <button id="copy-btn" class="copy-btn">
        <svg fill="currentColor" viewBox="0 0 16 16" height="1em" width="1em"><path d="M4 1.5H3a2 2 0 0 0-2 2V14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V3.5a2 2 0 0 0-2-2h-1v1h1a1 1 0 0 1 1 1V14a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1h1v-1z"></path><path d="M9.5 1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-1a.5.5 0 0 1 .5-.5h3zM-1 7a.5.5 0 0 1 .5-.5h15a.5.5 0 0 1 0 1h-15A.5.5 0 0 1-1 7z"></path></svg>
        <span>Copy</span>
    </button>
    <div class="config-viewer">
      <pre id="config-content" style="white-space: pre; overflow-wrap: break-word;"><?php echo htmlspecialchars($row['config']); ?></pre>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ... (Kode JavaScript untuk fitur pencarian tetap sama) ...

    const copyBtn = document.getElementById('copy-btn');
    const configContent = document.getElementById('config-content');
    const copyBtnSpan = copyBtn.querySelector('span');

    // --- FUNGSI COPY BARU YANG LEBIH ANDAL ---
    copyBtn.addEventListener('click', function() {
        const textToCopy = configContent.textContent;

        // Coba metode modern (navigator.clipboard) terlebih dahulu
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(textToCopy).then(showSuccess, showFailure);
        } else {
            // Fallback ke metode klasik jika tidak aman atau tidak didukung
            fallbackCopyText(textToCopy);
        }
    });

    function showSuccess() {
        copyBtnSpan.textContent = 'Disalin!';
        copyBtn.classList.add('copied');
        setTimeout(() => {
            copyBtnSpan.textContent = 'Copy';
            copyBtn.classList.remove('copied');
        }, 2000);
    }

    function showFailure() {
        alert('Gagal menyalin teks.');
    }

    function fallbackCopyText(text) {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        
        // Buat textarea tidak terlihat
        textArea.style.position = 'fixed';
        textArea.style.top = 0;
        textArea.style.left = 0;
        textArea.style.opacity = 0;

        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();

        try {
            const successful = document.execCommand('copy');
            if (successful) {
                showSuccess();
            } else {
                showFailure();
            }
        } catch (err) {
            showFailure();
        }

        document.body.removeChild(textArea);
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
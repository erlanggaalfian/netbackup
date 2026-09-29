<?php
require_once __DIR__ . '/../config/db.php';
require_login();

// Pastikan hanya admin yang bisa mengakses untuk keamanan data
if ((current_user()['role'] ?? '') !== 'admin') {
    die("Akses ditolak: Hanya administrator yang dapat mengakses halaman ini.");
}

try {
    $stmt = $pdo->query("SHOW CREATE TABLE backups");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $createTableSql = $row['Create Table'] ?? 'Tidak ditemukan.';
} catch (Exception $e) {
    $createTableSql = "Error: " . $e->getMessage();
}

try {
    $stmtCol = $pdo->query("SHOW COLUMNS FROM backups");
    $columns = $stmtCol->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $columns = [];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Inspeksi Struktur Database - NetBackup</title>
    <style>
        body { font-family: sans-serif; padding: 20px; background: #f4f7f9; color: #333; }
        pre { background: #2e2e2e; color: #fff; padding: 15px; border-radius: 8px; overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; background: #fff; }
        th, td { border: 1px solid #ccc; padding: 10px; text-align: left; }
        th { background: #eee; }
    </style>
</head>
<body>
    <h2>Hasil Inspeksi Tabel `backups`</h2>
    
    <h3>1. SQL Create Table</h3>
    <pre><?php echo htmlspecialchars($createTableSql); ?></pre>

    <h3>2. Detail Kolom</h3>
    <table>
        <thead>
            <tr>
                <th>Field</th>
                <th>Type</th>
                <th>Null</th>
                <th>Key</th>
                <th>Default</th>
                <th>Extra</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($columns as $col): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($col['Field']); ?></strong></td>
                <td><?php echo htmlspecialchars($col['Type']); ?></td>
                <td><?php echo htmlspecialchars($col['Null']); ?></td>
                <td><?php echo htmlspecialchars($col['Key']); ?></td>
                <td><?php echo htmlspecialchars($col['Default'] ?? 'NULL'); ?></td>
                <td><?php echo htmlspecialchars($col['Extra']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>

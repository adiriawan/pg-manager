<?php
if (!defined('PG_DUMP'))    define('PG_DUMP',    '/opt/homebrew/bin/pg_dump');
if (!defined('PG_RESTORE')) define('PG_RESTORE', '/opt/homebrew/bin/pg_restore');
if (!defined('PSQL'))       define('PSQL',       '/opt/homebrew/bin/psql');
if (!defined('MYSQL_DUMP')) define('MYSQL_DUMP', '/opt/homebrew/bin/mysqldump');
if (!defined('MYSQL_BIN'))  define('MYSQL_BIN',  '/opt/homebrew/bin/mysql');
if (!defined('BACKUP_DIR')) define('BACKUP_DIR', __DIR__ . '/../backups');

$flash   = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') verifyCsrf();

// Backup otomatis sebelum restore yang menghapus schema (pengaman data)
function autoBackupBeforeRestore(array $creds): ?string {
    if (isSQLite() || isMySQL()) return null;
    if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);
    $fn = 'AUTO_prerestore_' . $creds['dbname'] . '_' . date('Ymd_His') . '.sql';
    $cmd = 'PGPASSWORD=' . escapeshellarg($creds['password']) . ' ' . PG_DUMP
         . ' -h ' . escapeshellarg($creds['host']) . ' -p ' . escapeshellarg($creds['port'])
         . ' -U ' . escapeshellarg($creds['user']) . ' ' . escapeshellarg($creds['dbname'])
         . ' > ' . escapeshellarg(BACKUP_DIR . '/' . $fn) . ' 2>/dev/null';
    exec($cmd, $o, $rc);
    if ($rc !== 0 || !filesize(BACKUP_DIR . '/' . $fn)) { @unlink(BACKUP_DIR . '/' . $fn); return false; }
    return $fn;
}

// ============================================================
// ACTION: Buat backup baru
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'backup') {
    $format      = $_POST['format']   ?? 'sql';
    $schema_only = isset($_POST['schema_only']);
    $data_only   = isset($_POST['data_only']);
    $tables_sel  = array_filter($_POST['tables'] ?? []);

    $dbname   = $creds['dbname'];
    $ts       = date('Ymd_His');
    $filename = "{$dbname}_{$ts}.sql";
    $filepath = BACKUP_DIR . '/' . $filename;

    if (isSQLite()) {
        // SQLite backup: gunakan VACUUM INTO (SQLite 3.27+)
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', basename($creds['dbname']));
        $filename = "{$safeName}_{$ts}.db";
        $filepath = BACKUP_DIR . '/' . $filename;
        if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);
        try {
            $pdo->exec("VACUUM INTO " . $pdo->quote($filepath));
            $_SESSION['flash'] = ['type' => 'success', 'msg' => "Backup SQLite <strong>$filename</strong> berhasil dibuat (" . formatBytes(filesize($filepath)) . ")."];
        } catch (Exception $e) {
            // Fallback: PHP copy
            if (copy($creds['dbname'], $filepath)) {
                $_SESSION['flash'] = ['type' => 'success', 'msg' => "Backup SQLite <strong>$filename</strong> berhasil (copy) (" . formatBytes(filesize($filepath)) . ")."];
            } else {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => "Backup SQLite gagal: " . htmlspecialchars($e->getMessage())];
            }
        }
        header('Location: app.php?page=backup&schema=' . urlencode($schema));
        exit;
    } elseif (isMySQL()) {
        // mysqldump
        $mysqlPwd = $creds['password'] ? '-p' . escapeshellarg($creds['password']) : '';
        $cmd = MYSQL_DUMP
            . " -h " . escapeshellarg($creds['host'])
            . " -P " . escapeshellarg($creds['port'])
            . " -u " . escapeshellarg($creds['user'])
            . " " . $mysqlPwd;

        if ($schema_only) $cmd .= ' --no-data';
        elseif ($data_only) $cmd .= ' --no-create-info';

        if ($tables_sel) {
            $cmd .= ' ' . escapeshellarg($dbname);
            foreach ($tables_sel as $t) $cmd .= ' ' . escapeshellarg($t);
        } else {
            $cmd .= ' ' . escapeshellarg($dbname);
        }
        $cmd .= ' > ' . escapeshellarg($filepath) . ' 2>&1';
    } else {
        // pg_dump
        $ext      = $format === 'custom' ? 'dump' : ($format === 'tar' ? 'tar' : 'sql');
        $filename = "{$dbname}_{$ts}.{$ext}";
        $filepath = BACKUP_DIR . '/' . $filename;

        $env = 'PGPASSWORD=' . escapeshellarg($creds['password']);
        $cmd = "$env " . PG_DUMP
            . " -h " . escapeshellarg($creds['host'])
            . " -p " . escapeshellarg($creds['port'])
            . " -U " . escapeshellarg($creds['user'])
            . " -d " . escapeshellarg($dbname);

        if ($format === 'custom') $cmd .= ' -Fc';
        elseif ($format === 'tar') $cmd .= ' -Ft';
        else $cmd .= ' -Fp';

        if ($schema_only) $cmd .= ' --schema-only';
        elseif ($data_only) $cmd .= ' --data-only';

        foreach ($tables_sel as $t) {
            $cmd .= ' -t ' . escapeshellarg($schema . '.' . $t);
        }
        $cmd .= ' > ' . escapeshellarg($filepath) . ' 2>&1';
    }

    $output = null; $retval = null;
    exec($cmd, $output, $retval);

    if ($retval === 0 && file_exists($filepath) && filesize($filepath) > 0) {
        $_SESSION['flash'] = ['type' => 'success', 'msg' => "Backup <strong>$filename</strong> berhasil dibuat (" . formatBytes(filesize($filepath)) . ")."];
    } else {
        $errMsg = implode("\n", $output);
        if (file_exists($filepath)) unlink($filepath);
        $_SESSION['flash'] = ['type' => 'error', 'msg' => "Backup gagal: " . htmlspecialchars($errMsg ?: 'Unknown error')];
    }
    header('Location: app.php?page=backup&schema=' . urlencode($schema));
    exit;
}

// ============================================================
// ACTION: Download backup
// ============================================================
if (isset($_GET['download'])) {
    $file = basename($_GET['download']);
    $path = BACKUP_DIR . '/' . $file;
    if (file_exists($path) && strpos(realpath($path), realpath(BACKUP_DIR)) === 0) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}

// ============================================================
// ACTION: Hapus backup
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete_backup') {
    $file = basename($_POST['filename'] ?? '');
    $path = BACKUP_DIR . '/' . $file;
    if ($file && file_exists($path) && strpos(realpath($path), realpath(BACKUP_DIR)) === 0) {
        unlink($path);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => "File <strong>$file</strong> dihapus."];
    }
    header('Location: app.php?page=backup&schema=' . urlencode($schema));
    exit;
}

// ============================================================
// ACTION: Restore dari file upload
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'restore_upload') {
    $target_db  = trim($_POST['target_db'] ?? $creds['dbname']);
    $drop_first = isset($_POST['drop_first']);
    if ($drop_first && ($target_db === $creds['dbname'])) {
        $ab = autoBackupBeforeRestore($creds);
        if ($ab === false) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Restore dibatalkan: backup otomatis sebelum restore GAGAL, data lama tidak disentuh.'];
            header('Location: app.php?page=backup&schema=' . urlencode($schema));
            exit;
        }
    }

    if (empty($_FILES['restore_file']['tmp_name'])) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Pilih file backup terlebih dahulu.'];
    } else {
        $origName = $_FILES['restore_file']['name'];
        $tmpPath  = $_FILES['restore_file']['tmp_name'];
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        $env = 'PGPASSWORD=' . escapeshellarg($creds['password']);
        $connStr = " -h " . escapeshellarg($creds['host'])
                 . " -p " . escapeshellarg($creds['port'])
                 . " -U " . escapeshellarg($creds['user']);

        if ($ext === 'sql') {
            $cmd = "$env " . PSQL . $connStr . " -d " . escapeshellarg($target_db)
                 . ($drop_first ? ' -c "DROP SCHEMA public CASCADE; CREATE SCHEMA public;"' : '')
                 . " -f " . escapeshellarg($tmpPath) . " 2>&1";
        } else {
            // format custom / tar
            $cmd = "$env " . PG_RESTORE . $connStr . " -d " . escapeshellarg($target_db)
                 . ($drop_first ? ' --clean --if-exists' : '')
                 . " --no-owner --no-privileges"
                 . " " . escapeshellarg($tmpPath) . " 2>&1";
        }

        $output = [];
        $retval = null;
        exec($cmd, $output, $retval);
        $outStr = implode("\n", $output);

        if ($retval === 0) {
            $_SESSION['flash'] = ['type' => 'success', 'msg' => "Restore ke database <strong>$target_db</strong> berhasil."];
        } else {
            // pg_restore kadang return non-zero tapi sukses (warning)
            $hasError = preg_match('/error:/i', $outStr);
            if (!$hasError) {
                $_SESSION['flash'] = ['type' => 'success', 'msg' => "Restore ke <strong>$target_db</strong> selesai (ada warning)."];
            } else {
                $_SESSION['flash'] = ['type' => 'error', 'msg' => "Restore gagal:<br><pre>" . htmlspecialchars($outStr) . "</pre>"];
            }
        }
    }
    header('Location: app.php?page=backup&schema=' . urlencode($schema));
    exit;
}

// ============================================================
// ACTION: Restore dari file yang sudah ada di server
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'restore_existing') {
    $file       = basename($_POST['filename'] ?? '');
    $target_db  = trim($_POST['target_db'] ?? $creds['dbname']);
    $drop_first = isset($_POST['drop_first']);
    $path       = BACKUP_DIR . '/' . $file;
    if ($drop_first && ($target_db === $creds['dbname'])) {
        $ab = autoBackupBeforeRestore($creds);
        if ($ab === false) {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Restore dibatalkan: backup otomatis sebelum restore GAGAL, data lama tidak disentuh.'];
            header('Location: app.php?page=backup&schema=' . urlencode($schema));
            exit;
        }
    }

    if (!$file || !file_exists($path)) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'File tidak ditemukan.'];
    } else {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $env = 'PGPASSWORD=' . escapeshellarg($creds['password']);
        $connStr = " -h " . escapeshellarg($creds['host'])
                 . " -p " . escapeshellarg($creds['port'])
                 . " -U " . escapeshellarg($creds['user']);

        if ($ext === 'sql') {
            $cmd = "$env " . PSQL . $connStr . " -d " . escapeshellarg($target_db)
                 . ($drop_first ? ' -c "DROP SCHEMA public CASCADE; CREATE SCHEMA public;"' : '')
                 . " -f " . escapeshellarg($path) . " 2>&1";
        } else {
            $cmd = "$env " . PG_RESTORE . $connStr . " -d " . escapeshellarg($target_db)
                 . ($drop_first ? ' --clean --if-exists' : '')
                 . " --no-owner --no-privileges"
                 . " " . escapeshellarg($path) . " 2>&1";
        }

        $output = [];
        $retval = null;
        exec($cmd, $output, $retval);
        $outStr = implode("\n", $output);
        $hasError = preg_match('/error:/i', $outStr);

        if ($retval === 0 || !$hasError) {
            $_SESSION['flash'] = ['type' => 'success', 'msg' => "Restore <strong>$file</strong> ke <strong>$target_db</strong> berhasil."];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'msg' => "Restore gagal:<br><pre>" . htmlspecialchars($outStr) . "</pre>"];
        }
    }
    header('Location: app.php?page=backup&schema=' . urlencode($schema));
    exit;
}

// ============================================================
// Ambil daftar database untuk dropdown
// ============================================================
$databases = [];
try {
    if (!isSQLite()) {
        $stmt = $pdo->query("SELECT datname FROM pg_database WHERE datistemplate=false ORDER BY datname");
        $databases = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $databases = [basename($creds['dbname'])];
    }
} catch (Exception $e) {}

// ============================================================
// Ambil daftar file backup
// ============================================================
$backupFiles = [];
foreach (glob(BACKUP_DIR . '/*.{sql,dump,tar,db}', GLOB_BRACE) as $f) {
    $backupFiles[] = [
        'name'    => basename($f),
        'size'    => filesize($f),
        'mtime'   => filemtime($f),
        'ext'     => pathinfo($f, PATHINFO_EXTENSION),
    ];
}
usort($backupFiles, fn($a, $b) => $b['mtime'] - $a['mtime']);

// ============================================================
// Flash message
// ============================================================
if (isset($_SESSION['flash'])) {
    $flash     = $_SESSION['flash']['msg'];
    $flashType = $_SESSION['flash']['type'];
    unset($_SESSION['flash']);
}

function formatBytes($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
?>

<div class="page-header">
    <div>
        <h1>Backup &amp; Restore</h1>
        <p class="page-sub">Database: <strong><?= htmlspecialchars($creds['dbname']) ?></strong> &mdash; <?= count($backupFiles) ?> file backup tersimpan</p>
    </div>
    <div class="header-actions">
        <button class="btn btn-primary" onclick="openModal('modal-backup')">+ Buat Backup</button>
        <button class="btn btn-outline" onclick="openModal('modal-restore-upload')">↑ Restore dari File</button>
    </div>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType ?>"><?= $flash ?></div>
<?php endif; ?>

<!-- ============ PANEL: Buat Backup ============ -->
<div class="br-grid">

    <!-- Kiri: Daftar backup -->
    <div class="br-main">
        <h2 class="section-title">File Backup Tersimpan</h2>

        <?php if (empty($backupFiles)): ?>
        <div class="empty-state" style="padding:40px">
            <svg width="40" height="40" fill="none" viewBox="0 0 40 40"><rect x="4" y="4" width="32" height="32" rx="4" stroke="#3ECF8E" stroke-width="1.5"/><path d="M13 20h14M20 13v14" stroke="#3ECF8E" stroke-width="1.5" stroke-linecap="round"/></svg>
            <h3>Belum ada backup</h3>
            <p>Klik <strong>+ Buat Backup</strong> untuk memulai.</p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nama File</th>
                        <th>Format</th>
                        <th>Ukuran</th>
                        <th>Waktu</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($backupFiles as $bf): ?>
                <tr>
                    <td>
                        <div class="backup-name">
                            <span class="backup-icon"><?= $bf['ext'] === 'sql' ? '📄' : '📦' ?></span>
                            <span><?= htmlspecialchars($bf['name']) ?></span>
                        </div>
                    </td>
                    <td><code><?= strtoupper(htmlspecialchars($bf['ext'])) ?></code></td>
                    <td><?= formatBytes($bf['size']) ?></td>
                    <td><?= date('d/m/Y H:i:s', $bf['mtime']) ?></td>
                    <td>
                        <div style="display:flex;gap:6px;align-items:center">
                            <!-- Download -->
                            <a href="app.php?page=backup&schema=<?= urlencode($schema) ?>&download=<?= urlencode($bf['name']) ?>"
                               class="btn btn-outline btn-sm" title="Download">⬇ Download</a>

                            <!-- Restore -->
                            <button class="btn btn-outline btn-sm btn-accent"
                                onclick="openRestoreModal('<?= addslashes($bf['name']) ?>')">↩ Restore</button>

                            <!-- Delete -->
                            <form method="POST" data-confirm="Hapus file backup ini secara permanen?" data-confirm-title="Hapus Backup" data-confirm-danger data-confirm-ok="Hapus">
                                <?= csrfField() ?>
                                <input type="hidden" name="_action" value="delete_backup">
                                <input type="hidden" name="filename" value="<?= htmlspecialchars($bf['name']) ?>">
                                <button type="submit" class="btn btn-outline btn-sm btn-danger" title="Hapus">🗑</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Kanan: Info -->
    <div class="br-side">
        <div class="info-panel">
            <h3>📋 Informasi</h3>
            <ul class="info-list">
                <li><strong>SQL</strong> – Plain text, bisa dibaca/edit manual. Cocok untuk backup kecil.</li>
                <li><strong>Custom (.dump)</strong> – Format biner pg_dump, lebih kecil, restore lebih fleksibel.</li>
                <li><strong>Tar (.tar)</strong> – Arsip, mendukung restore paralel.</li>
            </ul>
            <div class="info-divider"></div>
            <h3>📁 Lokasi Backup</h3>
            <code class="path-code"><?= htmlspecialchars(realpath(BACKUP_DIR)) ?></code>
            <div class="info-divider"></div>
            <h3>⚙️ Tools</h3>
            <div class="tool-item"><span class="tool-label">pg_dump</span><code><?= PG_DUMP ?></code></div>
            <div class="tool-item"><span class="tool-label">pg_restore</span><code><?= PG_RESTORE ?></code></div>
            <div class="tool-item"><span class="tool-label">psql</span><code><?= PSQL ?></code></div>
        </div>
    </div>
</div>


<!-- ============================================================ -->
<!-- MODAL: Buat Backup                                          -->
<!-- ============================================================ -->
<div id="modal-backup" class="modal-overlay" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <h3>Buat Backup Baru</h3>
            <button class="modal-close" onclick="closeModal('modal-backup')">×</button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="_action" value="backup">
            <div class="modal-body">

                <div class="form-group">
                    <label>Database</label>
                    <input type="text" value="<?= htmlspecialchars($creds['dbname']) ?>" disabled>
                </div>

                <div class="form-group">
                    <label>Format Output</label>
                    <div class="format-grid">
                        <label class="format-card">
                            <input type="radio" name="format" value="sql" checked>
                            <div class="format-body">
                                <div class="format-title">📄 SQL</div>
                                <div class="format-desc">Plain text, mudah dibaca</div>
                            </div>
                        </label>
                        <label class="format-card">
                            <input type="radio" name="format" value="custom">
                            <div class="format-body">
                                <div class="format-title">📦 Custom</div>
                                <div class="format-desc">Biner, lebih kecil & fleksibel</div>
                            </div>
                        </label>
                        <label class="format-card">
                            <input type="radio" name="format" value="tar">
                            <div class="format-body">
                                <div class="format-title">🗜 Tar</div>
                                <div class="format-desc">Arsip, restore paralel</div>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label>Konten</label>
                    <div style="display:flex;gap:16px">
                        <label class="check-label"><input type="radio" name="content" value="all" checked onchange="toggleContent(this)"> Schema + Data</label>
                        <label class="check-label"><input type="radio" name="content" value="schema" onchange="toggleContent(this)"> Schema saja</label>
                        <label class="check-label"><input type="radio" name="content" value="data" onchange="toggleContent(this)"> Data saja</label>
                    </div>
                    <input type="hidden" name="schema_only" id="hid-schema-only" value="">
                    <input type="hidden" name="data_only"   id="hid-data-only"   value="">
                </div>

                <div class="form-group">
                    <label>Tabel (kosongkan = semua tabel)</label>
                    <div class="table-checklist">
                        <?php foreach ($tables as $t): ?>
                        <label class="check-label check-sm">
                            <input type="checkbox" name="tables[]" value="<?= htmlspecialchars($t) ?>">
                            <?= htmlspecialchars($t) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-backup')">Batal</button>
                <button type="submit" class="btn btn-primary" id="btn-do-backup">
                    <span id="backup-spinner" class="spinner" style="display:none"></span>
                    Mulai Backup
                </button>
            </div>
        </form>
    </div>
</div>


<!-- ============================================================ -->
<!-- MODAL: Restore dari file upload                              -->
<!-- ============================================================ -->
<div id="modal-restore-upload" class="modal-overlay" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <h3>Restore dari File</h3>
            <button class="modal-close" onclick="closeModal('modal-restore-upload')">×</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="_action" value="restore_upload">
            <div class="modal-body">
                <div class="alert alert-error">
                    ⚠️ <strong>Perhatian:</strong> Restore akan menimpa data yang ada. Pastikan Anda sudah backup data sebelumnya.
                </div>
                <div class="form-group">
                    <label>File Backup (.sql / .dump / .tar)</label>
                    <div class="file-drop-zone" id="drop-zone-upload" onclick="document.getElementById('restore-file-input').click()">
                        <svg width="28" height="28" fill="none" viewBox="0 0 28 28"><path d="M14 4v14M8 12l6-8 6 8" stroke="#3ECF8E" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 20h20v4H4z" stroke="#3ECF8E" stroke-width="1.5" stroke-linecap="round"/></svg>
                        <span id="upload-label">Klik atau drag file ke sini</span>
                        <span class="file-hint">.sql, .dump, .tar</span>
                    </div>
                    <input type="file" id="restore-file-input" name="restore_file" accept=".sql,.dump,.tar" style="display:none" onchange="updateLabel(this)">
                </div>
                <div class="form-group">
                    <label>Target Database</label>
                    <select name="target_db">
                        <?php foreach ($databases as $db): ?>
                        <option value="<?= htmlspecialchars($db) ?>" <?= $db === $creds['dbname'] ? 'selected' : '' ?>><?= htmlspecialchars($db) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="check-label">
                        <input type="checkbox" name="drop_first" value="1">
                        Drop &amp; recreate schema public sebelum restore (hanya untuk format SQL)
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-restore-upload')">Batal</button>
                <button type="submit" class="btn btn-primary">↩ Mulai Restore</button>
            </div>
        </form>
    </div>
</div>


<!-- ============================================================ -->
<!-- MODAL: Restore dari file yang ada di server                  -->
<!-- ============================================================ -->
<div id="modal-restore-existing" class="modal-overlay" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <h3>Restore: <span id="restore-filename-label"></span></h3>
            <button class="modal-close" onclick="closeModal('modal-restore-existing')">×</button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="_action" value="restore_existing">
            <input type="hidden" name="filename" id="restore-existing-file">
            <div class="modal-body">
                <div class="alert alert-error">
                    ⚠️ <strong>Perhatian:</strong> Restore akan menimpa data yang ada di database target.
                </div>
                <div class="form-group">
                    <label>Target Database</label>
                    <select name="target_db">
                        <?php foreach ($databases as $db): ?>
                        <option value="<?= htmlspecialchars($db) ?>" <?= $db === $creds['dbname'] ? 'selected' : '' ?>><?= htmlspecialchars($db) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="check-label">
                        <input type="checkbox" name="drop_first" value="1">
                        Drop &amp; recreate schema sebelum restore
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-restore-existing')">Batal</button>
                <button type="submit" class="btn btn-danger">↩ Mulai Restore</button>
            </div>
        </form>
    </div>
</div>

<script>
function openRestoreModal(filename) {
    document.getElementById('restore-existing-file').value = filename;
    document.getElementById('restore-filename-label').textContent = filename;
    openModal('modal-restore-existing');
}

function toggleContent(radio) {
    document.getElementById('hid-schema-only').value = '';
    document.getElementById('hid-data-only').value   = '';
    if (radio.value === 'schema') document.getElementById('hid-schema-only').name = 'schema_only';
    else if (radio.value === 'data') document.getElementById('hid-data-only').name = 'data_only';
    else {
        document.getElementById('hid-schema-only').name = '_unused1';
        document.getElementById('hid-data-only').name   = '_unused2';
    }
}

function updateLabel(input) {
    const label = document.getElementById('upload-label');
    if (input.files[0]) {
        label.textContent = input.files[0].name;
        label.style.color = '#3ECF8E';
    }
}

// Drag & drop
const dz = document.getElementById('drop-zone-upload');
if (dz) {
    dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('drag-active'); });
    dz.addEventListener('dragleave', () => dz.classList.remove('drag-active'));
    dz.addEventListener('drop', e => {
        e.preventDefault(); dz.classList.remove('drag-active');
        const fi = document.getElementById('restore-file-input');
        fi.files = e.dataTransfer.files;
        updateLabel(fi);
    });
}

// Show spinner on backup submit
document.querySelector('#modal-backup form')?.addEventListener('submit', function() {
    document.getElementById('backup-spinner').style.display = 'inline-block';
    document.getElementById('btn-do-backup').disabled = true;
    document.getElementById('btn-do-backup').querySelector('span + *') || null;
});
</script>

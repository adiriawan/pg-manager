<?php
ob_start(); // Buffer output agar header() tetap bisa dipanggil dari file yang di-include
require_once 'config.php';

if (!isConnected()) {
    header('Location: index.php');
    exit;
}

$pdo    = getPdo();
$creds  = getCredentials();
$page   = $_GET['page'] ?? 'table-editor';
$schema = $_GET['schema'] ?? 'public';
$table  = $_GET['table'] ?? null;

// Blokir akses schema sistem di seluruh app
if (in_array(strtolower($schema), systemSchemas())) {
    $schema = isMySQL() ? ($creds['dbname'] ?? 'public') : 'public';
    $table  = null;
}

// ── Export handler — HARUS sebelum output HTML apapun ─────────
if (isset($_GET['export']) && $table && in_array($page, ['table-editor'])) {
    $fmt         = $_GET['export'];
    $exportSchema = $schema;
    $exportTable  = $table;
    $safeName     = preg_replace('/[^a-zA-Z0-9_]/', '_', $exportTable);
    try {
        $rows = $pdo->query('SELECT * FROM "' . $exportSchema . '"."' . $exportTable . '"')->fetchAll();
        if ($fmt === 'json') {
            header('Content-Type: application/json');
            header("Content-Disposition: attachment; filename=\"{$safeName}.json\"");
            echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($fmt === 'csv' || $fmt === 'excel') {
            $filename = $fmt === 'excel' ? "{$safeName}.csv" : "{$safeName}.csv";
            header('Content-Type: text/csv; charset=UTF-8');
            header("Content-Disposition: attachment; filename=\"{$filename}\"");
            echo "\xEF\xBB\xBF"; // BOM UTF-8 agar Excel mengenali encoding
            $out = fopen('php://output', 'w');
            if ($rows) {
                fputcsv($out, array_keys($rows[0]), ',', '"', '\\');
                foreach ($rows as $r) {
                    fputcsv($out, array_map(fn($v) => $v ?? '', $r), ',', '"', '\\');
                }
            }
            fclose($out);
            exit;
        }
    } catch (Exception $e) {
        // Jika gagal, lanjutkan render halaman biasa
    }
}

// ── Export SQL Editor — sebelum output HTML ────────────────────
if (isset($_GET['export']) && $page === 'sql-editor' && isset($_SESSION['last_sql'])) {
    $fmt = $_GET['export'];
    try {
        $rows = $pdo->query($_SESSION['last_sql'])->fetchAll();
        if ($fmt === 'json') {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="query_result.json"');
            echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($fmt === 'csv' || $fmt === 'excel') {
            $filename = $fmt === 'excel' ? 'query_result.csv' : 'query_result.csv';
            header('Content-Type: text/csv; charset=UTF-8');
            header("Content-Disposition: attachment; filename=\"{$filename}\"");
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            if ($rows) {
                fputcsv($out, array_keys($rows[0]), ',', '"', '\\');
                foreach ($rows as $r) {
                    fputcsv($out, array_map(fn($v) => $v ?? '', $r), ',', '"', '\\');
                }
            }
            fclose($out);
            exit;
        }
    } catch (Exception $e) {}
}

// Ambil semua schema
$schemas = [];
try {
    $schemas = $pdo->query(schemataQuery())->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// Ambil semua tabel di schema aktif
$tables = [];
try {
    $stmt = $pdo->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE' ORDER BY table_name");
    $stmt->execute([$schema]);
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// Ambil semua views
$views = [];
try {
    $stmt = $pdo->prepare("SELECT table_name FROM information_schema.views WHERE table_schema = ? ORDER BY table_name");
    $stmt->execute([$schema]);
    $views = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PG Manager – <?= htmlspecialchars($creds['dbname']) ?></title>
<!-- Anti-flicker: terapkan tema SEBELUM CSS/body render -->
<script>
(function(){
    var t = localStorage.getItem('pgm_theme');
    // dark = default (tidak ada class), light = tambah class 'light'
    if (t === 'light') document.documentElement.classList.add('light');
    // Set background langsung di <html> agar tidak ada flash putih
    document.documentElement.style.background =
        t === 'light' ? '#f0f2f5' : '#1c1c1c';
})();
</script>
<link rel="stylesheet" href="assets/style.css">
<!-- Hapus inline background setelah CSS load agar CSS variable yang mengontrol -->
<script>document.documentElement.style.background = '';</script>
</head>
<body class="app-page">

<!-- Sidebar -->
<aside class="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <svg width="24" height="24" viewBox="0 0 32 32" fill="none"><circle cx="16" cy="16" r="16" fill="#3ECF8E"/><path d="M9 22V10l7 4 7-4v12l-7-4-7 4z" fill="#fff"/></svg>
        </div>
        <div class="sidebar-db-info">
            <div class="sidebar-db-name"><?= htmlspecialchars($creds['dbname']) ?></div>
            <div class="sidebar-db-host">
                <span class="driver-badge driver-badge-<?= htmlspecialchars($creds['driver'] ?? 'pgsql') ?>"><?= isMySQL() ? 'MySQL' : 'PostgreSQL' ?></span>
                <?= htmlspecialchars($creds['host']) ?>:<?= htmlspecialchars($creds['port']) ?>
            </div>
        </div>
        <button class="theme-toggle" id="theme-toggle" onclick="toggleTheme()" title="Ganti tema">☀️</button>
    </div>

    <nav class="sidebar-nav">
        <!-- Favorit -->
        <div id="fav-list"></div>

        <div class="sidebar-section-title">Utama</div>
        <a href="app.php?page=table-editor&schema=<?= urlencode($schema) ?>" class="nav-item <?= $page === 'table-editor' ? 'active' : '' ?>">
            <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M0 2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v1H0V2zm0 4h16v2H0V6zm0 4h16v2H0v-2zm0 4h16v2a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2z"/></svg>
            Table Editor
        </a>
        <a href="app.php?page=sql-editor&schema=<?= urlencode($schema) ?>" class="nav-item <?= $page === 'sql-editor' ? 'active' : '' ?>">
            <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M5.854 4.854a.5.5 0 1 0-.708-.708l-3.5 3.5a.5.5 0 0 0 0 .708l3.5 3.5a.5.5 0 0 0 .708-.708L2.707 8l3.147-3.146zm4.292 0a.5.5 0 0 1 .708-.708l3.5 3.5a.5.5 0 0 1 0 .708l-3.5 3.5a.5.5 0 0 1-.708-.708L13.293 8l-3.147-3.146z"/></svg>
            SQL Editor
        </a>
        <a href="app.php?page=database&schema=<?= urlencode($schema) ?>" class="nav-item <?= $page === 'database' ? 'active' : '' ?>">
            <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><ellipse cx="8" cy="3.5" rx="7" ry="2.5"/><path d="M1 6c0 1.381 3.134 2.5 7 2.5S15 7.381 15 6V3.5c0 1.381-3.134 2.5-7 2.5S1 4.881 1 3.5V6zm0 4c0 1.381 3.134 2.5 7 2.5s7-1.119 7-2.5V8c0 1.381-3.134 2.5-7 2.5S1 9.381 1 8v2zm0 4c0 1.381 3.134 2.5 7 2.5s7-1.119 7-2.5v-2c0 1.381-3.134 2.5-7 2.5S1 13.381 1 12v2z"/></svg>
            Database
        </a>
        <a href="app.php?page=backup&schema=<?= urlencode($schema) ?>" class="nav-item <?= $page === 'backup' ? 'active' : '' ?>">
            <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/><path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/></svg>
            Backup &amp; Restore
        </a>
        <a href="app.php?page=import-csv&schema=<?= urlencode($schema) ?>" class="nav-item <?= $page === 'import-csv' ? 'active' : '' ?>">
            <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/><path d="M7.646 1.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1-.708.708L8.5 2.707V11.5a.5.5 0 0 1-1 0V2.707L5.354 4.854a.5.5 0 1 1-.708-.708l3-3z"/></svg>
            Import CSV
        </a>
        <a href="app.php?page=join-builder&schema=<?= urlencode($schema) ?>" class="nav-item <?= $page === 'join-builder' ? 'active' : '' ?>">
            <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1 4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V4zm1 0v2h2V4H2zm7-1a1 1 0 0 0-1 1v2a1 1 0 0 0 1 1h2a1 1 0 0 0 1-1V4a1 1 0 0 0-1-1H9zm0 1h2v2H9V4zm-4 5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V9zm1 0v2h2V9H6z"/><path d="M3 6v1h4v1H3v1l-2-1.5L3 6zm10 1v1H9V7h4v-1l2 1.5L13 9V8z"/></svg>
            Join Builder
        </a>
        <a href="app.php?page=erd&schema=<?= urlencode($schema) ?>" class="nav-item <?= $page === 'erd' ? 'active' : '' ?>">
            <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M6 3.5A1.5 1.5 0 0 1 7.5 2h1A1.5 1.5 0 0 1 10 3.5v1A1.5 1.5 0 0 1 8.5 6v1H11a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-1 0V8h-5v.5a.5.5 0 0 1-1 0v-1A.5.5 0 0 1 5 7h2.5V6A1.5 1.5 0 0 1 6 4.5v-1zM8.5 5a.5.5 0 0 0 .5-.5v-1a.5.5 0 0 0-.5-.5h-1a.5.5 0 0 0-.5.5v1a.5.5 0 0 0 .5.5h1zM3 11.5A1.5 1.5 0 0 1 4.5 10h1A1.5 1.5 0 0 1 7 11.5v1A1.5 1.5 0 0 1 5.5 14h-1A1.5 1.5 0 0 1 3 12.5v-1zm1.5-.5a.5.5 0 0 0-.5.5v1a.5.5 0 0 0 .5.5h1a.5.5 0 0 0 .5-.5v-1a.5.5 0 0 0-.5-.5h-1zm4.5.5a1.5 1.5 0 0 1 1.5-1.5h1a1.5 1.5 0 0 1 1.5 1.5v1a1.5 1.5 0 0 1-1.5 1.5h-1A1.5 1.5 0 0 1 9 12.5v-1zm1.5-.5a.5.5 0 0 0-.5.5v1a.5.5 0 0 0 .5.5h1a.5.5 0 0 0 .5-.5v-1a.5.5 0 0 0-.5-.5h-1z"/></svg>
            ERD
        </a>
        <a href="app.php?page=designer&schema=<?= urlencode($schema) ?>" class="nav-item <?= $page === 'designer' ? 'active' : '' ?>">
            <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M2 6a6 6 0 1 1 10.174 4.31c-.203.196-.359.4-.453.619l-.762 1.769A.5.5 0 0 1 10.5 13h-5a.5.5 0 0 1-.46-.302l-.761-1.77a1.964 1.964 0 0 0-.453-.618A5.984 5.984 0 0 1 2 6zm3 8.5a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1l-.224.447a1 1 0 0 1-.894.553H6.618a1 1 0 0 1-.894-.553L5.5 15a.5.5 0 0 1-.5-.5z"/></svg>
            Designer
        </a>

        <div class="sidebar-section-title" style="margin-top:16px">Schema</div>
        <div class="schema-selector">
            <?php foreach ($schemas as $s): ?>
            <a href="app.php?page=<?= urlencode($page) ?>&schema=<?= urlencode($s) ?>" class="schema-item <?= $s === $schema ? 'active' : '' ?>">
                <?= htmlspecialchars($s) ?>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if ($page === 'table-editor'): ?>
        <div class="sidebar-section-title" style="margin-top:16px">Tables</div>
        <?php foreach ($tables as $t): ?>
        <a href="app.php?page=table-editor&schema=<?= urlencode($schema) ?>&table=<?= urlencode($t) ?>" class="nav-item nav-item-sub <?= $table === $t ? 'active' : '' ?>">
            <svg width="12" height="12" fill="currentColor" viewBox="0 0 16 16"><path d="M0 2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v1H0V2zm0 4h16v2H0V6zm0 4h16v2H0v-2zm0 4h16v2a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2z"/></svg>
            <?= htmlspecialchars($t) ?>
            <button class="btn-unpin" style="opacity:0"
                onclick="togglePin('<?= addslashes($schema) ?>','<?= addslashes($t) ?>',event)"
                title="Pin ke favorit">⭐</button>
        </a>
        <?php endforeach; ?>
        <?php if (!empty($views)): ?>
        <div class="sidebar-section-title" style="margin-top:8px">Views</div>
        <?php foreach ($views as $v): ?>
        <a href="app.php?page=table-editor&schema=<?= urlencode($schema) ?>&table=<?= urlencode($v) ?>&type=view" class="nav-item nav-item-sub">
            <svg width="12" height="12" fill="currentColor" viewBox="0 0 16 16"><path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zm-8 3.5A3.5 3.5 0 1 1 8 4.5a3.5 3.5 0 0 1 0 7z"/></svg>
            <?= htmlspecialchars($v) ?>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php endif; ?>
    </nav>

        <a href="app.php?page=help&schema=<?= urlencode($schema) ?>" class="nav-item <?= $page === 'help' ? 'active' : '' ?>">
            <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/><path d="M5.255 5.786a.237.237 0 0 0 .241.247h.825c.138 0 .248-.113.266-.25.09-.656.54-1.134 1.342-1.134.686 0 1.314.343 1.314 1.168 0 .635-.374.927-.965 1.371-.673.489-1.206 1.06-1.168 1.987l.003.217a.25.25 0 0 0 .25.246h.811a.25.25 0 0 0 .25-.25v-.105c0-.718.273-.927 1.01-1.486.609-.463 1.244-.977 1.244-2.056 0-1.511-1.276-2.241-2.673-2.241-1.267 0-2.655.59-2.75 2.286zm1.557 5.763c0 .533.425.927 1.01.927.609 0 1.028-.394 1.028-.927 0-.552-.42-.94-1.029-.94-.584 0-1.009.388-1.009.94z"/></svg>
            Panduan
        </a>

    <div class="sidebar-footer">
        <form method="POST" action="index.php">
            <input type="hidden" name="action" value="disconnect">
            <button type="submit" class="btn-disconnect">Putus Koneksi</button>
        </form>
    </div>
</aside>

<!-- Main Content -->
<main class="main-content <?= in_array($page, ['erd','designer']) ? 'main-erd' : '' ?>">

    <!-- ── Breadcrumb ────────────────────────────────────── -->
    <?php
    $pageLabels = [
        'table-editor' => 'Table Editor', 'sql-editor'   => 'SQL Editor',
        'database'     => 'Database',     'erd'          => 'ERD',
        'backup'       => 'Backup & Restore', 'join-builder' => 'Join Builder',
        'import-csv'   => 'Import CSV',   'alter-table'  => 'ALTER TABLE',
        'help'         => 'Panduan',      'designer'     => 'DB Designer',
    ];
    $crumbs = [
        ['label' => htmlspecialchars($creds['dbname']), 'url' => 'app.php?page=database&schema=' . urlencode($schema)],
        ['label' => htmlspecialchars($schema),          'url' => 'app.php?page=table-editor&schema=' . urlencode($schema)],
    ];
    if ($table && in_array($page, ['table-editor','alter-table'])) {
        $crumbs[] = ['label' => htmlspecialchars($table), 'url' => 'app.php?page=table-editor&schema=' . urlencode($schema) . '&table=' . urlencode($table)];
    }
    if ($page !== 'table-editor') {
        $crumbs[] = ['label' => $pageLabels[$page] ?? $page, 'url' => null];
    } elseif ($table) {
        // Table editor dengan tabel aktif — tabel sudah jadi crumb terakhir
    } else {
        $crumbs[] = ['label' => 'Table Editor', 'url' => null];
    }
    ?>
    <nav class="breadcrumb" aria-label="breadcrumb">
        <?php foreach ($crumbs as $i => $c): ?>
            <?php if ($i > 0): ?><span class="bc-sep">›</span><?php endif; ?>
            <?php if ($c['url'] && $i < count($crumbs) - 1): ?>
                <a href="<?= $c['url'] ?>" class="bc-item"><?= $c['label'] ?></a>
            <?php else: ?>
                <span class="bc-item bc-current"><?= $c['label'] ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <?php
    switch ($page) {
        case 'table-editor':
            include 'pages/table_editor.php';
            break;
        case 'sql-editor':
            include 'pages/sql_editor.php';
            break;
        case 'database':
            include 'pages/database.php';
            break;
        case 'join-builder':
            include 'pages/join_builder.php';
            break;
        case 'erd':
            include 'pages/erd.php';
            break;
        case 'designer':
            include 'pages/designer.php';
            break;
        case 'backup':
            include 'pages/backup.php';
            break;
        case 'import-csv':
            include 'pages/import_csv.php';
            break;
        case 'alter-table':
            include 'pages/alter_table.php';
            break;
        case 'help':
            include 'pages/help.php';
            break;
        default:
            include 'pages/table_editor.php';
    }
    ?>
</main>

<script src="assets/app.js"></script>
</body>
</html><?php ob_end_flush(); ?>

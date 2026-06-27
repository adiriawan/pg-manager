<?php
require_once '../config.php';
header('Content-Type: application/json');

if (!isConnected()) { echo json_encode(['error' => 'Tidak terkoneksi']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'Method not allowed']); exit; }

verifyCsrf();

$action = $_POST['action'] ?? '';
$dbName = trim($_POST['dbname'] ?? '');

// Validasi nama database hanya untuk aksi yang membutuhkan nama
if (in_array($action, ['create','drop'])) {
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_\-]{0,62}$/', $dbName)) {
        echo json_encode(['error' => 'Nama database tidak valid. Gunakan huruf, angka, _ atau - dan awali dengan huruf.']);
        exit;
    }
}

// Koneksi ke postgres (database maintenance) agar bisa CREATE/DROP DATABASE
$creds = getCredentials();
try {
    $pgCreds = array_merge($creds, ['dbname' => 'postgres']);
    $pgPdo   = getConnection($pgCreds);
} catch (Exception $e) {
    // Fallback ke template1
    try {
        $pgCreds = array_merge($creds, ['dbname' => 'template1']);
        $pgPdo   = getConnection($pgCreds);
    } catch (Exception $e2) {
        echo json_encode(['error' => 'Gagal konek ke postgres: ' . $e->getMessage()]);
        exit;
    }
}

// CREATE DATABASE
if ($action === 'create') {
    $owner    = trim($_POST['owner']    ?? $creds['user']);
    $encoding = trim($_POST['encoding'] ?? 'UTF8');
    $template = trim($_POST['template'] ?? 'template0');

    // Whitelist encoding
    $allowedEncodings = ['UTF8','SQL_ASCII','LATIN1','WIN1252','EUC_JP','EUC_KR','EUC_CN'];
    if (!in_array(strtoupper($encoding), $allowedEncodings)) $encoding = 'UTF8';
    // Whitelist template
    if (!in_array($template, ['template0','template1'])) $template = 'template0';
    // Validasi owner: hanya alfanumerik + underscore
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $owner)) $owner = $creds['user'];

    try {
        // PostgreSQL tidak mengizinkan CREATE DATABASE dalam transaksi,
        // gunakan PDO::ATTR_AUTOCOMMIT via exec langsung
        $pgPdo->exec('CREATE DATABASE "' . $dbName . '"'
            . ' OWNER "' . $owner . '"'
            . ' ENCODING \'' . $encoding . '\''
            . ' TEMPLATE ' . $template);
        echo json_encode(['ok' => true, 'message' => "Database \"$dbName\" berhasil dibuat."]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// DROP DATABASE
if ($action === 'drop') {
    $currentDb = $creds['dbname'];
    if ($dbName === $currentDb) {
        echo json_encode(['error' => 'Tidak bisa menghapus database yang sedang digunakan.']);
        exit;
    }
    // Blokir database sistem
    if (in_array($dbName, ['postgres','template0','template1'])) {
        echo json_encode(['error' => 'Tidak bisa menghapus database sistem.']);
        exit;
    }
    try {
        // Terminasi koneksi aktif ke database yang akan di-drop
        $pgPdo->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$dbName' AND pid <> pg_backend_pid()");
        $pgPdo->exec('DROP DATABASE "' . $dbName . '"');
        echo json_encode(['ok' => true, 'message' => "Database \"$dbName\" berhasil dihapus."]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// LIST DATABASES
if ($action === 'list') {
    try {
        $stmt = $pgPdo->query("SELECT datname, pg_size_pretty(pg_database_size(datname)) as size,
            pg_catalog.pg_get_userbyid(datdba) as owner
            FROM pg_database WHERE datistemplate = false ORDER BY datname");
        echo json_encode(['ok' => true, 'databases' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['error' => 'Action tidak dikenali']);

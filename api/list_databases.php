<?php
require_once '../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$driver = in_array($_POST['driver'] ?? '', ['pgsql','mysql','sqlite']) ? $_POST['driver'] : 'pgsql';
// Simpan sementara ke session agar fungsi helper bisa digunakan
$_SESSION['db_creds'] = ['driver' => $driver];

// SQLite: tidak perlu list databases — langsung connect ke file
if ($driver === 'sqlite') {
    $path = trim($_POST['sqlite_path'] ?? '');
    if (!$path) {
        http_response_code(400);
        echo json_encode(['error' => 'Path file SQLite tidak boleh kosong']);
    } else {
        // Validasi: bisa dibuka atau dibuat
        echo json_encode(['databases' => [basename($path)]]);
    }
    exit;
}

$host     = trim($_POST['host'] ?? 'localhost');
$port     = trim($_POST['port'] ?? ($driver === 'mysql' ? '3306' : '5432'));
$user     = trim($_POST['user'] ?? ($driver === 'mysql' ? 'root' : 'postgres'));
$password = $_POST['password'] ?? '';

if ($driver === 'mysql') {
    // MySQL: konek ke information_schema untuk list databases
    $creds = ['driver'=>'mysql','host'=>$host,'port'=>$port,'user'=>$user,'password'=>$password,'dbname'=>'information_schema'];
    try {
        $pdo = getConnection($creds);
        $stmt = $pdo->query("SELECT schema_name FROM information_schema.schemata
            WHERE schema_name NOT IN ('mysql','information_schema','performance_schema','sys')
            ORDER BY schema_name");
        $databases = $stmt->fetchAll(PDO::FETCH_COLUMN);
        // Jika kosong (user tidak punya db lain), tampilkan semua
        if (empty($databases)) {
            $stmt = $pdo->query("SELECT schema_name FROM information_schema.schemata ORDER BY schema_name");
            $databases = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        echo json_encode(['databases' => $databases]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
} else {
    // PostgreSQL: konek ke postgres untuk list databases
    $creds = ['driver'=>'pgsql','host'=>$host,'port'=>$port,'user'=>$user,'password'=>$password,'dbname'=>'postgres'];
    try {
        $pdo = getConnection($creds);
        $stmt = $pdo->query("SELECT datname FROM pg_database WHERE datistemplate = false ORDER BY datname");
        $databases = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['databases' => $databases]);
    } catch (Exception $e) {
        try {
            $creds['dbname'] = 'template1';
            $pdo = getConnection($creds);
            $stmt = $pdo->query("SELECT datname FROM pg_database WHERE datistemplate = false ORDER BY datname");
            $databases = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo json_encode(['databases' => $databases]);
        } catch (Exception $e2) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}

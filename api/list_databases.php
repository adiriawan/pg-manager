<?php
require_once '../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$creds = [
    'host'     => trim($_POST['host'] ?? 'localhost'),
    'port'     => trim($_POST['port'] ?? '5432'),
    'user'     => trim($_POST['user'] ?? 'postgres'),
    'password' => $_POST['password'] ?? '',
    'dbname'   => 'postgres', // koneksi awal ke postgres untuk listing
];

try {
    $pdo = getConnection($creds);
    $stmt = $pdo->query("
        SELECT datname FROM pg_database
        WHERE datistemplate = false
        ORDER BY datname
    ");
    $databases = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['databases' => $databases]);
} catch (Exception $e) {
    // Coba dbname = template1 sebagai fallback
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

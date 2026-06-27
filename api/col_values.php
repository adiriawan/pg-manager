<?php
// API: ambil nilai unik sebuah kolom untuk column filter dropdown
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!isConnected()) {
    echo json_encode(['error' => 'Tidak terkoneksi']);
    exit;
}

$pdo    = getPdo();
$schema = $_GET['schema'] ?? '';
$table  = $_GET['table']  ?? '';
$col    = $_GET['col']    ?? '';

if (!$schema || !$table || !$col) {
    echo json_encode(['error' => 'Parameter tidak lengkap']);
    exit;
}

// Validasi nama (hanya huruf, angka, underscore)
if (!preg_match('/^[\w]+$/', $schema) || !preg_match('/^[\w]+$/', $table) || !preg_match('/^[\w]+$/', $col)) {
    echo json_encode(['error' => 'Nama tidak valid']);
    exit;
}

try {
    // Ambil nilai unik, max 500
    $stmt = $pdo->prepare(
        'SELECT DISTINCT "' . $col . '"::text AS val
         FROM "' . $schema . '"."' . $table . '"
         WHERE "' . $col . '" IS NOT NULL
         ORDER BY 1
         LIMIT 500'
    );
    $stmt->execute();
    $values = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Hitung apakah ada NULL
    $nullStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM "' . $schema . '"."' . $table . '" WHERE "' . $col . '" IS NULL'
    );
    $nullStmt->execute();
    $hasNull = (int)$nullStmt->fetchColumn() > 0;

    echo json_encode([
        'values'  => $values,
        'hasNull' => $hasNull,
        'count'   => count($values),
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

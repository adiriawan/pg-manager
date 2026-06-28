<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (!isConnected()) { echo json_encode(['error' => 'Tidak terkoneksi']); exit; }

$pdo    = getPdo();
$schema = $_GET['schema'] ?? '';
$table  = $_GET['table']  ?? '';
$col    = $_GET['col']    ?? '';

if (!$schema || !$table || !$col) {
    echo json_encode(['error' => 'Parameter tidak lengkap']); exit;
}

if (!preg_match('/^[\w]+$/', $schema) || !preg_match('/^[\w]+$/', $table) || !preg_match('/^[\w]+$/', $col)) {
    echo json_encode(['error' => 'Nama tidak valid']); exit;
}

try {
    $qtbl    = qualifiedTable($schema, $table);
    $qcol    = qi($col);
    $colCast = castText($qcol);

    $stmt = $pdo->prepare(
        "SELECT DISTINCT {$colCast} AS val
         FROM {$qtbl}
         WHERE {$qcol} IS NOT NULL
         ORDER BY 1
         LIMIT 500"
    );
    $stmt->execute();
    $values = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $nullStmt = $pdo->prepare("SELECT COUNT(*) FROM {$qtbl} WHERE {$qcol} IS NULL");
    $nullStmt->execute();
    $hasNull = (int)$nullStmt->fetchColumn() > 0;

    echo json_encode(['values' => $values, 'hasNull' => $hasNull, 'count' => count($values)]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

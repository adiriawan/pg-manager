<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (!isConnected()) { echo json_encode(['error' => 'Tidak terkoneksi']); exit; }

$pdo    = getPdo();
$schema = $_POST['schema'] ?? '';
$table  = $_POST['table']  ?? '';
$pkCol  = $_POST['pk_col'] ?? '';
$pkVal  = $_POST['pk_val'] ?? '';
$col    = $_POST['col']    ?? '';
$val    = $_POST['val']    ?? null;
$setNull = isset($_POST['set_null']) && $_POST['set_null'] === '1';

if (!$schema || !$table || !$pkCol || $pkVal === '' || !$col) {
    echo json_encode(['error' => 'Parameter tidak lengkap']); exit;
}

// Blokir akses ke schema sistem
if (in_array(strtolower($schema), systemSchemas())) {
    echo json_encode(['error' => 'Akses ke schema sistem tidak diizinkan']); exit;
}

try {
    // Validasi schema
    $schemaChk = $pdo->prepare("SELECT 1 FROM information_schema.schemata WHERE schema_name = ? LIMIT 1");
    $schemaChk->execute([$schema]);
    if (!$schemaChk->fetchColumn()) {
        echo json_encode(['error' => 'Schema tidak valid']); exit;
    }

    // Validasi kolom
    $chk = $pdo->prepare(columnExistsQuery());
    $chk->execute([$schema, $table, $col]);
    if (!$chk->fetchColumn()) {
        echo json_encode(['error' => 'Kolom tidak valid']); exit;
    }

    // Validasi pkCol
    $pkChk = $pdo->prepare(columnExistsQuery());
    $pkChk->execute([$schema, $table, $pkCol]);
    if (!$pkChk->fetchColumn()) {
        echo json_encode(['error' => 'Primary key tidak valid']); exit;
    }

    $newVal = $setNull ? null : ($val === '' ? null : $val);
    $sql    = 'UPDATE ' . qualifiedTable($schema, $table) . ' SET ' . qi($col) . ' = ? WHERE ' . qi($pkCol) . ' = ?';
    $stmt   = $pdo->prepare($sql);
    $stmt->execute([$newVal, $pkVal]);

    echo json_encode(['ok' => true, 'val' => $newVal]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

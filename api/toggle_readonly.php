<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (!isConnected()) { echo json_encode(['error' => 'Tidak terkoneksi']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'Method not allowed']); exit; }
verifyCsrf();

$_SESSION['readonly'] = ($_POST['state'] ?? '') === '1';
echo json_encode(['ok' => true, 'readonly' => $_SESSION['readonly']]);

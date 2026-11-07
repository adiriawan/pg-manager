<?php
require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json');

if (!isConnected() || !isSQLite()) {
    echo json_encode(['error' => 'Tidak terhubung ke SQLite.']);
    exit;
}

$creds       = getCredentials();
$localPath   = $creds['dbname']       ?? '';
$androidPath = $creds['android_path'] ?? '';
$package     = $creds['android_pkg']  ?? '';

if (!$androidPath || !$localPath || !$package) {
    echo json_encode(['error' => 'android_path, android_pkg, atau dbname tidak tersedia.']);
    exit;
}

// Ekspansi ~ ke home directory
$localPath = preg_replace('/^~/', $_SERVER['HOME'] ?? '/Users/' . get_current_user(), $localPath);

$adb = '/Users/adiriawan/Library/Android/sdk/platform-tools/adb';
if (!is_executable($adb)) {
    $adb = trim(shell_exec('which adb 2>/dev/null') ?? '');
}
if (!$adb || !is_executable($adb)) {
    echo json_encode(['error' => 'adb tidak ditemukan. Pastikan Android SDK terinstal.']);
    exit;
}

// Cari emulator yang sedang berjalan
$devices = shell_exec("{$adb} devices 2>/dev/null") ?? '';
preg_match('/^(emulator-\d+)\s+device/m', $devices, $m);
$serial = $m[1] ?? '';
$serialFlag = $serial ? "-s {$serial}" : '';

// Aktifkan root agar bisa akses data app release
shell_exec("{$adb} {$serialFlag} root 2>/dev/null");
sleep(1);

// Pastikan direktori tujuan ada
$dir = dirname($localPath);
if (!is_dir($dir)) {
    echo json_encode(['error' => "Direktori tujuan tidak ada: {$dir}"]);
    exit;
}

// Pakai adb pull langsung (butuh root)
$errFile = tempnam(sys_get_temp_dir(), 'adb_err_');
$cmd = "{$adb} {$serialFlag} pull " . escapeshellarg($androidPath) . " " . escapeshellarg($localPath) . " 2>{$errFile}";
exec($cmd, $out, $exitCode);
$errMsg = file_get_contents($errFile);
@unlink($errFile);

if ($exitCode !== 0 || !file_exists($localPath)) {
    $detail = trim($errMsg ?: implode(' ', $out) ?: 'Gagal menarik file.');
    echo json_encode(['error' => $detail]);
    exit;
}

$written = filesize($localPath);

echo json_encode([
    'ok'      => true,
    'message' => 'Database diperbarui dari emulator.',
    'time'    => date('H:i:s'),
    'size'    => $written,
    'device'  => $serial ?: 'auto',
]);

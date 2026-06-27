<?php
session_start();

define('APP_NAME', 'PG Manager');
define('APP_VERSION', '1.0');

function getConnection(array $creds): PDO {
    $dsn = "pgsql:host={$creds['host']};port={$creds['port']};dbname={$creds['dbname']}";
    $pdo = new PDO($dsn, $creds['user'], $creds['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    return $pdo;
}

function getCredentials(): ?array {
    return $_SESSION['db_creds'] ?? null;
}

function isConnected(): bool {
    return isset($_SESSION['db_creds']) && isset($_SESSION['db_connected']) && $_SESSION['db_connected'] === true;
}

function tryConnect(array $creds): bool {
    try {
        $pdo = getConnection($creds);
        $pdo->query('SELECT 1');
        $_SESSION['db_creds'] = $creds;
        $_SESSION['db_connected'] = true;
        return true;
    } catch (Exception $e) {
        $_SESSION['db_error'] = $e->getMessage();
        return false;
    }
}

function getPdo(): ?PDO {
    $creds = getCredentials();
    if (!$creds) return null;
    try {
        return getConnection($creds);
    } catch (Exception $e) {
        return null;
    }
}

// BUG-04 FIX: CSRF token helper
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrfToken(), $token)) {
        http_response_code(403);
        die(json_encode(['error' => 'Invalid CSRF token']));
    }
}

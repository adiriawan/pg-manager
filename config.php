<?php
session_start();

define('APP_NAME', 'PG Manager');
define('APP_VERSION', '1.1');

// ── Koneksi ──────────────────────────────────────────────────────
function getConnection(array $creds): PDO {
    $driver = $creds['driver'] ?? 'pgsql';
    if ($driver === 'mysql') {
        $dsn = "mysql:host={$creds['host']};port={$creds['port']};dbname={$creds['dbname']};charset=utf8mb4";
    } else {
        $dsn = "pgsql:host={$creds['host']};port={$creds['port']};dbname={$creds['dbname']}";
    }
    $pdo = new PDO($dsn, $creds['user'], $creds['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
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
        $_SESSION['db_creds']     = $creds;
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

// ── Driver helper ─────────────────────────────────────────────────
function dbDriver(): string {
    return getCredentials()['driver'] ?? 'pgsql';
}

function isMySQL(): bool { return dbDriver() === 'mysql'; }
function isPgSQL(): bool { return dbDriver() === 'pgsql'; }

// ── SQL Abstraction ───────────────────────────────────────────────

/** Quote identifier (kolom/tabel/schema) sesuai driver */
function qi(string $name): string {
    if (isMySQL()) return '`' . str_replace('`', '``', $name) . '`';
    return '"' . str_replace('"', '""', $name) . '"';
}

/** Cast kolom ke text untuk perbandingan string */
function castText(string $expr): string {
    if (isMySQL()) return "CAST({$expr} AS CHAR)";
    return "{$expr}::text";
}

/** LIKE case-insensitive */
function ilike(string $col, string $placeholder = '?'): string {
    // MySQL LIKE sudah case-insensitive by default (untuk collation _ci)
    if (isMySQL()) return "{$col} LIKE {$placeholder}";
    return "{$col} ILIKE {$placeholder}";
}

/** NOT LIKE case-insensitive */
function notIlike(string $col, string $placeholder = '?'): string {
    if (isMySQL()) return "{$col} NOT LIKE {$placeholder}";
    return "{$col} NOT ILIKE {$placeholder}";
}

/** Schema sistem yang diblokir */
function systemSchemas(): array {
    if (isMySQL()) return ['mysql','information_schema','performance_schema','sys'];
    return ['pg_catalog','information_schema','pg_toast'];
}

/** Default port per driver */
function defaultPort(): string {
    return isMySQL() ? '3306' : '5432';
}

/** Nama tabel qualified: schema.table */
function qualifiedTable(string $schema, string $table): string {
    if (isMySQL()) {
        // MySQL: `schema`.`table`
        return qi($schema) . '.' . qi($table);
    }
    return qi($schema) . '.' . qi($table);
}

/** Query untuk ambil daftar schema/database yang bisa diakses */
function schemataQuery(): string {
    if (isMySQL()) {
        $blocked = implode("','", systemSchemas());
        return "SELECT schema_name FROM information_schema.schemata
                WHERE schema_name NOT IN ('{$blocked}') ORDER BY schema_name";
    }
    return "SELECT schema_name FROM information_schema.schemata
            WHERE schema_name NOT IN ('pg_catalog','information_schema','pg_toast')
            ORDER BY schema_name";
}

/** Query untuk cek apakah kolom valid */
function columnExistsQuery(): string {
    return "SELECT 1 FROM information_schema.columns
            WHERE table_schema=? AND table_name=? AND column_name=? LIMIT 1";
}

/** Query info kolom lengkap untuk table editor */
function columnsInfoQuery(string $schema, string $table): array {
    if (isMySQL()) {
        return [
            "SELECT c.COLUMN_NAME as column_name,
                    c.DATA_TYPE as data_type,
                    c.IS_NULLABLE as is_nullable,
                    c.COLUMN_DEFAULT as column_default,
                    CASE WHEN c.COLUMN_KEY = 'PRI' THEN 'YES' ELSE 'NO' END as is_primary
             FROM information_schema.COLUMNS c
             WHERE c.TABLE_SCHEMA = ? AND c.TABLE_NAME = ?
             ORDER BY c.ORDINAL_POSITION",
            [$schema, $table]
        ];
    }
    return [
        "SELECT c.column_name, c.data_type, c.is_nullable, c.column_default,
                CASE WHEN pk.column_name IS NOT NULL THEN 'YES' ELSE 'NO' END as is_primary
         FROM information_schema.columns c
         LEFT JOIN (
             SELECT ku.column_name
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage ku
                 ON tc.constraint_name = ku.constraint_name AND tc.table_schema = ku.table_schema
             WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = ? AND tc.table_name = ?
         ) pk ON c.column_name = pk.column_name
         WHERE c.table_schema = ? AND c.table_name = ?
         ORDER BY c.ordinal_position",
        [$schema, $table, $schema, $table]
    ];
}

/** TRUNCATE sesuai driver */
function truncateSql(string $schema, string $table): string {
    if (isMySQL()) return 'TRUNCATE TABLE ' . qualifiedTable($schema, $table);
    return 'TRUNCATE TABLE ' . qualifiedTable($schema, $table) . ' RESTART IDENTITY';
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

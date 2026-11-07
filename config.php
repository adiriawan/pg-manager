<?php
session_start();

define('APP_NAME', 'PG Manager');
define('APP_VERSION', '1.1');

// ── Koneksi ──────────────────────────────────────────────────────
function getConnection(array $creds): PDO {
    $driver = $creds['driver'] ?? 'pgsql';
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ];
    $readOnly = !empty($_SESSION['readonly']);
    if ($driver === 'sqlite') {
        $dsn = 'sqlite:' . ($creds['dbname'] ?? ':memory:');
        $pdo = new PDO($dsn, null, null, $opts);
        $pdo->exec('PRAGMA foreign_keys = ON');
        if ($readOnly) $pdo->exec('PRAGMA query_only = ON');
        return $pdo;
    }
    if ($driver === 'mysql') {
        $dsn = "mysql:host={$creds['host']};port={$creds['port']};dbname={$creds['dbname']};charset=utf8mb4";
    } else {
        $dsn = "pgsql:host={$creds['host']};port={$creds['port']};dbname={$creds['dbname']}";
    }
    $pdo = new PDO($dsn, $creds['user'], $creds['password'], $opts);
    // Mode baca-saja ditegakkan di level database, bukan hanya di UI
    if ($readOnly) {
        $pdo->exec($driver === 'mysql' ? 'SET SESSION TRANSACTION READ ONLY' : 'SET default_transaction_read_only = on');
    }
    return $pdo;
}

function isReadOnly(): bool { return !empty($_SESSION['readonly']); }

const READONLY_MSG = 'Mode baca-saja aktif: perubahan data diblokir. Matikan mode ini di sidebar untuk mengubah data.';

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

function isMySQL(): bool  { return dbDriver() === 'mysql'; }
function isPgSQL(): bool  { return dbDriver() === 'pgsql'; }
function isSQLite(): bool { return dbDriver() === 'sqlite'; }

// ── SQL Abstraction ───────────────────────────────────────────────

/** Quote identifier (kolom/tabel/schema) sesuai driver */
function qi(string $name): string {
    if (isMySQL()) return '`' . str_replace('`', '``', $name) . '`';
    return '"' . str_replace('"', '""', $name) . '"';
}

/** Cast kolom ke text untuk perbandingan string */
function castText(string $expr): string {
    if (isPgSQL()) return "{$expr}::text";
    return "CAST({$expr} AS TEXT)";
}

/** LIKE case-insensitive */
function ilike(string $col, string $placeholder = '?'): string {
    if (isPgSQL()) return "{$col} ILIKE {$placeholder}";
    // MySQL dan SQLite: LIKE sudah case-insensitive untuk ASCII
    return "{$col} LIKE {$placeholder}";
}

/** NOT LIKE case-insensitive */
function notIlike(string $col, string $placeholder = '?'): string {
    if (isPgSQL()) return "{$col} NOT ILIKE {$placeholder}";
    return "{$col} NOT LIKE {$placeholder}";
}

/** Schema sistem yang diblokir */
function systemSchemas(): array {
    if (isMySQL())  return ['mysql','information_schema','performance_schema','sys'];
    if (isSQLite()) return [];
    return ['pg_catalog','information_schema','pg_toast'];
}

/** Default port per driver */
function defaultPort(): string {
    return isMySQL() ? '3306' : '5432';
}

/** Nama tabel qualified: schema.table */
function qualifiedTable(string $schema, string $table): string {
    if (isSQLite()) return qi($table);
    return qi($schema) . '.' . qi($table);
}

/** Query untuk ambil daftar schema/database yang bisa diakses.
 *  Mengembalikan null untuk SQLite (tangani di pemanggil). */
function schemataQuery(): ?string {
    if (isSQLite()) return null;
    if (isMySQL()) {
        $blocked = implode("','", systemSchemas());
        return "SELECT schema_name FROM information_schema.schemata
                WHERE schema_name NOT IN ('{$blocked}') ORDER BY schema_name";
    }
    return "SELECT schema_name FROM information_schema.schemata
            WHERE schema_name NOT IN ('pg_catalog','information_schema','pg_toast')
            ORDER BY schema_name";
}

/** Query untuk cek apakah kolom valid (bukan SQLite — gunakan columnExistsSQLite()) */
function columnExistsQuery(): string {
    return "SELECT 1 FROM information_schema.columns
            WHERE table_schema=? AND table_name=? AND column_name=? LIMIT 1";
}

/** Cek apakah kolom exist di SQLite */
function columnExistsSQLite(PDO $pdo, string $table, string $col): bool {
    $stmt = $pdo->query('PRAGMA table_info(' . qi($table) . ')');
    foreach ($stmt->fetchAll() as $r) {
        if ($r['name'] === $col) return true;
    }
    return false;
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

/** Ambil info kolom (abstraksi untuk semua driver) */
function getColumnsInfo(PDO $pdo, string $schema, string $table): array {
    if (isSQLite()) {
        $stmt = $pdo->query('PRAGMA table_info(' . qi($table) . ')');
        return array_map(function($r) {
            return [
                'column_name'    => $r['name'],
                'data_type'      => strtolower($r['type'] ?: 'text'),
                'is_nullable'    => $r['notnull'] ? 'NO' : 'YES',
                'column_default' => $r['dflt_value'],
                'is_primary'     => $r['pk'] > 0 ? 'YES' : 'NO',
            ];
        }, $stmt->fetchAll());
    }
    [$sql, $params] = columnsInfoQuery($schema, $table);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Ambil FK untuk semua tabel (SQLite pakai PRAGMA per tabel) */
function getForeignKeysSQLite(PDO $pdo): array {
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
    $fks = [];
    foreach ($tables as $tbl) {
        $stmt = $pdo->query('PRAGMA foreign_key_list(' . qi($tbl) . ')');
        foreach ($stmt->fetchAll() as $row) {
            $fks[] = [
                'from_table'      => $tbl,
                'from_column'     => $row['from'],
                'to_table'        => $row['table'],
                'to_column'       => $row['to'],
                'constraint_name' => "fk_{$tbl}_{$row['from']}",
            ];
        }
    }
    return $fks;
}

/** Ambil daftar tabel SQLite */
function getTablesSQLite(PDO $pdo): array {
    return $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
}

/** TRUNCATE sesuai driver */
function truncateSql(string $schema, string $table): string {
    if (isSQLite()) return 'DELETE FROM ' . qi($table);
    if (isMySQL())  return 'TRUNCATE TABLE ' . qualifiedTable($schema, $table);
    return 'TRUNCATE TABLE ' . qualifiedTable($schema, $table) . ' RESTART IDENTITY';
}

/** Foreign key satu tabel: [kolom => ['schema'=>, 'table'=>, 'column'=>]] (kolom tunggal saja). */
function getTableForeignKeys(PDO $pdo, string $schema, string $table): array {
    $out = [];
    try {
        if (isSQLite()) {
            foreach ($pdo->query('PRAGMA foreign_key_list(' . qi($table) . ')')->fetchAll() as $r) {
                $to = $r['to'];
                if ($to === null || $to === '') {
                    foreach (getColumnsInfo($pdo, 'main', $r['table']) as $c) if ($c['is_primary'] === 'YES') { $to = $c['column_name']; break; }
                }
                if ($to) $out[$r['from']] = ['schema' => 'main', 'table' => $r['table'], 'column' => $to];
            }
        } elseif (isMySQL()) {
            $st = $pdo->prepare("SELECT COLUMN_NAME c, REFERENCED_TABLE_SCHEMA rs, REFERENCED_TABLE_NAME rt, REFERENCED_COLUMN_NAME rc
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL");
            $st->execute([$schema, $table]);
            foreach ($st->fetchAll() as $r) $out[$r['c']] = ['schema' => $r['rs'], 'table' => $r['rt'], 'column' => $r['rc']];
        } else {
            $st = $pdo->prepare("SELECT kcu.column_name AS c, ccu.table_schema AS rs, ccu.table_name AS rt, ccu.column_name AS rc
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
                JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = tc.constraint_name AND ccu.constraint_schema = tc.table_schema
                WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = ? AND tc.table_name = ?");
            $st->execute([$schema, $table]);
            foreach ($st->fetchAll() as $r) $out[$r['c']] = ['schema' => $r['rs'], 'table' => $r['rt'], 'column' => $r['rc']];
        }
    } catch (Exception $e) {}
    return $out;
}

// ── Undo hapus baris ──────────────────────────────────────────────
const UNDO_TTL = 600;        // detik
const UNDO_MAX_ROWS = 5000;  // batas baris yang disimpan di sesi

/** Simpan baris yang akan dihapus agar bisa diurungkan. Mengembalikan jumlah baris tersimpan atau 0. */
function undoCapture(PDO $pdo, string $schema, string $table, string $where = '', array $params = [], string $label = 'hapus'): int {
    try {
        $st = $pdo->prepare('SELECT * FROM ' . qualifiedTable($schema, $table) . ($where ? " WHERE $where" : '') . ' LIMIT ' . (UNDO_MAX_ROWS + 1));
        $st->execute($params);
        $rows = $st->fetchAll();
    } catch (Exception $e) { return 0; }
    if (!$rows || count($rows) > UNDO_MAX_ROWS) { unset($_SESSION['undo_delete']); return 0; }
    foreach ($rows as $r) foreach ($r as $v) if (is_resource($v)) { unset($_SESSION['undo_delete']); return 0; }
    $_SESSION['undo_delete'] = ['schema' => $schema, 'table' => $table, 'rows' => $rows, 'time' => time(), 'label' => $label];
    return count($rows);
}

function undoAvailable(string $schema, string $table): ?array {
    $u = $_SESSION['undo_delete'] ?? null;
    if (!$u || $u['schema'] !== $schema || $u['table'] !== $table) return null;
    if (time() - $u['time'] > UNDO_TTL) { unset($_SESSION['undo_delete']); return null; }
    return $u;
}

/** Masukkan kembali baris yang dihapus. Mengembalikan jumlah baris dipulihkan; melempar Exception jika gagal. */
function undoRestore(PDO $pdo, string $schema, string $table): int {
    $u = undoAvailable($schema, $table);
    if (!$u) throw new Exception('Tidak ada penghapusan yang bisa diurungkan (sudah kedaluwarsa).');
    $rows = $u['rows'];
    $cols = array_keys($rows[0]);
    $override = '';
    if (isPgSQL()) {
        $st = $pdo->prepare("SELECT column_name, is_generated, identity_generation FROM information_schema.columns WHERE table_schema=? AND table_name=?");
        $st->execute([$schema, $table]);
        foreach ($st->fetchAll() as $c) {
            if (($c['is_generated'] ?? '') === 'ALWAYS') $cols = array_values(array_diff($cols, [$c['column_name']]));
            if (($c['identity_generation'] ?? '') === 'ALWAYS' && in_array($c['column_name'], $cols)) $override = ' OVERRIDING SYSTEM VALUE';
        }
    }
    $sql = 'INSERT INTO ' . qualifiedTable($schema, $table) . ' (' . implode(',', array_map('qi', $cols)) . ')' . $override
         . ' VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare($sql);
        foreach ($rows as $r) {
            $vals = [];
            foreach ($cols as $c) {
                $v = $r[$c];
                $vals[] = is_bool($v) ? ($v ? '1' : '0') : $v;
            }
            $ins->execute($vals);
        }
        // Sinkronkan sequence PostgreSQL agar insert berikutnya tidak bentrok
        if (isPgSQL()) {
            foreach ($cols as $c) {
                $seq = $pdo->prepare('SELECT pg_get_serial_sequence(?, ?)');
                $seq->execute([qualifiedTable($schema, $table), $c]);
                $seqName = $seq->fetchColumn();
                if ($seqName) $pdo->query("SELECT setval('" . str_replace("'", "''", $seqName) . "', COALESCE((SELECT MAX(" . qi($c) . ") FROM " . qualifiedTable($schema, $table) . "), 1))");
            }
        }
        if ($own) $pdo->commit();
    } catch (Exception $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw new Exception('Gagal mengurungkan: ' . $e->getMessage());
    }
    unset($_SESSION['undo_delete']);
    return count($rows);
}

// Deteksi SQL destruktif (DROP/TRUNCATE/ALTER..DROP, DELETE/UPDATE tanpa WHERE).
// Mengembalikan alasan (string) atau null jika aman.
function dangerousSqlReason(string $sql): ?string {
    $sql = preg_replace('/\/\*.*?\*\//s', ' ', $sql);
    $sql = preg_replace('/--[^\n]*/', ' ', $sql);
    $sql = preg_replace("/'(?:[^']|'')*'/", "''", $sql);
    $sql = preg_replace('/"(?:[^"]|"")*"/', '""', $sql);
    foreach (explode(';', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        if (preg_match('/^(DROP|TRUNCATE)\b/i', $stmt, $m)) return strtoupper($m[1]) . ' menghapus objek/data secara permanen';
        if (preg_match('/^ALTER\b.*\bDROP\b/is', $stmt)) return 'ALTER ... DROP menghapus kolom/constraint';
        if (preg_match('/^DELETE\b/i', $stmt) && !preg_match('/\bWHERE\b/i', $stmt)) return 'DELETE tanpa WHERE akan menghapus SEMUA baris';
        if (preg_match('/^UPDATE\b/i', $stmt) && !preg_match('/\bWHERE\b/i', $stmt)) return 'UPDATE tanpa WHERE akan mengubah SEMUA baris';
    }
    return null;
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

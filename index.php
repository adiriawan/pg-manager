<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'connect') {
        $driver = in_array($_POST['driver'] ?? '', ['pgsql','mysql','sqlite']) ? $_POST['driver'] : 'pgsql';
        if ($driver === 'sqlite') {
            $creds = [
                'driver'   => 'sqlite',
                'dbname'   => trim($_POST['sqlite_path'] ?? ''),
                'host'     => '',
                'port'     => '',
                'user'     => '',
                'password' => '',
            ];
        } else {
            $creds = [
                'driver'   => $driver,
                'host'     => trim($_POST['host'] ?? 'localhost'),
                'port'     => trim($_POST['port'] ?? ($driver === 'mysql' ? '3306' : '5432')),
                'dbname'   => trim($_POST['dbname'] ?? ($driver === 'mysql' ? 'mysql' : 'postgres')),
                'user'     => trim($_POST['user'] ?? ($driver === 'mysql' ? 'root' : 'postgres')),
                'password' => $_POST['password'] ?? '',
            ];
        }
        tryConnect($creds);
        header('Location: index.php');
        exit;
    }
    if ($_POST['action'] === 'disconnect') {
        session_destroy();
        header('Location: index.php');
        exit;
    }
    // Pindah database (dari modal Kelola Database)
    if ($_POST['action'] === 'disconnect_and_connect' && isConnected()) {
        $newDb = trim($_POST['dbname'] ?? '');
        if ($newDb && preg_match('/^[a-zA-Z][a-zA-Z0-9_\-]{0,62}$/', $newDb)) {
            $existing = getCredentials();
            $newCreds = array_merge($existing, ['dbname' => $newDb]);
            tryConnect($newCreds);
        }
        header('Location: app.php');
        exit;
    }
}

if (isConnected()) {
    header('Location: app.php');
    exit;
}

$error = $_SESSION['db_error'] ?? null;
unset($_SESSION['db_error']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PG Manager – Connect</title>
<script>
(function(){
    var t = localStorage.getItem('pgm_theme');
    if (t === 'light') document.documentElement.classList.add('light');
    document.documentElement.style.background = t === 'light' ? '#f0f2f5' : '#1c1c1c';
})();
</script>
<link rel="stylesheet" href="assets/style.css">
<script>document.documentElement.style.background = '';</script>
</head>
<body class="auth-page">
<div class="auth-container">
    <div class="auth-logo">
        <svg width="32" height="32" viewBox="0 0 32 32" fill="none"><circle cx="16" cy="16" r="16" fill="#3ECF8E"/><path d="M9 22V10l7 4 7-4v12l-7-4-7 4z" fill="#fff"/></svg>
        <span>PG Manager</span>
    </div>
    <div class="auth-card">
        <h1>Hubungkan Database</h1>
        <p class="auth-sub">Masukkan kredensial database Anda</p>
        <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST" action="index.php" id="connect-form">
            <input type="hidden" name="action" value="connect">

            <!-- Driver selector -->
            <div class="driver-selector">
                <label class="driver-opt" id="drv-pgsql">
                    <input type="radio" name="driver" value="pgsql" checked onchange="onDriverChange()">
                    <span class="driver-card">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><ellipse cx="12" cy="5" rx="9" ry="3" stroke="currentColor" stroke-width="1.5"/><path d="M3 5v5c0 1.657 4.03 3 9 3s9-1.343 9-3V5" stroke="currentColor" stroke-width="1.5"/><path d="M3 10v5c0 1.657 4.03 3 9 3s9-1.343 9-3v-5" stroke="currentColor" stroke-width="1.5"/><path d="M3 15v4c0 1.657 4.03 3 9 3s9-1.343 9-3v-4" stroke="currentColor" stroke-width="1.5"/></svg>
                        PostgreSQL
                    </span>
                </label>
                <label class="driver-opt" id="drv-mysql">
                    <input type="radio" name="driver" value="mysql" onchange="onDriverChange()">
                    <span class="driver-card">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M12 3C7.03 3 3 7.03 3 12s4.03 9 9 9 9-4.03 9-9-4.03-9-9-9z" stroke="currentColor" stroke-width="1.5"/><path d="M8 12l3 3 5-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        MySQL / MariaDB
                    </span>
                </label>
                <label class="driver-opt" id="drv-sqlite">
                    <input type="radio" name="driver" value="sqlite" onchange="onDriverChange()">
                    <span class="driver-card">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M3 9h18M9 9v12" stroke="currentColor" stroke-width="1.5"/></svg>
                        SQLite
                    </span>
                </label>
            </div>

            <!-- Form fields untuk PgSQL/MySQL -->
            <div id="fields-network">
                <div class="form-row">
                    <div class="form-group">
                        <label>Host</label>
                        <input type="text" name="host" id="inp-host" value="localhost" placeholder="localhost">
                    </div>
                    <div class="form-group form-group-sm">
                        <label>Port</label>
                        <input type="text" name="port" id="inp-port" value="5432" placeholder="5432">
                    </div>
                </div>
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="user" id="inp-user" value="adiriawan" placeholder="adiriawan">
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" id="inp-password" placeholder="••••••••">
                </div>
                <div class="form-group">
                    <label>Database</label>
                    <div class="db-select-wrap">
                        <select name="dbname" id="inp-dbname" disabled>
                            <option value="">— muat daftar database —</option>
                        </select>
                        <button type="button" class="btn btn-outline btn-load-db" id="btn-load-db" onclick="loadDatabases()">
                            <span id="load-btn-text">Muat</span>
                            <span id="load-spinner" class="spinner" style="display:none"></span>
                        </button>
                    </div>
                    <div id="db-load-error" class="field-error" style="display:none"></div>
                </div>
            </div>

            <!-- Form fields untuk SQLite -->
            <div id="fields-sqlite" style="display:none">
                <div class="form-group">
                    <label>Path File Database (.db)</label>
                    <input type="text" name="sqlite_path" id="inp-sqlite-path"
                           placeholder="/Users/nama/database.db"
                           style="font-family:var(--mono,monospace);font-size:13px">
                    <div class="field-hint">Path absolut ke file SQLite. File akan dibuat jika belum ada.</div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-full" id="btn-connect" disabled>Hubungkan</button>
        </form>

        <script>
        async function loadDatabases() {
            const host     = document.getElementById('inp-host').value.trim();
            const port     = document.getElementById('inp-port').value.trim();
            const user     = document.getElementById('inp-user').value.trim();
            const password = document.getElementById('inp-password').value;

            const btn     = document.getElementById('btn-load-db');
            const spinner = document.getElementById('load-spinner');
            const btnText = document.getElementById('load-btn-text');
            const errDiv  = document.getElementById('db-load-error');
            const select  = document.getElementById('inp-dbname');
            const connectBtn = document.getElementById('btn-connect');

            btn.disabled = true;
            spinner.style.display = 'inline-block';
            btnText.textContent = 'Memuat…';
            errDiv.style.display = 'none';
            select.disabled = true;
            connectBtn.disabled = true;

            const body = new URLSearchParams({ host, port, user, password });
            try {
                const res  = await fetch('api/list_databases.php', { method: 'POST', body });
                const data = await res.json();

                if (data.error) {
                    errDiv.textContent = data.error;
                    errDiv.style.display = 'block';
                } else {
                    select.innerHTML = '';
                    data.databases.forEach((db, i) => {
                        const opt = document.createElement('option');
                        opt.value = db;
                        opt.textContent = db;
                        if (i === 0) opt.selected = true;
                        select.appendChild(opt);
                    });
                    select.disabled = false;
                    connectBtn.disabled = false;
                }
            } catch (e) {
                errDiv.textContent = 'Gagal terhubung ke server.';
                errDiv.style.display = 'block';
            } finally {
                btn.disabled = false;
                spinner.style.display = 'none';
                btnText.textContent = 'Muat Ulang';
            }
        }

        // Auto-load saat password diisi dan tekan Enter
        document.getElementById('inp-password').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); loadDatabases(); }
        });

        function onDriverChange() {
            const driver = document.querySelector('input[name=driver]:checked').value;
            const isSQLite = driver === 'sqlite';

            document.getElementById('fields-network').style.display = isSQLite ? 'none' : '';
            document.getElementById('fields-sqlite').style.display  = isSQLite ? '' : 'none';

            if (isSQLite) {
                // SQLite: enable connect segera ketika path diisi
                const pathEl = document.getElementById('inp-sqlite-path');
                document.getElementById('btn-connect').disabled = pathEl.value.trim() === '';
                return;
            }

            const portEl = document.getElementById('inp-port');
            const userEl = document.getElementById('inp-user');
            // Update port default
            if (portEl.value === '5432' || portEl.value === '3306') {
                portEl.value = driver === 'mysql' ? '3306' : '5432';
            }
            // Update user default
            if (userEl.value === 'root' || userEl.value === 'postgres' || userEl.value === 'adiriawan') {
                userEl.value = driver === 'mysql' ? 'root' : 'adiriawan';
            }
            // Reset database list
            const sel = document.getElementById('inp-dbname');
            sel.innerHTML = '<option value="">— muat daftar database —</option>';
            sel.disabled = true;
            document.getElementById('btn-connect').disabled = true;
        }

        // Enable connect button saat path SQLite diisi
        document.getElementById('inp-sqlite-path').addEventListener('input', function() {
            const driver = document.querySelector('input[name=driver]:checked').value;
            if (driver === 'sqlite') {
                document.getElementById('btn-connect').disabled = this.value.trim() === '';
            }
        });
        </script>
    </div>
</div>
</body>
</html>

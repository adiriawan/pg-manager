<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'connect') {
        $creds = [
            'host'     => trim($_POST['host'] ?? 'localhost'),
            'port'     => trim($_POST['port'] ?? '5432'),
            'dbname'   => trim($_POST['dbname'] ?? 'postgres'),
            'user'     => trim($_POST['user'] ?? 'postgres'),
            'password' => $_POST['password'] ?? '',
        ];
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
        header('Location: index.php');
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
        <p class="auth-sub">Masukkan kredensial PostgreSQL Anda</p>
        <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST" action="index.php" id="connect-form">
            <input type="hidden" name="action" value="connect">
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
        </script>
    </div>
</div>
</body>
</html>

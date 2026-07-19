<?php
// Database overview page
$dbVersion = '';
$dbSize    = '';
$dbStats   = [];
$tableStats = [];

try {
    if (isSQLite()) {
        $dbVersion = 'SQLite ' . $pdo->query("SELECT sqlite_version()")->fetchColumn();
        $pageCount  = (int)$pdo->query("PRAGMA page_count")->fetchColumn();
        $pageSize   = (int)$pdo->query("PRAGMA page_size")->fetchColumn();
        $totalBytes = $pageCount * $pageSize;
        if ($totalBytes >= 1048576)      $dbSize = round($totalBytes / 1048576, 2) . ' MB';
        elseif ($totalBytes >= 1024)     $dbSize = round($totalBytes / 1024, 1) . ' KB';
        else                             $dbSize = $totalBytes . ' B';
        $dbStats['table_count'] = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchColumn();
        $dbStats['view_count']  = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='view'")->fetchColumn();
        $dbStats['connections'] = 1;
        $sqliteTables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($sqliteTables as $tblName) {
            $cnt = $pdo->query("SELECT COUNT(*) FROM " . qi($tblName))->fetchColumn();
            $tableStats[] = [
                'table_schema'    => 'main',
                'table_name'      => $tblName,
                'row_estimate'    => $cnt,
                'data_size'       => '—',
                'total_size'      => '—',
                'last_autovacuum' => null,
            ];
        }
    } elseif (isMySQL()) {
        $dbVersion = $pdo->query("SELECT version()")->fetchColumn();
        $sysFilter = "'" . implode("','", systemSchemas()) . "'";

        $stmt = $pdo->prepare("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2)
            FROM information_schema.tables WHERE table_schema = ?");
        $stmt->execute([$creds['dbname']]);
        $mb = $stmt->fetchColumn();
        $dbSize = ($mb !== null ? $mb : '0') . ' MB';

        $stmt = $pdo->prepare("SELECT table_schema, table_name,
            table_rows as row_estimate,
            ROUND(data_length / 1024, 1) as data_size,
            ROUND((data_length + index_length) / 1024, 1) as total_size,
            update_time as last_autovacuum
            FROM information_schema.tables
            WHERE table_schema NOT IN ($sysFilter) AND table_type = 'BASE TABLE'
            ORDER BY (data_length + index_length) DESC LIMIT 50");
        $stmt->execute();
        $tableStats = array_map(function($r) {
            $r['data_size']  = $r['data_size']  !== null ? $r['data_size']  . ' KB' : '—';
            $r['total_size'] = $r['total_size']  !== null ? $r['total_size'] . ' KB' : '—';
            return $r;
        }, $stmt->fetchAll());

        $dbStats['connections']       = $pdo->query("SELECT count(*) FROM information_schema.processlist WHERE command != 'Sleep'")->fetchColumn();
        $dbStats['total_connections'] = $pdo->query("SELECT count(*) FROM information_schema.processlist")->fetchColumn();
        $dbStats['table_count']       = $pdo->query("SELECT count(*) FROM information_schema.tables WHERE table_schema NOT IN ($sysFilter) AND table_type='BASE TABLE'")->fetchColumn();
        $dbStats['view_count']        = $pdo->query("SELECT count(*) FROM information_schema.views WHERE table_schema NOT IN ($sysFilter)")->fetchColumn();
    } else {
        // PostgreSQL
        $dbVersion = $pdo->query("SELECT version()")->fetchColumn();
        $sysFilter = "'" . implode("','", systemSchemas()) . "'";

        $stmt = $pdo->prepare("SELECT pg_size_pretty(pg_database_size(?))");
        $stmt->execute([$creds['dbname']]);
        $dbSize = $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT t.table_schema, t.table_name,
                pg_size_pretty(pg_total_relation_size('\"'||t.table_schema||'\".\"'||t.table_name||'\"')) as total_size,
                pg_size_pretty(pg_relation_size('\"'||t.table_schema||'\".\"'||t.table_name||'\"')) as data_size,
                s.n_live_tup as row_estimate, s.last_autovacuum, s.last_autoanalyze
            FROM information_schema.tables t
            LEFT JOIN pg_stat_user_tables s ON s.schemaname=t.table_schema AND s.relname=t.table_name
            WHERE t.table_schema NOT IN ($sysFilter) AND t.table_type='BASE TABLE'
            ORDER BY pg_total_relation_size('\"'||t.table_schema||'\".\"'||t.table_name||'\"') DESC NULLS LAST
            LIMIT 50");
        $stmt->execute();
        $tableStats = $stmt->fetchAll();

        $dbStats['connections']       = $pdo->query("SELECT count(*) FROM pg_stat_activity WHERE state='active'")->fetchColumn();
        $dbStats['total_connections'] = $pdo->query("SELECT count(*) FROM pg_stat_activity")->fetchColumn();
        $dbStats['table_count']       = $pdo->query("SELECT count(*) FROM information_schema.tables WHERE table_schema NOT IN ($sysFilter) AND table_type='BASE TABLE'")->fetchColumn();
        $dbStats['view_count']        = $pdo->query("SELECT count(*) FROM information_schema.views WHERE table_schema NOT IN ($sysFilter)")->fetchColumn();
    }
} catch (Exception $e) {}

// Handle create table
$createError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_table'])) {
    try {
        $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['new_table_name'] ?? '');
        $newSchema = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['new_table_schema'] ?? 'public');
        $colDefs = [];
        $colNames = $_POST['new_col_name'] ?? [];
        $colTypes = $_POST['new_col_type'] ?? [];
        $colNulls = $_POST['new_col_nullable'] ?? [];
        $colPks   = $_POST['new_col_pk'] ?? [];
        foreach ($colNames as $i => $colName) {
            if (!$colName) continue;
            $colName = preg_replace('/[^a-zA-Z0-9_]/', '', $colName);
            $colType = $colTypes[$i] ?? 'text';
            $nullable = isset($colNulls[$i]) ? '' : 'NOT NULL';
            $pk = isset($colPks[$i]) ? 'PRIMARY KEY' : '';
            // Konversi tipe serial per driver
            if (isMySQL() && in_array($colType, ['bigserial','serial'])) {
                $colType = $colType === 'bigserial' ? 'BIGINT AUTO_INCREMENT' : 'INT AUTO_INCREMENT';
            }
            if (isSQLite() && in_array($colType, ['bigserial','serial'])) {
                $colType = 'INTEGER';
                $pk = 'PRIMARY KEY AUTOINCREMENT';
                $nullable = ''; // INTEGER PRIMARY KEY cannot have NOT NULL separately
            }
            $colDefs[] = qi($colName) . " $colType $nullable $pk";
        }
        if (isSQLite()) $newSchema = 'main';
        if ($tableName && $colDefs) {
            $sql = "CREATE TABLE " . qualifiedTable($newSchema, $tableName) . " (" . implode(', ', $colDefs) . ")";
            $pdo->exec($sql);
            $schemaLabel = isSQLite() ? $tableName : "$newSchema.$tableName";
            $_SESSION['flash_message'] = "Tabel $schemaLabel berhasil dibuat.";
            header('Location: app.php?page=table-editor&schema=' . urlencode($newSchema) . '&table=' . urlencode($tableName));
            exit;
        }
    } catch (Exception $e) {
        $createError = $e->getMessage();
    }
}
?>
<div class="page-header">
    <h1>Database Overview</h1>
    <p class="page-sub">
        <?= htmlspecialchars(isSQLite() ? basename($creds['dbname']) : $creds['dbname']) ?>
        <?php if (!isSQLite()): ?> @ <?= htmlspecialchars($creds['host']) ?><?php endif; ?>
    </p>
    <div class="header-actions">
        <?php if (!isSQLite()): ?>
        <button class="btn btn-outline" onclick="openModal('modal-manage-db')">🗄️ Kelola Database</button>
        <?php endif; ?>
        <button class="btn btn-primary" onclick="openModal('modal-create-table')">+ Buat Tabel</button>
    </div>
</div>

<!-- Stats Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">🗄️</div>
        <div class="stat-value"><?= htmlspecialchars($dbSize ?: '—') ?></div>
        <div class="stat-label">Ukuran Database</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">📋</div>
        <div class="stat-value"><?= $dbStats['table_count'] ?? '—' ?></div>
        <div class="stat-label">Jumlah Tabel</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">👁️</div>
        <div class="stat-value"><?= $dbStats['view_count'] ?? '—' ?></div>
        <div class="stat-label">Jumlah View</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">🔗</div>
        <div class="stat-value"><?= $dbStats['active_connections'] ?? $dbStats['connections'] ?? '—' ?></div>
        <div class="stat-label">Koneksi Aktif</div>
    </div>
</div>

<!-- Version Info -->
<div class="info-block">
    <strong>Versi <?= isSQLite() ? 'SQLite' : (isMySQL() ? 'MySQL' : 'PostgreSQL') ?>:</strong>
    <code><?= htmlspecialchars(preg_replace('/\(.*\)/', '', $dbVersion)) ?></code>
</div>
<?php if (isSQLite()): ?>
<div class="info-block">
    <strong>File:</strong>
    <code><?= htmlspecialchars($creds['dbname']) ?></code>
</div>
<?php endif; ?>

<!-- Table Stats -->
<h2 class="section-title">Tabel & Ukuran</h2>
<div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>Schema</th>
                <th>Tabel</th>
                <th>Estimasi Baris</th>
                <th>Ukuran Data</th>
                <th>Total Ukuran</th>
                <th>Last Autovacuum</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tableStats as $t): ?>
            <tr>
                <td><code><?= htmlspecialchars($t['table_schema']) ?></code></td>
                <td><strong><?= htmlspecialchars($t['table_name']) ?></strong></td>
                <td><?= $t['row_estimate'] !== null ? number_format($t['row_estimate']) : '—' ?></td>
                <td><?= htmlspecialchars($t['data_size'] ?: '—') ?></td>
                <td><?= htmlspecialchars($t['total_size'] ?: '—') ?></td>
                <td><?= $t['last_autovacuum'] ? date('d/m/Y H:i', strtotime($t['last_autovacuum'])) : '—' ?></td>
                <td>
                    <a href="app.php?page=table-editor&schema=<?= urlencode($t['table_schema']) ?>&table=<?= urlencode($t['table_name']) ?>" class="btn btn-outline btn-sm">Lihat</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Modal Create Table -->
<div id="modal-create-table" class="modal-overlay" style="display:none">
    <div class="modal modal-wide">
        <div class="modal-header">
            <h3>Buat Tabel Baru</h3>
            <button class="modal-close" onclick="closeModal('modal-create-table')">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="create_table" value="1">
            <?php if ($createError): ?>
            <div class="alert alert-error" style="margin:0 24px"><?= htmlspecialchars($createError) ?></div>
            <?php endif; ?>
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Nama Tabel</label>
                        <input type="text" name="new_table_name" placeholder="nama_tabel" pattern="[a-zA-Z0-9_]+" required>
                    </div>
                    <?php if (!isSQLite()): ?>
                    <div class="form-group form-group-sm">
                        <label>Schema</label>
                        <select name="new_table_schema">
                            <?php foreach ($schemas as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>" <?= $s === $schema ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="new_table_schema" value="main">
                    <?php endif; ?>
                </div>
                <h4 style="margin:16px 0 8px">Kolom</h4>
                <div id="col-list">
                    <div class="col-row">
                        <input type="text" name="new_col_name[]" placeholder="id" value="id" style="flex:2">
                        <select name="new_col_type[]" style="flex:2">
                            <option value="bigserial" selected>bigserial</option>
                            <option value="serial">serial</option>
                            <option value="integer">integer</option>
                            <option value="bigint">bigint</option>
                            <option value="text">text</option>
                            <option value="varchar(255)">varchar(255)</option>
                            <option value="boolean">boolean</option>
                            <option value="timestamp">timestamp</option>
                            <option value="date">date</option>
                            <option value="numeric">numeric</option>
                            <option value="jsonb">jsonb</option>
                            <option value="uuid">uuid</option>
                        </select>
                        <label class="check-label"><input type="checkbox" name="new_col_pk[]" value="1" checked> PK</label>
                        <label class="check-label"><input type="checkbox" name="new_col_nullable[]" value="1"> Nullable</label>
                        <button type="button" class="btn-icon btn-delete" onclick="removeCol(this)">✕</button>
                    </div>
                </div>
                <button type="button" class="btn btn-outline btn-sm" onclick="addCol()" style="margin-top:8px">+ Tambah Kolom</button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-create-table')">Batal</button>
                <button type="submit" class="btn btn-primary">Buat Tabel</button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     Modal: Kelola Database (Buat / Drop)
══════════════════════════════════════════════════════════ -->
<div id="modal-manage-db" class="modal-overlay" style="display:none">
    <div class="modal modal-wide">
        <div class="modal-header">
            <h3>🗄️ Kelola Database</h3>
            <button class="modal-close" onclick="closeModal('modal-manage-db')">×</button>
        </div>
        <div class="modal-body" style="padding-bottom:0">

            <!-- Tab -->
            <div class="mdb-tabs">
                <button class="mdb-tab active" onclick="mdbTab('list')">Daftar Database</button>
                <button class="mdb-tab" onclick="mdbTab('create')">+ Buat Baru</button>
                <button class="mdb-tab" onclick="mdbTab('drop')">🗑 Hapus</button>
            </div>

            <!-- Panel: List -->
            <div id="mdb-panel-list" class="mdb-panel">
                <div id="mdb-list-loading" style="padding:24px;text-align:center;color:var(--text-muted)">Memuat…</div>
                <table class="data-table" id="mdb-db-table" style="display:none">
                    <thead><tr>
                        <th>Nama Database</th>
                        <th>Owner</th>
                        <th>Ukuran</th>
                        <th>Aksi</th>
                    </tr></thead>
                    <tbody id="mdb-db-tbody"></tbody>
                </table>
            </div>

            <!-- Panel: Create -->
            <div id="mdb-panel-create" class="mdb-panel" style="display:none">
                <div id="mdb-create-msg"></div>
                <div class="form-group">
                    <label>Nama Database <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="mdb-new-name" placeholder="nama_database_baru"
                           pattern="[a-zA-Z][a-zA-Z0-9_\-]*" autocomplete="off">
                    <div class="field-hint">Hanya huruf, angka, _ atau - . Harus diawali huruf.</div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Owner</label>
                        <input type="text" id="mdb-new-owner" value="<?= htmlspecialchars($creds['user']) ?>" placeholder="postgres">
                    </div>
                    <div class="form-group">
                        <label>Encoding</label>
                        <select id="mdb-new-encoding">
                            <option value="UTF8" selected>UTF8 (Direkomendasikan)</option>
                            <option value="SQL_ASCII">SQL_ASCII</option>
                            <option value="LATIN1">LATIN1</option>
                            <option value="WIN1252">WIN1252</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Template</label>
                        <select id="mdb-new-template">
                            <option value="template0" selected>template0 (Bersih)</option>
                            <option value="template1">template1</option>
                        </select>
                    </div>
                </div>
                <button class="btn btn-primary" onclick="mdbCreate()">Buat Database</button>
            </div>

            <!-- Panel: Drop -->
            <div id="mdb-panel-drop" class="mdb-panel" style="display:none">
                <div class="alert alert-error" style="margin:0 0 16px">
                    ⚠️ Menghapus database bersifat <strong>permanen</strong> dan tidak bisa dibatalkan. Semua data akan hilang.
                </div>
                <div id="mdb-drop-msg"></div>
                <div class="form-group">
                    <label>Pilih Database yang Akan Dihapus</label>
                    <select id="mdb-drop-select">
                        <option value="">— pilih database —</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Ketik nama database untuk konfirmasi</label>
                    <input type="text" id="mdb-drop-confirm" placeholder="ketik nama database…" autocomplete="off">
                </div>
                <button class="btn btn-danger" id="mdb-drop-btn" disabled onclick="mdbDrop()">Hapus Database</button>
            </div>

        </div><!-- /modal-body -->
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('modal-manage-db')">Tutup</button>
        </div>
    </div>
</div>

<style>
.mdb-tabs { display:flex; gap:4px; border-bottom:1px solid var(--border); margin-bottom:16px; }
.mdb-tab  { background:none; border:none; border-bottom:2px solid transparent; padding:8px 14px;
             font-size:13px; font-weight:500; color:var(--text-muted); cursor:pointer; transition:all .15s; }
.mdb-tab:hover  { color:var(--text); }
.mdb-tab.active { color:var(--accent); border-bottom-color:var(--accent); }
.mdb-panel { padding-bottom:8px; }
.mdb-current-badge { display:inline-block; background:var(--accent); color:#000; font-size:10px;
                      font-weight:700; padding:1px 6px; border-radius:3px; margin-left:6px; }
</style>

<script>
const MDB_CSRF = <?= json_encode(csrfToken()) ?>;
const MDB_CURRENT_DB = <?= json_encode($creds['dbname']) ?>;

function mdbTab(name) {
    document.querySelectorAll('.mdb-tab').forEach((t,i) => {
        const panels = ['list','create','drop'];
        t.classList.toggle('active', panels[i] === name);
    });
    ['list','create','drop'].forEach(p => {
        document.getElementById('mdb-panel-' + p).style.display = p === name ? '' : 'none';
    });
}

// Load daftar database saat modal dibuka
document.getElementById('modal-manage-db').addEventListener('click', function(e) {
    if (e.target === this) return; // klik overlay
});

function mdbLoadList() {
    const loading = document.getElementById('mdb-list-loading');
    const table   = document.getElementById('mdb-db-table');
    const tbody   = document.getElementById('mdb-db-tbody');
    loading.style.display = '';
    table.style.display   = 'none';

    fetch('api/manage_database.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({ csrf_token: MDB_CSRF, action: 'list' })
    })
    .then(r => r.json())
    .then(data => {
        loading.style.display = 'none';
        if (data.error) { loading.textContent = '❌ ' + data.error; loading.style.display=''; return; }
        const dropSel = document.getElementById('mdb-drop-select');
        dropSel.innerHTML = '<option value="">— pilih database —</option>';
        tbody.innerHTML = '';
        data.databases.forEach(db => {
            const isCurrent = db.datname === MDB_CURRENT_DB;
            const isSys     = ['postgres','template0','template1'].includes(db.datname);
            tbody.innerHTML += `<tr>
                <td><strong>${db.datname}</strong>${isCurrent ? '<span class="mdb-current-badge">Aktif</span>':''}</td>
                <td>${db.owner||'—'}</td>
                <td>${db.size||'—'}</td>
                <td>
                    <a href="index.php?switch=${encodeURIComponent(db.datname)}" class="btn btn-outline btn-sm"
                       onclick="return switchDb('${db.datname}')">Hubungkan</a>
                </td>
            </tr>`;
            if (!isCurrent && !isSys) {
                dropSel.innerHTML += `<option value="${db.datname}">${db.datname}</option>`;
            }
        });
        table.style.display = '';
    })
    .catch(() => { loading.textContent = '❌ Gagal memuat daftar database.'; });
}

// Intercept openModal untuk load list
const _origOpen = window.openModal;
window.openModal = function(id) {
    _origOpen(id);
    if (id === 'modal-manage-db') mdbLoadList();
};

function switchDb(dbname) {
    // Arahkan user ke form login dengan database baru
    if (!confirm(`Hubungkan ke database "${dbname}"? Anda akan keluar dari sesi saat ini.`)) return false;
    // POST ke index.php dengan dbname baru (gunakan credentials yang sama)
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'index.php';
    form.innerHTML = `
        <input name="action"   value="disconnect_and_connect">
        <input name="dbname"   value="${dbname}">
    `;
    document.body.appendChild(form);
    form.submit();
    return false;
}

function mdbCreate() {
    const name     = document.getElementById('mdb-new-name').value.trim();
    const owner    = document.getElementById('mdb-new-owner').value.trim();
    const encoding = document.getElementById('mdb-new-encoding').value;
    const template = document.getElementById('mdb-new-template').value;
    const msgEl    = document.getElementById('mdb-create-msg');

    if (!name) { msgEl.innerHTML = '<div class="alert alert-error">Nama database wajib diisi.</div>'; return; }
    if (!/^[a-zA-Z][a-zA-Z0-9_\-]{0,62}$/.test(name)) {
        msgEl.innerHTML = '<div class="alert alert-error">Nama tidak valid. Gunakan huruf, angka, _ atau - dan awali dengan huruf.</div>'; return;
    }

    msgEl.innerHTML = '<div style="color:var(--text-muted);padding:8px 0">⏳ Membuat database…</div>';

    fetch('api/manage_database.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({ csrf_token: MDB_CSRF, action: 'create', dbname: name, owner, encoding, template })
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) {
            msgEl.innerHTML = `<div class="alert alert-error">${data.error}</div>`;
        } else {
            msgEl.innerHTML = `<div class="alert alert-success">✅ ${data.message}</div>`;
            document.getElementById('mdb-new-name').value = '';
            mdbLoadList(); // Refresh daftar
            // Tawari untuk langsung konek
            setTimeout(() => {
                if (confirm(`Database "${name}" berhasil dibuat. Hubungkan sekarang?`)) switchDb(name);
            }, 400);
        }
    })
    .catch(() => { msgEl.innerHTML = '<div class="alert alert-error">Gagal menghubungi server.</div>'; });
}

function mdbDrop() {
    const select  = document.getElementById('mdb-drop-select');
    const confirm_input = document.getElementById('mdb-drop-confirm');
    const msgEl   = document.getElementById('mdb-drop-msg');
    const dbname  = select.value;

    if (!dbname) { msgEl.innerHTML = '<div class="alert alert-error">Pilih database terlebih dahulu.</div>'; return; }
    if (confirm_input.value !== dbname) {
        msgEl.innerHTML = `<div class="alert alert-error">Nama konfirmasi tidak cocok. Ketik "<strong>${dbname}</strong>" dengan benar.</div>`; return;
    }
    if (!confirm(`YAKIN menghapus database "${dbname}"? Semua data akan hilang permanen!`)) return;

    msgEl.innerHTML = '<div style="color:var(--text-muted);padding:8px 0">⏳ Menghapus database…</div>';

    fetch('api/manage_database.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({ csrf_token: MDB_CSRF, action: 'drop', dbname })
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) {
            msgEl.innerHTML = `<div class="alert alert-error">${data.error}</div>`;
        } else {
            msgEl.innerHTML = `<div class="alert alert-success">✅ ${data.message}</div>`;
            confirm_input.value = '';
            select.value = '';
            mdbLoadList();
        }
    })
    .catch(() => { msgEl.innerHTML = '<div class="alert alert-error">Gagal menghubungi server.</div>'; });
}

// Enable/disable tombol drop berdasarkan konfirmasi
document.getElementById('mdb-drop-confirm').addEventListener('input', function() {
    const sel = document.getElementById('mdb-drop-select').value;
    document.getElementById('mdb-drop-btn').disabled = !sel || this.value !== sel;
});
document.getElementById('mdb-drop-select').addEventListener('change', function() {
    document.getElementById('mdb-drop-confirm').value = '';
    document.getElementById('mdb-drop-btn').disabled = true;
    document.getElementById('mdb-drop-msg').innerHTML = '';
});
</script>

<script>
function addCol() {
    const list = document.getElementById('col-list');
    const row = document.createElement('div');
    row.className = 'col-row';
    row.innerHTML = `
        <input type="text" name="new_col_name[]" placeholder="nama_kolom" style="flex:2">
        <select name="new_col_type[]" style="flex:2">
            <option value="text" selected>text</option>
            <option value="bigserial">bigserial</option>
            <option value="serial">serial</option>
            <option value="integer">integer</option>
            <option value="bigint">bigint</option>
            <option value="varchar(255)">varchar(255)</option>
            <option value="boolean">boolean</option>
            <option value="timestamp">timestamp</option>
            <option value="date">date</option>
            <option value="numeric">numeric</option>
            <option value="jsonb">jsonb</option>
            <option value="uuid">uuid</option>
        </select>
        <label class="check-label"><input type="checkbox" name="new_col_pk[]" value="1"> PK</label>
        <label class="check-label"><input type="checkbox" name="new_col_nullable[]" value="1" checked> Nullable</label>
        <button type="button" class="btn-icon btn-delete" onclick="removeCol(this)">✕</button>`;
    list.appendChild(row);
}
function removeCol(btn) {
    const rows = document.querySelectorAll('.col-row');
    if (rows.length > 1) btn.closest('.col-row').remove();
}
</script>

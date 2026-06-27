<?php
if (!$table) {
    echo '<div class="empty-state"><h3>Pilih tabel terlebih dahulu</h3></div>';
    return;
}

$flash    = null;
$flashType = 'success';

// ── Eksekusi ALTER ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf(); // BUG-04 FIX
    $op = $_POST['alter_op'] ?? '';
    // BUG-03 FIX: Ambil daftar kolom valid lebih awal untuk validasi
    $validColsStmt = $pdo->prepare("SELECT column_name FROM information_schema.columns
        WHERE table_schema=? AND table_name=? ORDER BY ordinal_position");
    $validColsStmt->execute([$schema, $table]);
    $validColNames = $validColsStmt->fetchAll(PDO::FETCH_COLUMN);

    // BUG-03 FIX: Whitelist tipe data yang diizinkan
    $allowedTypes = ['text','varchar(255)','integer','bigint','bigserial','serial','boolean',
                     'numeric','float8','date','timestamp','timestamptz','jsonb','uuid'];

    try {
        // Rename kolom
        if ($op === 'rename_col') {
            $oldName = $_POST['old_col'] ?? '';
            $newName = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['new_col'] ?? '');
            // BUG-03 FIX: validasi oldName harus kolom yang benar-benar ada
            if ($oldName && $newName && in_array($oldName, $validColNames)) {
                $pdo->exec("ALTER TABLE \"$schema\".\"$table\" RENAME COLUMN \"$oldName\" TO \"$newName\"");
                // BUG-11 FIX: escape nilai user di flash message
                $flash = "Kolom <strong>" . htmlspecialchars($oldName) . "</strong> berhasil diubah menjadi <strong>" . htmlspecialchars($newName) . "</strong>.";
            }
        }
        // Ubah tipe data
        if ($op === 'change_type') {
            $col  = $_POST['col_name'] ?? '';
            $type = $_POST['new_type'] ?? '';
            // BUG-03 FIX: validasi col dan whitelist type
            if ($col && $type && in_array($col, $validColNames) && in_array($type, $allowedTypes)) {
                $pdo->exec("ALTER TABLE \"$schema\".\"$table\" ALTER COLUMN \"$col\" TYPE $type USING \"$col\"::$type");
                $flash = "Tipe kolom <strong>" . htmlspecialchars($col) . "</strong> berhasil diubah ke <strong>" . htmlspecialchars($type) . "</strong>.";
            }
        }
        // Set NOT NULL / NULL
        if ($op === 'set_nullable') {
            $col      = $_POST['col_name']  ?? '';
            $nullable = $_POST['nullable']  ?? 'YES';
            if ($col && in_array($col, $validColNames)) {
                $kw = $nullable === 'YES' ? 'DROP NOT NULL' : 'SET NOT NULL';
                $pdo->exec("ALTER TABLE \"$schema\".\"$table\" ALTER COLUMN \"$col\" $kw");
                $flash = "Kolom <strong>" . htmlspecialchars($col) . "</strong> berhasil diperbarui.";
            }
        }
        // Set DEFAULT
        if ($op === 'set_default') {
            $col = $_POST['col_name'] ?? '';
            $def = $_POST['default_val'] ?? '';
            if ($col && in_array($col, $validColNames)) {
                if ($def === '') {
                    $pdo->exec("ALTER TABLE \"$schema\".\"$table\" ALTER COLUMN \"$col\" DROP DEFAULT");
                } else {
                    $pdo->exec("ALTER TABLE \"$schema\".\"$table\" ALTER COLUMN \"$col\" SET DEFAULT " . $pdo->quote($def));
                }
                $flash = "Default kolom <strong>" . htmlspecialchars($col) . "</strong> berhasil diperbarui.";
            }
        }
        // Tambah kolom baru
        if ($op === 'add_col') {
            $colName  = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['add_col_name'] ?? '');
            $colType  = $_POST['add_col_type'] ?? 'text';
            // BUG-03 FIX: whitelist tipe data
            if (!in_array($colType, $allowedTypes)) $colType = 'text';
            $nullable  = isset($_POST['add_nullable']) ? '' : 'NOT NULL';
            $default   = trim($_POST['add_default']  ?? '');
            $defClause = $default ? "DEFAULT " . $pdo->quote($default) : '';
            if ($colName) {
                $pdo->exec("ALTER TABLE \"$schema\".\"$table\" ADD COLUMN \"$colName\" $colType $nullable $defClause");
                $flash = "Kolom <strong>" . htmlspecialchars($colName) . "</strong> berhasil ditambahkan.";
            }
        }
        // Hapus kolom
        if ($op === 'drop_col') {
            $col = $_POST['drop_col_name'] ?? '';
            if ($col && in_array($col, $validColNames)) {
                $pdo->exec("ALTER TABLE \"$schema\".\"$table\" DROP COLUMN \"$col\" CASCADE");
                $flash = "Kolom <strong>" . htmlspecialchars($col) . "</strong> berhasil dihapus.";
            }
        }
        // Rename tabel
        if ($op === 'rename_table') {
            $newName = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['new_table_name'] ?? '');
            if ($newName) {
                $pdo->exec("ALTER TABLE \"$schema\".\"$table\" RENAME TO \"$newName\"");
                $flash = "Tabel berhasil diubah menjadi <strong>" . htmlspecialchars($newName) . "</strong>.";
                header("Location: app.php?page=alter-table&schema=" . urlencode($schema) . "&table=" . urlencode($newName) . "&flash=" . urlencode("Tabel berhasil direname ke $newName"));
                exit;
            }
        }

    } catch (Exception $e) {
        $flash     = $e->getMessage();
        $flashType = 'error';
    }
    if ($flash && $flashType === 'success') {
        $_SESSION['alter_flash'] = $flash;
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

if (isset($_SESSION['alter_flash'])) {
    $flash = $_SESSION['alter_flash'];
    unset($_SESSION['alter_flash']);
}
if (isset($_GET['flash'])) $flash = htmlspecialchars($_GET['flash']);

// ── Ambil struktur kolom ──────────────────────────────────────
$columns = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.column_name, c.data_type, c.is_nullable, c.column_default,
               CASE WHEN pk.column_name IS NOT NULL THEN 'YES' ELSE 'NO' END as is_primary
        FROM information_schema.columns c
        LEFT JOIN (
            SELECT ku.column_name FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage ku
                ON tc.constraint_name = ku.constraint_name AND tc.table_schema = ku.table_schema
            WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = ? AND tc.table_name = ?
        ) pk ON c.column_name = pk.column_name
        WHERE c.table_schema = ? AND c.table_name = ?
        ORDER BY c.ordinal_position
    ");
    $stmt->execute([$schema, $table, $schema, $table]);
    $columns = $stmt->fetchAll();
} catch (Exception $e) {}

$typeOptions = ['text','varchar(255)','integer','bigint','bigserial','serial','boolean',
                'numeric','float8','date','timestamp','timestamptz','jsonb','uuid'];
?>

<div class="page-header">
    <div>
        <h1>ALTER TABLE — <strong><?= htmlspecialchars($schema) ?>.<span style="color:var(--accent)"><?= htmlspecialchars($table) ?></span></strong></h1>
        <p class="page-sub"><?= count($columns) ?> kolom</p>
    </div>
    <div class="header-actions">
        <a href="app.php?page=table-editor&schema=<?= urlencode($schema) ?>&table=<?= urlencode($table) ?>" class="btn btn-outline">← Kembali ke Data</a>
    </div>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType ?>"><?= $flash ?></div>
<?php endif; ?>

<div class="alter-layout">

    <!-- ── Kolom yang ada ──────────────────────────────────── -->
    <div class="alter-card">
        <div class="alter-card-header">📋 Kolom yang Ada</div>
        <div class="alter-card-body">
            <?php foreach ($columns as $col): ?>
            <div class="alter-col-row">
                <div>
                    <span class="alter-col-name"><?= htmlspecialchars($col['column_name']) ?></span>
                    <?php if ($col['is_primary'] === 'YES'): ?><span class="badge-pk">PK</span><?php endif; ?>
                </div>
                <div class="alter-col-type"><?= htmlspecialchars($col['data_type']) ?></div>
                <div style="font-size:11px;color:var(--text-muted)">
                    <?= $col['is_nullable'] === 'YES' ? 'nullable' : 'not null' ?>
                    <?= $col['column_default'] ? ' · default: ' . htmlspecialchars($col['column_default']) : '' ?>
                </div>
                <div style="display:flex;gap:4px;margin-left:auto">
                    <button class="btn btn-outline btn-sm"
                        onclick="openRenameCol('<?= addslashes($col['column_name']) ?>')">Rename</button>
                    <button class="btn btn-outline btn-sm"
                        onclick="openChangeType('<?= addslashes($col['column_name']) ?>','<?= addslashes($col['data_type']) ?>')">Tipe</button>
                    <button class="btn btn-outline btn-sm"
                        onclick="openSetDefault('<?= addslashes($col['column_name']) ?>','<?= addslashes($col['column_default'] ?? '') ?>')">Default</button>
                    <button class="btn btn-outline btn-sm"
                        onclick="openNullable('<?= addslashes($col['column_name']) ?>','<?= $col['is_nullable'] ?>')">Nullable</button>
                    <?php if ($col['is_primary'] !== 'YES'): ?>
                    <button class="btn btn-outline btn-sm btn-danger"
                        onclick="openDropCol('<?= addslashes($col['column_name']) ?>')">Drop</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── Aksi Cepat ──────────────────────────────────────── -->
    <div style="display:flex;flex-direction:column;gap:12px">

        <!-- Tambah Kolom -->
        <div class="alter-card">
            <div class="alter-card-header">➕ Tambah Kolom Baru</div>
            <div class="alter-card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="alter_op" value="add_col">
                    <div class="form-group">
                        <label>Nama Kolom</label>
                        <input type="text" name="add_col_name" placeholder="nama_kolom" pattern="[a-zA-Z0-9_]+" required>
                    </div>
                    <div class="form-group">
                        <label>Tipe Data</label>
                        <select name="add_col_type">
                            <?php foreach ($typeOptions as $t): ?>
                            <option value="<?= $t ?>"><?= $t ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Default Value (opsional)</label>
                        <input type="text" name="add_default" placeholder="kosong = tanpa default">
                    </div>
                    <div class="form-group">
                        <label class="check-label">
                            <input type="checkbox" name="add_nullable" value="1" checked> Nullable
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Tambah Kolom</button>
                </form>
            </div>
        </div>

        <!-- Rename Tabel -->
        <div class="alter-card">
            <div class="alter-card-header">✏️ Rename Tabel</div>
            <div class="alter-card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="alter_op" value="rename_table">
                    <div class="form-group">
                        <label>Nama Baru</label>
                        <input type="text" name="new_table_name" placeholder="nama_tabel_baru"
                               pattern="[a-zA-Z0-9_]+" value="<?= htmlspecialchars($table) ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm"
                        onclick="return confirm('Yakin rename tabel ini?')">Rename Tabel</button>
                </form>
            </div>
        </div>

    </div><!-- /aksi cepat -->
</div><!-- /alter-layout -->

<!-- Modal Rename Kolom -->
<div id="modal-rename-col" class="modal-overlay" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <h3>Rename Kolom</h3>
            <button class="modal-close" onclick="closeModal('modal-rename-col')">×</button>
        </div>
        <form method="POST">
                    <?= csrfField() ?>
            <input type="hidden" name="alter_op" value="rename_col">
            <div class="modal-body">
                <div class="form-group">
                    <label>Nama Lama</label>
                    <input type="text" id="rename-old" name="old_col" readonly style="opacity:.6">
                </div>
                <div class="form-group">
                    <label>Nama Baru</label>
                    <input type="text" id="rename-new" name="new_col" placeholder="nama_kolom_baru" pattern="[a-zA-Z0-9_]+" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-rename-col')">Batal</button>
                <button type="submit" class="btn btn-primary">Rename</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Ubah Tipe -->
<div id="modal-change-type" class="modal-overlay" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <h3>Ubah Tipe Data Kolom</h3>
            <button class="modal-close" onclick="closeModal('modal-change-type')">×</button>
        </div>
        <form method="POST">
                    <?= csrfField() ?>
            <input type="hidden" name="alter_op" value="change_type">
            <input type="hidden" id="ctype-col" name="col_name">
            <div class="modal-body">
                <div class="form-group">
                    <label>Kolom</label>
                    <input type="text" id="ctype-col-label" readonly style="opacity:.6">
                </div>
                <div class="form-group">
                    <label>Tipe Baru</label>
                    <select name="new_type">
                        <?php foreach ($typeOptions as $t): ?><option><?= $t ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="alert alert-error" style="margin:0">
                    ⚠️ Perubahan tipe data menggunakan CAST otomatis. Pastikan data kompatibel.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-change-type')">Batal</button>
                <button type="submit" class="btn btn-primary">Ubah Tipe</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Set Default -->
<div id="modal-set-default" class="modal-overlay" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <h3>Set Default Value</h3>
            <button class="modal-close" onclick="closeModal('modal-set-default')">×</button>
        </div>
        <form method="POST">
                    <?= csrfField() ?>
            <input type="hidden" name="alter_op" value="set_default">
            <input type="hidden" id="sdef-col" name="col_name">
            <div class="modal-body">
                <div class="form-group">
                    <label>Kolom: <strong id="sdef-col-label"></strong></label>
                </div>
                <div class="form-group">
                    <label>Default Value (kosongkan untuk hapus default)</label>
                    <input type="text" id="sdef-val" name="default_val" placeholder="contoh: now(), 0, 'aktif'">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-set-default')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Set Nullable -->
<div id="modal-nullable" class="modal-overlay" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <h3>Ubah Nullable</h3>
            <button class="modal-close" onclick="closeModal('modal-nullable')">×</button>
        </div>
        <form method="POST">
                    <?= csrfField() ?>
            <input type="hidden" name="alter_op" value="set_nullable">
            <input type="hidden" id="null-col" name="col_name">
            <div class="modal-body">
                <div class="form-group">
                    <label>Kolom: <strong id="null-col-label"></strong></label>
                </div>
                <div class="form-group">
                    <label>Status Nullable</label>
                    <select name="nullable" id="null-sel">
                        <option value="YES">NULL diizinkan (DROP NOT NULL)</option>
                        <option value="NO">Wajib diisi (SET NOT NULL)</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-nullable')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Drop Kolom -->
<div id="modal-drop-col" class="modal-overlay" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <h3>Hapus Kolom</h3>
            <button class="modal-close" onclick="closeModal('modal-drop-col')">×</button>
        </div>
        <form method="POST">
                    <?= csrfField() ?>
            <input type="hidden" name="alter_op" value="drop_col">
            <input type="hidden" id="dropcol-name" name="drop_col_name">
            <div class="modal-body">
                <div class="alert alert-error">
                    Kolom <strong id="dropcol-label"></strong> akan dihapus permanen beserta semua datanya (CASCADE).
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-drop-col')">Batal</button>
                <button type="submit" class="btn btn-danger">Hapus Kolom</button>
            </div>
        </form>
    </div>
</div>

<style>
.alter-layout { display: grid; grid-template-columns: 1fr 320px; gap: 16px; align-items: flex-start; }
@media (max-width:900px) { .alter-layout { grid-template-columns: 1fr; } }
.alter-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
.alter-card-header { padding: 10px 14px; background: var(--bg-sidebar); border-bottom: 1px solid var(--border); font-size: 13px; font-weight: 600; }
.alter-card-body { padding: 14px; }
.alter-col-row { display: flex; align-items: center; gap: 10px; padding: 9px 0; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.alter-col-row:last-child { border-bottom: none; }
.alter-col-name { font-family: var(--mono); font-weight: 600; font-size: 13px; }
.alter-col-type { font-size: 11px; color: var(--text-muted); background: var(--bg-input); padding: 2px 6px; border-radius: 3px; }
</style>

<script>
function openRenameCol(col) {
    document.getElementById('rename-old').value = col;
    document.getElementById('rename-new').value = col;
    openModal('modal-rename-col');
    setTimeout(() => document.getElementById('rename-new').select(), 100);
}
function openChangeType(col, curType) {
    document.getElementById('ctype-col').value       = col;
    document.getElementById('ctype-col-label').value = col;
    const sel = document.querySelector('#modal-change-type select[name=new_type]');
    if (sel) [...sel.options].forEach(o => o.selected = o.value === curType);
    openModal('modal-change-type');
}
function openSetDefault(col, curDef) {
    document.getElementById('sdef-col').value       = col;
    document.getElementById('sdef-col-label').textContent = col;
    document.getElementById('sdef-val').value       = curDef;
    openModal('modal-set-default');
}
function openNullable(col, curNullable) {
    document.getElementById('null-col').value       = col;
    document.getElementById('null-col-label').textContent = col;
    document.getElementById('null-sel').value       = curNullable;
    openModal('modal-nullable');
}
function openDropCol(col) {
    document.getElementById('dropcol-name').value          = col;
    document.getElementById('dropcol-label').textContent   = col;
    openModal('modal-drop-col');
}
</script>

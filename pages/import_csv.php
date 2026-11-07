<?php
$flash     = null;
$flashType = 'success';
$preview   = [];
$csvHeaders = [];
$targetTable = $_GET['table'] ?? ($table ?? '');
$csvTotal   = 0;

/** Baca header, 5 baris pratinjau, dan total baris data dari file CSV. */
function csvPreview(string $path, string $delChar, bool $hasHeader): array {
    $headers = []; $preview = []; $total = 0; $first = true;
    $h = fopen($path, 'r');
    while (($row = fgetcsv($h, 0, $delChar)) !== false) {
        if ($row === [null]) continue; // baris kosong
        if ($first) {
            $first = false;
            if ($hasHeader) { $headers = $row; continue; }
            $headers = array_map(fn($i) => 'Kolom ' . ($i + 1), array_keys($row));
        }
        $total++;
        if (count($preview) < 5) $preview[] = $row;
    }
    fclose($h);
    return [$headers, $preview, $total];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') verifyCsrf();

// ── Proses Import ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_import'])) {
    $targetTable  = $_POST['target_table']  ?? '';
    $delimiter    = $_POST['delimiter']     ?? ',';
    $skipHeader   = !empty($_POST['skip_header']);
    $onConflict   = $_POST['on_conflict']   ?? 'skip'; // skip | update | error
    $conflictKey  = $_POST['conflict_key']  ?? '';
    $dryRun       = isset($_POST['dry_run']);
    $colMaps      = $_POST['col_map']       ?? []; // [csvIdx => tableCol]
    $tmpFile      = $_SESSION['csv_tmp']    ?? '';

    if (!$targetTable || !$tmpFile || !file_exists($tmpFile)) {
        $flash     = 'File CSV tidak ditemukan. Unggah ulang.';
        $flashType = 'error';
    } else {
        $handle     = fopen($tmpFile, 'r');
        $imported   = 0;
        $skipped    = 0;
        $errors     = 0;
        $lineNum    = 0;
        $firstErr   = null;
        $delChar    = $delimiter === 'tab' ? "\t" : $delimiter;

        // Ambil PK tabel tujuan
        $pkCol = null;
        try {
            if (isSQLite()) {
                $cols = getColumnsInfo($pdo, $schema, $targetTable);
                foreach ($cols as $c) {
                    if ($c['is_primary'] === 'YES') { $pkCol = $c['column_name']; break; }
                }
            } else {
                $st = $pdo->prepare("
                    SELECT ku.column_name FROM information_schema.table_constraints tc
                    JOIN information_schema.key_column_usage ku
                        ON tc.constraint_name = ku.constraint_name AND tc.table_schema = ku.table_schema
                    WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = ? AND tc.table_name = ?
                    LIMIT 1");
                $st->execute([$schema, $targetTable]);
                $pkCol = $st->fetchColumn();
            }
        } catch (Exception $e) {}

        // Mode update: butuh kolom kunci (default: primary key)
        if ($onConflict === 'update' && !isMySQL()) {
            if ($conflictKey === '') $conflictKey = (string)$pkCol;
            if ($conflictKey === '') {
                $flash = 'Mode "perbarui" butuh kolom kunci, tetapi tabel ini tidak punya primary key. Pilih kolom kunci (harus unik).';
                $flashType = 'error';
            }
        }

        if ($flashType !== 'error') try {
            $pdo->beginTransaction();
            while (($row = fgetcsv($handle, 0, $delChar)) !== false) {
                $lineNum++;
                if ($lineNum === 1 && $skipHeader) continue;
                if ($row === [null]) continue; // baris kosong

                $insertCols = [];
                $insertVals = [];
                foreach ($colMaps as $csvIdx => $tableCol) {
                    if (!$tableCol || $tableCol === '__skip__') continue;
                    $insertCols[] = qi($tableCol);
                    $insertVals[] = isset($row[$csvIdx]) && $row[$csvIdx] !== '' ? $row[$csvIdx] : null;
                }
                if (!$insertCols) continue;

                $ph  = implode(',', array_fill(0, count($insertCols), '?'));
                if ($onConflict === 'update') {
                    $base   = 'INSERT INTO ' . qualifiedTable($schema, $targetTable) . ' (' . implode(',', $insertCols) . ') VALUES (' . $ph . ')';
                    $keyQ   = qi($conflictKey);
                    $upd    = array_values(array_filter($insertCols, fn($c) => $c !== $keyQ));
                    if (isMySQL()) {
                        $sql = $base . ($upd ? ' ON DUPLICATE KEY UPDATE ' . implode(',', array_map(fn($c) => "$c=VALUES($c)", $upd)) : '');
                    } else {
                        $ref = isSQLite() ? 'excluded' : 'EXCLUDED';
                        $sql = $base . " ON CONFLICT ($keyQ) " . ($upd ? 'DO UPDATE SET ' . implode(',', array_map(fn($c) => "$c=$ref.$c", $upd)) : 'DO NOTHING');
                    }
                } elseif ($onConflict === 'skip') {
                    if (isMySQL()) {
                        $sql = 'INSERT IGNORE INTO ' . qualifiedTable($schema, $targetTable) . ' (' . implode(',', $insertCols) . ') VALUES (' . $ph . ')';
                    } elseif (isSQLite()) {
                        $sql = 'INSERT OR IGNORE INTO ' . qualifiedTable($schema, $targetTable) . ' (' . implode(',', $insertCols) . ') VALUES (' . $ph . ')';
                    } else {
                        $sql = 'INSERT INTO ' . qualifiedTable($schema, $targetTable) . ' (' . implode(',', $insertCols) . ') VALUES (' . $ph . ') ON CONFLICT DO NOTHING';
                    }
                } else {
                    $sql = 'INSERT INTO ' . qualifiedTable($schema, $targetTable) . ' (' . implode(',', $insertCols) . ') VALUES (' . $ph . ')';
                }

                try {
                    $st = $pdo->prepare($sql);
                    $st->execute($insertVals);
                    $affected = $st->rowCount();
                    if ($affected) $imported++; else $skipped++;
                } catch (Exception $e) {
                    // PostgreSQL membatalkan seluruh transaksi setelah error; hentikan agar tidak melapor sukses palsu
                    if ($onConflict === 'error' || isPgSQL()) {
                        throw new Exception("Baris CSV ke-$lineNum: " . $e->getMessage());
                    }
                    $errors++;
                    $firstErr ??= "Baris $lineNum: " . $e->getMessage();
                    $skipped++;
                }
            }
            if ($dryRun) $pdo->rollBack(); else $pdo->commit();
            $flash = ($dryRun ? '🧪 <strong>Uji coba (rollback — tidak ada data tersimpan):</strong> ' : '') . "Import selesai: <strong>$imported</strong> baris " . ($onConflict === 'update' ? 'ditambah/diperbarui' : 'berhasil') . ", <strong>$skipped</strong> dilewati."
                   . ($errors ? " <strong>$errors</strong> baris error (contoh: " . htmlspecialchars($firstErr) . ")" : '');
            if ($errors) $flashType = 'error';
            if (!$dryRun) { @unlink($tmpFile); unset($_SESSION['csv_tmp']); }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $flash     = 'Import gagal (tidak ada data yang diubah): ' . htmlspecialchars($e->getMessage());
            $flashType = 'error';
        }
        fclose($handle);
    }
}

// ── Upload & Preview ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $tmpPath   = $_FILES['csv_file']['tmp_name'];
    $delimiter = $_POST['delimiter'] ?? ',';
    $delChar   = $delimiter === 'tab' ? "\t" : $delimiter;
    $skipHeader = isset($_POST['skip_header']);

    if ($tmpPath && is_uploaded_file($tmpPath)) {
        // Simpan file sementara
        $savedTmp = sys_get_temp_dir() . '/pgm_csv_' . uniqid() . '.csv';
        move_uploaded_file($tmpPath, $savedTmp);
        $_SESSION['csv_tmp']         = $savedTmp;
        $_SESSION['csv_delimiter']   = $delimiter;
        $_SESSION['csv_skip_header'] = $skipHeader ? 1 : 0;

        [$csvHeaders, $preview, $csvTotal] = csvPreview($savedTmp, $delChar, $skipHeader);
    }
} elseif (isset($_SESSION['csv_tmp']) && file_exists($_SESSION['csv_tmp'])) {
    // Re-load preview dari file yang sudah diupload
    $delChar = $_SESSION['csv_delimiter'] === 'tab' ? "\t" : ($_SESSION['csv_delimiter'] ?? ',');
    [$csvHeaders, $preview, $csvTotal] = csvPreview($_SESSION['csv_tmp'], $delChar, !empty($_SESSION['csv_skip_header']));
}

// Tabel tujuan — ambil kolomnya
$targetCols = [];
if ($targetTable && in_array($targetTable, $tables)) {
    try {
        $targetCols = getColumnsInfo($pdo, $schema, $targetTable);
    } catch (Exception $e) {}
}
?>

<div class="page-header">
    <h1>Import CSV</h1>
    <p class="page-sub">Unggah file CSV dan masukkan langsung ke tabel database</p>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType ?>"><?= $flash ?></div>
<?php endif; ?>

<div class="import-layout">

    <!-- ── Panel Kiri: Upload + Pengaturan ── -->
    <div>
        <div class="alter-card" style="margin-bottom:12px">
            <div class="alter-card-header">📂 Pilih File CSV</div>
            <div class="alter-card-body">
                <form method="POST" enctype="multipart/form-data" id="upload-form">
                    <?= csrfField() ?>
                    <div class="form-group">
                        <label>File CSV</label>
                        <div class="file-drop-zone" onclick="document.getElementById('csv-file-input').click()">
                            <svg width="24" height="24" fill="none" viewBox="0 0 24 24"><path d="M12 4v12M6 10l6-6 6 6" stroke="#3ECF8E" stroke-width="1.5" stroke-linecap="round"/><path d="M4 18h16" stroke="#3ECF8E" stroke-width="1.5" stroke-linecap="round"/></svg>
                            <span id="csv-file-label">Klik atau drag file CSV ke sini</span>
                        </div>
                        <input type="file" id="csv-file-input" name="csv_file" accept=".csv,.tsv,.txt" style="display:none" onchange="updateCsvLabel(this)">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Delimiter</label>
                            <select name="delimiter" id="csv-delimiter">
                                <option value=",">, (koma)</option>
                                <option value=";">; (titik koma)</option>
                                <option value="tab">⇥ (tab)</option>
                                <option value="|">| (pipe)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="check-label" style="margin-top:24px">
                                <input type="checkbox" name="skip_header" value="1" checked id="csv-skip-header">
                                Baris pertama = header
                            </label>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Upload &amp; Preview</button>
                </form>
            </div>
        </div>

        <!-- Preview CSV -->
        <?php if ($csvHeaders): ?>
        <div class="alter-card">
            <div class="alter-card-header">👁️ Preview (5 baris pertama)</div>
            <div class="alter-card-body" style="padding:0">
                <div class="csv-preview-wrap">
                    <table class="data-table">
                        <thead><tr>
                            <th>#</th>
                            <?php foreach ($csvHeaders as $h): ?>
                            <th><?= htmlspecialchars($h) ?></th>
                            <?php endforeach; ?>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($preview as $i => $row): ?>
                            <tr>
                                <td class="data-cell" style="color:var(--text-muted)"><?= $i+1 ?></td>
                                <?php foreach ($row as $cell): ?>
                                <td class="data-cell"><?= htmlspecialchars($cell) ?></td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Panel Kanan: Mapping & Import ── -->
    <div>
        <form method="POST" id="import-form">
            <?= csrfField() ?>
            <input type="hidden" name="do_import" value="1">

            <div class="alter-card" style="margin-bottom:12px">
                <div class="alter-card-header">🎯 Tabel Tujuan</div>
                <div class="alter-card-body">
                    <div class="form-group">
                        <label>Pilih Tabel</label>
                        <select name="target_table" id="target-table-sel" onchange="window.location.href='app.php?page=import-csv&schema=<?= urlencode($schema) ?>&table='+encodeURIComponent(this.value)">
                            <option value="">— pilih tabel —</option>
                            <?php foreach ($tables as $t): ?>
                            <option value="<?= htmlspecialchars($t) ?>" <?= $targetTable === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Jika baris duplikat</label>
                            <select name="on_conflict">
                                <option value="skip">Lewati (ON CONFLICT DO NOTHING)</option>
                                <option value="update">Perbarui baris yang sudah ada (upsert)</option>
                                <option value="error">Hentikan dengan error</option>
                            </select>
                        </div>
                    </div>
                    <?php if ($targetCols && !isMySQL()): ?>
                    <div class="form-group" id="conflict-key-group" style="display:none">
                        <label>Kolom kunci (harus unik / primary key)</label>
                        <select name="conflict_key">
                            <?php foreach ($targetCols as $tc): ?>
                            <option value="<?= htmlspecialchars($tc['column_name']) ?>" <?= $tc['is_primary'] === 'YES' ? 'selected' : '' ?>><?= htmlspecialchars($tc['column_name']) ?><?= $tc['is_primary'] === 'YES' ? ' (PK)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <input type="hidden" name="delimiter" value="<?= htmlspecialchars($_SESSION['csv_delimiter'] ?? ',') ?>">
                    <input type="hidden" name="skip_header" value="<?= !empty($_SESSION['csv_skip_header']) ? 1 : 0 ?>">
                </div>
            </div>

            <?php if ($csvHeaders && $targetCols): ?>
            <div class="alter-card" style="margin-bottom:12px">
                <div class="alter-card-header">🔗 Mapping Kolom CSV → Tabel</div>
                <div class="alter-card-body">
                    <div style="display:grid;grid-template-columns:1fr auto 1fr;gap:6px;margin-bottom:8px;font-size:11px;font-weight:600;color:var(--text-muted)">
                        <span>Kolom CSV</span><span></span><span>Kolom Tabel</span>
                    </div>
                    <?php foreach ($csvHeaders as $idx => $header): ?>
                    <div class="col-map-row">
                        <div class="col-map-csv">
                            <?= htmlspecialchars($header) ?>
                            <span style="color:var(--text-muted);font-size:10px">
                                (<?= isset($preview[0][$idx]) ? htmlspecialchars(mb_strimwidth($preview[0][$idx],0,20,'…')) : '—' ?>)
                            </span>
                        </div>
                        <div class="col-map-arrow">→</div>
                        <select name="col_map[<?= $idx ?>]" class="col-map-sel">
                            <option value="__skip__">— lewati —</option>
                            <?php foreach ($targetCols as $tc):
                                // Auto-match: nama kolom sama (case insensitive)
                                $norm = fn($x) => preg_replace('/[^a-z0-9]/', '', strtolower($x));
                                $matched = $norm($tc['column_name']) === $norm($header);
                                $required = $tc['is_nullable'] === 'NO' && $tc['column_default'] === null;
                            ?>
                            <option value="<?= htmlspecialchars($tc['column_name']) ?>" <?= $matched ? 'selected' : '' ?> <?= $required ? 'data-required="1"' : '' ?>>
                                <?= htmlspecialchars($tc['column_name']) ?> (<?= htmlspecialchars($tc['data_type']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="alter-card" style="margin-bottom:12px">
                <div class="alter-card-header">📋 Ringkasan</div>
                <div class="alter-card-body" id="import-summary" style="font-size:13px;line-height:1.7"
                     data-total="<?= (int)$csvTotal ?>" data-required='<?= htmlspecialchars(json_encode(array_values(array_map(fn($c) => $c['column_name'], array_filter($targetCols, fn($c) => $c['is_nullable'] === 'NO' && $c['column_default'] === null))))) ?>'></div>
            </div>

            <div style="display:flex;gap:8px">
                <button type="submit" name="dry_run" value="1" class="btn btn-outline" style="flex:1" title="Jalankan import lalu batalkan (rollback)">🧪 Uji Coba (Rollback)</button>
                <button type="submit" class="btn btn-primary" style="flex:2" id="btn-do-import" onclick="showImportProgress()">↑ Mulai Import</button>
            </div>

            <div class="import-progress" id="import-progress" style="margin-top:12px">
                <div style="font-size:12px;color:var(--text-secondary);margin-bottom:4px">Mengimpor data...</div>
                <div class="progress-bar-wrap">
                    <div class="progress-bar" id="progress-bar" style="width:0%"></div>
                </div>
            </div>

            <?php elseif ($csvHeaders && !$targetCols): ?>
            <div class="help-callout callout-info">
                Pilih tabel tujuan terlebih dahulu untuk melihat opsi mapping kolom.
            </div>
            <?php elseif (!$csvHeaders): ?>
            <div class="empty-state" style="padding:32px;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius)">
                <svg width="36" height="36" fill="none" viewBox="0 0 36 36"><path d="M6 8h24v20H6z" stroke="#3ECF8E" stroke-width="1.5" rx="2"/><path d="M6 13h24M12 8v20M18 8v20M24 8v20" stroke="#3ECF8E" stroke-width="1" stroke-dasharray="2 2"/></svg>
                <p style="color:var(--text-muted)">Upload file CSV terlebih dahulu</p>
            </div>
            <?php endif; ?>
        </form>
    </div>

</div>

<script>
function updateCsvLabel(input) {
    const label = document.getElementById('csv-file-label');
    if (input.files[0]) {
        label.textContent = input.files[0].name;
        label.style.color = 'var(--accent)';
        // Auto-submit untuk preview
        document.getElementById('upload-form').submit();
    }
}

// Ringkasan: jumlah baris, kolom terpetakan, kolom wajib yang belum dipetakan
function updateImportSummary() {
    const box = document.getElementById('import-summary');
    if (!box) return;
    const sels = [...document.querySelectorAll('.col-map-sel')];
    const mapped = sels.map(s => s.value).filter(v => v && v !== '__skip__');
    const dupes = mapped.filter((v, i) => mapped.indexOf(v) !== i);
    const required = JSON.parse(box.dataset.required || '[]');
    const missing = required.filter(c => !mapped.includes(c));
    const total = parseInt(box.dataset.total || '0');
    let html = `📄 <strong>${total.toLocaleString('id-ID')}</strong> baris data &nbsp;·&nbsp; 🔗 <strong>${mapped.length}</strong> dari ${sels.length} kolom CSV dipetakan`;
    if (!mapped.length) html += `<br><span style="color:var(--danger)">⚠ Belum ada kolom yang dipetakan.</span>`;
    if (dupes.length) html += `<br><span style="color:var(--danger)">⚠ Kolom tabel dipetakan lebih dari sekali: ${[...new Set(dupes)].join(', ')}</span>`;
    if (missing.length) html += `<br><span style="color:var(--warning)">⚠ Kolom wajib (NOT NULL, tanpa default) belum dipetakan: ${missing.join(', ')}</span>`;
    box.innerHTML = html;
    const btn = document.getElementById('btn-do-import');
    if (btn) btn.disabled = !mapped.length || dupes.length > 0;
}
document.querySelectorAll('.col-map-sel').forEach(s => s.addEventListener('change', updateImportSummary));
document.querySelector('[name=on_conflict]')?.addEventListener('change', function() {
    const g = document.getElementById('conflict-key-group');
    if (g) g.style.display = this.value === 'update' ? '' : 'none';
});
updateImportSummary();

function showImportProgress() {
    document.getElementById('import-progress').classList.add('show');
    let w = 0;
    const bar = document.getElementById('progress-bar');
    const iv = setInterval(() => {
        w = Math.min(w + Math.random() * 15, 90);
        bar.style.width = w + '%';
    }, 300);
}

// Drag & drop
const dz = document.querySelector('.file-drop-zone');
if (dz) {
    dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('drag-active'); });
    dz.addEventListener('dragleave', () => dz.classList.remove('drag-active'));
    dz.addEventListener('drop', e => {
        e.preventDefault(); dz.classList.remove('drag-active');
        const fi = document.getElementById('csv-file-input');
        fi.files = e.dataTransfer.files;
        updateCsvLabel(fi);
    });
}
</script>

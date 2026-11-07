<?php
// Table editor page
$action = $_GET['action'] ?? null;
$type   = $_GET['type'] ?? 'table';

// ── Export handler ────────────────────────────────────────────
if (isset($_GET['export']) && $table) {
    $fmt = $_GET['export'];
    $exportSchema = $schema;
    $exportTable  = $table;
    try {
        $rows = $pdo->query('SELECT * FROM ' . qualifiedTable($exportSchema, $exportTable))->fetchAll();
        $safeName = preg_replace('/[^a-zA-Z0-9_]/', '_', $exportTable);
        if ($fmt === 'json') {
            header('Content-Type: application/json');
            header("Content-Disposition: attachment; filename=\"{$safeName}.json\"");
            echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($fmt === 'excel') {
            // Ekspor sebagai CSV dengan BOM UTF-8 — Excel membaca kolom dengan benar
            header('Content-Type: text/csv; charset=UTF-8');
            header("Content-Disposition: attachment; filename=\"{$safeName}.csv\"");
            // BOM UTF-8 agar Excel mengenali encoding & membuka dialog import otomatis
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            if ($rows) {
                // Tulis header
                fputcsv($out, array_keys($rows[0]), ',', '"', '\\');
                // Tulis data — fputcsv otomatis escape nilai yang mengandung koma/newline/quote
                foreach ($rows as $r) {
                    fputcsv($out, array_map(fn($v) => $v ?? '', $r), ',', '"', '\\');
                }
            }
            fclose($out);
            exit;
        }
        if ($fmt === 'csv') {
            header('Content-Type: text/csv; charset=UTF-8');
            header("Content-Disposition: attachment; filename=\"{$safeName}.csv\"");
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            if ($rows) {
                fputcsv($out, array_keys($rows[0]));
                foreach ($rows as $r) fputcsv($out, $r);
            }
            fclose($out);
            exit;
        }
    } catch (Exception $e) {}
}

$message = null;
$msgType = 'success';

// Handle insert/update/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $table) {
    verifyCsrf(); // BUG-04 FIX
    $postAction = $_POST['_action'] ?? null;

    try {
        if ($postAction === 'insert') {
            $cols = $_POST['col'] ?? [];
            $vals = $_POST['val'] ?? [];
            $validCols = [];
            $validVals = [];
            foreach ($cols as $i => $col) {
                if ($col !== '' && isset($vals[$i])) {
                    $validCols[] = qi($col);
                    $validVals[] = $vals[$i] === '' ? null : $vals[$i];
                }
            }
            if ($validCols) {
                $placeholders = implode(',', array_fill(0, count($validCols), '?'));
                $sql = 'INSERT INTO ' . qualifiedTable($schema, $table) . ' (' . implode(',', $validCols) . ') VALUES (' . $placeholders . ')';
                $pdo->prepare($sql)->execute($validVals);
                $message = 'Baris berhasil ditambahkan.';
            }
        }

        if ($postAction === 'delete') {
            $pkCol = $_POST['pk_col'] ?? null;
            $pkVal = $_POST['pk_val'] ?? null;
            if ($pkCol && $pkVal !== null) {
                $saved = undoCapture($pdo, $schema, $table, qi($pkCol) . ' = ?', [$pkVal]);
                $sql = 'DELETE FROM ' . qualifiedTable($schema, $table) . ' WHERE ' . qi($pkCol) . ' = ?';
                $pdo->prepare($sql)->execute([$pkVal]);
                $message = 'Baris berhasil dihapus.' . ($saved ? ' Bisa diurungkan.' : '');
            }
        }

        if ($postAction === 'update') {
            $pkCol  = $_POST['pk_col'] ?? null;
            $pkVal  = $_POST['pk_val'] ?? null;
            $col    = $_POST['upd_col'] ?? null;
            $val    = $_POST['upd_val'] ?? null;
            if ($pkCol && $pkVal !== null && $col) {
                $sql = 'UPDATE ' . qualifiedTable($schema, $table) . ' SET ' . qi($col) . ' = ? WHERE ' . qi($pkCol) . ' = ?';
                $pdo->prepare($sql)->execute([$val === '' ? null : $val, $pkVal]);
                $message = 'Data berhasil diperbarui.';
            }
        }

        if ($postAction === 'bulk_delete') {
            $pkColPost = $_POST['pk_col'] ?? null;
            $pkVals    = json_decode($_POST['pk_vals'] ?? '[]', true) ?: [];
            if ($pkColPost && $pkVals) {
                $placeholders = implode(',', array_fill(0, count($pkVals), '?'));
                $saved = undoCapture($pdo, $schema, $table, qi($pkColPost) . ' IN (' . $placeholders . ')', $pkVals);
                $sql = 'DELETE FROM ' . qualifiedTable($schema, $table) . ' WHERE ' . qi($pkColPost) . ' IN (' . $placeholders . ')';
                $pdo->prepare($sql)->execute($pkVals);
                $message = count($pkVals) . ' baris berhasil dihapus.' . ($saved ? ' Bisa diurungkan.' : '');
            }
        }

        if ($postAction === 'truncate') {
            if (($_POST['confirm_name'] ?? '') !== $table) {
                throw new Exception('Truncate dibatalkan: nama tabel konfirmasi tidak cocok.');
            }
            $saved = undoCapture($pdo, $schema, $table, '', [], 'truncate');
            $pdo->exec(truncateSql($schema, $table));
            $message = 'Tabel berhasil dikosongkan.' . ($saved ? ' Bisa diurungkan.' : '');
        }

        if ($postAction === 'undo_delete') {
            $n = undoRestore($pdo, $schema, $table);
            $message = "$n baris berhasil dipulihkan.";
        }

        if ($postAction === 'drop') {
            $cascade = isPgSQL() ? ' CASCADE' : '';
            $pdo->exec('DROP TABLE ' . qualifiedTable($schema, $table) . $cascade);  // SQLite: qualifiedTable = qi(table), no CASCADE needed but harmless
            header('Location: app.php?page=table-editor&schema=' . urlencode($schema));
            exit;
        }

    } catch (Exception $e) {
        $message = $e->getMessage();
        $msgType = 'error';
    }
}

// Redirect setelah POST — bangun URL eksplisit agar parameter tidak hilang
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $message && $msgType === 'success') {
    $_SESSION['flash_message'] = $message;
    $redirectUrl = 'app.php?page=table-editor'
        . '&schema=' . urlencode($schema)
        . ($table ? '&table=' . urlencode($table) : '')
        . (isset($_GET['sort'])     ? '&sort='     . urlencode($_GET['sort'])     : '')
        . (isset($_GET['dir'])      ? '&dir='      . urlencode($_GET['dir'])      : '')
        . (isset($_GET['per_page']) ? '&per_page=' . urlencode($_GET['per_page']) : '')
        . (isset($_GET['p'])        ? '&p='        . urlencode($_GET['p'])        : '')
        . (isset($_GET['search'])   ? '&search='   . urlencode($_GET['search'])   : '');
    header('Location: ' . $redirectUrl);
    exit;
}

if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

// Kalau tidak ada tabel dipilih
if (!$table): ?>
<div class="page-header">
    <h1>Table Editor</h1>
    <p class="page-sub">Pilih tabel di sidebar untuk melihat dan mengelola data.</p>
</div>
<div class="empty-state">
    <svg width="48" height="48" fill="none" viewBox="0 0 48 48"><rect x="4" y="8" width="40" height="32" rx="4" stroke="#3ECF8E" stroke-width="2"/><path d="M4 16h40" stroke="#3ECF8E" stroke-width="2"/><path d="M16 16v24M32 16v24" stroke="#3ECF8E" stroke-width="1.5" stroke-dasharray="3 3"/></svg>
    <h3>Belum ada tabel dipilih</h3>
    <p>Pilih tabel dari sidebar kiri untuk mulai melihat data.</p>
</div>
<?php return; endif;

// Ambil kolom
$columns = [];
try {
    $columns = getColumnsInfo($pdo, $schema, $table);
} catch (Exception $e) {}

$pkCol = null;
foreach ($columns as $col) {
    if ($col['is_primary'] === 'YES') { $pkCol = $col['column_name']; break; }
}

// Paginasi & sorting
$perPage  = max(1, min(500, (int)($_GET['per_page'] ?? 50))); // BUG-08/09 FIX: clamp 1–500
$page_num = max(1, (int)($_GET['p'] ?? 1));
$offset   = ($page_num - 1) * $perPage;
$search   = $_GET['search'] ?? '';
// BUG-10 FIX: validasi sortCol harus ada di daftar kolom tabel
$sortColRaw = $_GET['sort'] ?? null;
$sortCol    = ($sortColRaw && in_array($sortColRaw, array_column($columns, 'column_name')))
              ? $sortColRaw
              : ($pkCol ?? ($columns[0]['column_name'] ?? null));
$sortDir  = strtoupper($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

// ── Filter kondisi (dari GET) ──────────────────────────────────
// Format: filter_col[]=..., filter_op[]=..., filter_val[]=..., filter_logic[]=...
$filterCols   = $_GET['filter_col']   ?? [];
$filterOps    = $_GET['filter_op']    ?? [];
$filterVals   = $_GET['filter_val']   ?? [];
$filterLogics = $_GET['filter_logic'] ?? [];  // AND / OR antar kondisi

// Operator yang diizinkan (whitelist) — disesuaikan per driver
$likeOp    = isPgSQL() ? 'ILIKE ?' : 'LIKE ?';
$notLikeOp = isPgSQL() ? 'NOT ILIKE ?' : 'NOT LIKE ?';
$allowedOps = [
    'ilike'        => $likeOp,
    'not_ilike'    => $notLikeOp,
    'eq'           => '= ?',
    'neq'          => '!= ?',
    'gt'           => '> ?',
    'gte'          => '>= ?',
    'lt'           => '< ?',
    'lte'          => '<= ?',
    'is_null'      => 'IS NULL',
    'is_not_null'  => 'IS NOT NULL',
    'starts_with'  => $likeOp,
    'ends_with'    => $likeOp,
    'in'           => 'IN (?)',   // diproses manual
];

$validColNames = array_column($columns, 'column_name');

$conditions   = [];
$filterParams = [];
$activeFilters = [];  // untuk ditampilkan ulang di UI

foreach ($filterCols as $i => $fcol) {
    $fcol = trim($fcol);
    $fop  = $filterOps[$i]  ?? 'ilike';
    $fval = $filterVals[$i] ?? '';
    $flog = strtoupper($filterLogics[$i] ?? 'AND');
    $flog = in_array($flog, ['AND', 'OR']) ? $flog : 'AND';

    if (!$fcol || !in_array($fcol, $validColNames)) continue;
    if (!isset($allowedOps[$fop])) continue;

    $activeFilters[] = ['col' => $fcol, 'op' => $fop, 'val' => $fval, 'logic' => $flog];

    $colExpr = qi($fcol);
    // Operator tanpa nilai
    if (in_array($fop, ['is_null', 'is_not_null'])) {
        $conditions[] = [$flog, "$colExpr " . ($fop === 'is_null' ? 'IS NULL' : 'IS NOT NULL')];
        continue;
    }
    // Operator IN (nilai dipisah koma)
    if ($fop === 'in') {
        $inVals = array_map('trim', explode(',', $fval));
        $inVals = array_filter($inVals, fn($v) => $v !== '');
        if (!$inVals) continue;
        $placeholders = implode(',', array_fill(0, count($inVals), '?'));
        $conditions[] = [$flog, castText($colExpr) . " IN ($placeholders)"];
        foreach ($inVals as $iv) $filterParams[] = $iv;
        continue;
    }
    // Modifikasi nilai untuk ILIKE pattern
    $paramVal = $fval;
    if ($fop === 'ilike' || $fop === 'not_ilike') $paramVal = '%' . $fval . '%';
    elseif ($fop === 'starts_with') $paramVal = $fval . '%';
    elseif ($fop === 'ends_with')   $paramVal = '%' . $fval;

    $sqlOp = $allowedOps[$fop];
    $conditions[] = [$flog, castText($colExpr) . " $sqlOp"];
    $filterParams[] = $paramVal;
}

// Bangun WHERE dari kondisi
$whereClause  = '';
$searchParams = [];

$allConditions = [];

// Search global (kolom lama, tetap berjalan)
if ($search && $columns) {
    $sc = [];
    foreach ($columns as $col) {
        $sc[] = ilike(castText(qi($col['column_name'])));
        $searchParams[] = '%' . $search . '%';
    }
    $allConditions[] = '(' . implode(' OR ', $sc) . ')';
}

// Filter kondisi baru
if ($conditions) {
    $parts = [];
    foreach ($conditions as $idx => [$logic, $expr]) {
        if ($idx === 0) $parts[] = $expr;
        else            $parts[] = "$logic $expr";
    }
    $allConditions[] = '(' . implode(' ', $parts) . ')';
}

if ($allConditions) {
    $whereClause = 'WHERE ' . implode(' AND ', $allConditions);
}
$allParams = array_merge($searchParams, $filterParams);

// ── Query data ────────────────────────────────────────────────
$totalRows = 0;
$rows      = [];
$queryError = null;

try {
    $qtbl    = qualifiedTable($schema, $table);
    $countSql = 'SELECT COUNT(*) FROM ' . $qtbl . ' ' . $whereClause;
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($allParams);
    $totalRows = (int)$countStmt->fetchColumn();

    $orderBy = $sortCol ? qi($sortCol) . ' ' . $sortDir : '1';
    $dataSql  = 'SELECT * FROM ' . $qtbl . ' ' . $whereClause
              . ' ORDER BY ' . $orderBy
              . ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
    $dataStmt = $pdo->prepare($dataSql);
    $dataStmt->execute($allParams);
    $rows = $dataStmt->fetchAll();
} catch (Exception $e) {
    $message = $e->getMessage();
    $msgType = 'error';
}

$totalPages   = $perPage > 0 ? ceil($totalRows / $perPage) : 1;
$hasFilters   = count($activeFilters) > 0;
$baseUrl      = 'app.php?page=table-editor&schema=' . urlencode($schema) . '&table=' . urlencode($table);

// URL filter saat ini (untuk pagination)
$filterQueryStr = '';
foreach ($activeFilters as $i => $f) {
    $filterQueryStr .= '&filter_col[]='   . urlencode($f['col'])
                    .  '&filter_op[]='    . urlencode($f['op'])
                    .  '&filter_val[]='   . urlencode($f['val'])
                    .  '&filter_logic[]=' . urlencode($f['logic']);
}
?>

<div class="page-header">
    <div>
        <h1><?= htmlspecialchars($schema) ?>.<strong><?= htmlspecialchars($table) ?></strong></h1>
        <p class="page-sub"><?= number_format($totalRows) ?> baris</p>
    </div>
    <div class="header-actions">
        <!-- Bulk delete — muncul hanya saat ada baris dipilih -->
        <button class="btn btn-outline btn-danger" id="btn-bulk-delete" style="display:none"
                onclick="bulkDelete()">
            🗑 Hapus Terpilih (<span id="bulk-count">0</span>)
        </button>
        <button class="btn btn-outline" onclick="openStructureModal()">Struktur</button>
        <a href="app.php?page=alter-table&schema=<?= urlencode($schema) ?>&table=<?= urlencode($table) ?>" class="btn btn-outline">ALTER</a>
        <button class="btn btn-outline btn-warning" onclick="openModal('modal-truncate')">Truncate</button>
        <div class="export-wrap">
            <button type="button" class="btn btn-outline" onclick="toggleExportMenu('tbl-export-menu')">⬇ Export ▾</button>
            <div class="export-menu" id="tbl-export-menu">
                <button onclick="exportTableData('csv')">📄 CSV</button>
                <button onclick="exportTableData('json')">📋 JSON</button>
                <button onclick="exportTableData('excel')">📊 Excel</button>
            </div>
        </div>
        <a href="app.php?page=import-csv&schema=<?= urlencode($schema) ?>&table=<?= urlencode($table) ?>" class="btn btn-outline">⬆ Import CSV</a>
        <button class="btn btn-outline btn-danger" onclick="openDropModal()">Drop</button>
        <button class="btn btn-primary" onclick="openInsertModal()">+ Insert Row</button>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($undo = undoAvailable($schema, $table)): ?>
<div class="alert alert-warning undo-banner">
    ↩ <?= count($undo['rows']) ?> baris baru saja dihapus (<?= htmlspecialchars($undo['label']) ?>).
    <form method="POST" style="display:inline">
        <?= csrfField() ?>
        <input type="hidden" name="_action" value="undo_delete">
        <button type="submit" class="btn btn-primary btn-sm" style="margin-left:8px">Urungkan</button>
    </form>
    <span style="color:var(--text-muted);font-size:12px;margin-left:8px">Data yang ikut terhapus via ON DELETE CASCADE tidak ikut dipulihkan. Tersedia 10 menit.</span>
</div>
<?php endif; ?>

<!-- ── Toolbar ─────────────────────────────────────────────── -->
<div class="toolbar">
    <form method="GET" action="app.php" id="filter-form" class="search-form">
        <input type="hidden" name="page"   value="table-editor">
        <input type="hidden" name="schema" value="<?= htmlspecialchars($schema) ?>">
        <input type="hidden" name="table"  value="<?= htmlspecialchars($table) ?>">
        <input type="hidden" name="sort"   value="<?= htmlspecialchars($sortCol ?? '') ?>">
        <input type="hidden" name="dir"    value="<?= $sortDir ?>">

        <!-- Search global -->
        <div class="search-input-wrap">
            <svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/></svg>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari semua kolom...">
        </div>

        <!-- Tombol Filter -->
        <button type="button"
                class="btn btn-outline <?= $hasFilters ? 'btn-filter-active' : '' ?>"
                onclick="toggleFilterPanel()">
            <svg width="13" height="13" fill="currentColor" viewBox="0 0 16 16"><path d="M6 10.5a.5.5 0 0 1 .5-.5h3a.5.5 0 0 1 0 1h-3a.5.5 0 0 1-.5-.5zm-2-3a.5.5 0 0 1 .5-.5h7a.5.5 0 0 1 0 1h-7a.5.5 0 0 1-.5-.5zm-2-3a.5.5 0 0 1 .5-.5h11a.5.5 0 0 1 0 1h-11a.5.5 0 0 1-.5-.5z"/></svg>
            Filter
            <?php if ($hasFilters): ?>
            <span class="filter-badge"><?= count($activeFilters) ?></span>
            <?php endif; ?>
        </button>

        <!-- Tombol Kolom (sembunyikan/tampilkan) -->
        <div class="export-wrap" id="colvis-wrap">
            <button type="button" class="btn btn-outline" id="colvis-btn" onclick="toggleColVis(event)">
                ▥ Kolom <span class="filter-badge" id="colvis-badge" style="display:none"></span>
            </button>
            <div class="export-menu colvis-menu" id="colvis-menu" onclick="event.stopPropagation()">
                <div class="colvis-actions">
                    <button type="button" onclick="setAllCols(true)">Tampilkan semua</button>
                    <button type="button" onclick="setAllCols(false)">Sembunyikan semua</button>
                </div>
                <label class="colvis-item colvis-opt">
                    <input type="checkbox" id="freeze-chk" checked onchange="toggleFreeze(this.checked)" style="accent-color:var(--accent)">
                    <span>📌 Kunci kolom pertama saat scroll</span>
                </label>
                <label class="colvis-item colvis-opt" style="color:var(--text-muted)" onclick="resetLayout()">
                    <span>↺ Reset tampilan (lebar, urutan, sembunyi)</span>
                </label>
                <?php foreach ($columns as $i => $col): ?>
                <label class="colvis-item">
                    <input type="checkbox" class="colvis-chk" data-col="<?= htmlspecialchars($col['column_name']) ?>"
                           data-idx="<?= $i ?>" checked onchange="onColVisChange()" style="accent-color:var(--accent)">
                    <span><?= htmlspecialchars($col['column_name']) ?></span>
                    <span class="col-type"><?= htmlspecialchars($col['data_type']) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <select name="per_page" onchange="this.form.submit()">
            <?php foreach ([25, 50, 100, 500] as $n): ?>
            <option value="<?= $n ?>" <?= $perPage == $n ? 'selected' : '' ?>><?= $n ?> baris</option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn btn-outline">Terapkan</button>

        <?php if ($hasFilters || $search): ?>
        <a href="<?= $baseUrl ?>" class="btn btn-outline btn-danger-soft">✕ Reset</a>
        <?php endif; ?>
    </form>
</div>

<!-- ── Filter Panel ────────────────────────────────────────── -->
<div id="filter-panel" class="filter-panel" style="<?= $hasFilters ? '' : 'display:none' ?>">
    <div class="filter-panel-header">
        <span class="filter-panel-title">
            <svg width="13" height="13" fill="currentColor" viewBox="0 0 16 16"><path d="M6 10.5a.5.5 0 0 1 .5-.5h3a.5.5 0 0 1 0 1h-3a.5.5 0 0 1-.5-.5zm-2-3a.5.5 0 0 1 .5-.5h7a.5.5 0 0 1 0 1h-7a.5.5 0 0 1-.5-.5zm-2-3a.5.5 0 0 1 .5-.5h11a.5.5 0 0 1 0 1h-11a.5.5 0 0 1-.5-.5z"/></svg>
            Kondisi Filter
        </span>
        <button type="button" class="btn btn-outline btn-sm" onclick="addFilterRow()">+ Tambah Kondisi</button>
    </div>

    <div id="filter-rows" form="filter-form">
        <?php if ($activeFilters): ?>
            <?php foreach ($activeFilters as $i => $f): ?>
            <div class="filter-row" data-idx="<?= $i ?>">
                <!-- Logic (AND/OR) — hanya muncul ab baris ke-2 -->
                <div class="filter-logic <?= $i === 0 ? 'filter-logic-first' : '' ?>">
                    <?php if ($i === 0): ?>
                    <span class="filter-where-label">WHERE</span>
                    <?php else: ?>
                    <select name="filter_logic[]" class="filter-logic-sel">
                        <option value="AND" <?= $f['logic'] === 'AND' ? 'selected' : '' ?>>AND</option>
                        <option value="OR"  <?= $f['logic'] === 'OR'  ? 'selected' : '' ?>>OR</option>
                    </select>
                    <?php endif; ?>
                </div>
                <!-- Kolom -->
                <select name="filter_col[]" class="filter-col-sel" onchange="updateOpOptions(this)">
                    <?php foreach ($validColNames as $cn): ?>
                    <option value="<?= htmlspecialchars($cn) ?>" <?= $cn === $f['col'] ? 'selected' : '' ?>><?= htmlspecialchars($cn) ?></option>
                    <?php endforeach; ?>
                </select>
                <!-- Operator -->
                <select name="filter_op[]" class="filter-op-sel" onchange="toggleValueInput(this)">
                    <?php foreach (getFilterOpLabels() as $opKey => $opLabel): ?>
                    <option value="<?= $opKey ?>" <?= $opKey === $f['op'] ? 'selected' : '' ?>><?= $opLabel ?></option>
                    <?php endforeach; ?>
                </select>
                <!-- Nilai -->
                <input type="text" name="filter_val[]" class="filter-val-inp"
                       value="<?= htmlspecialchars($f['val']) ?>"
                       placeholder="nilai..."
                       <?= in_array($f['op'], ['is_null','is_not_null']) ? 'style="display:none"' : '' ?>>
                <!-- Hapus baris -->
                <button type="button" class="btn-icon btn-delete" onclick="removeFilterRow(this)" title="Hapus kondisi">✕</button>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <!-- Baris default kosong -->
            <div class="filter-row" data-idx="0">
                <div class="filter-logic filter-logic-first">
                    <span class="filter-where-label">WHERE</span>
                </div>
                <select name="filter_col[]" class="filter-col-sel" onchange="updateOpOptions(this)">
                    <?php foreach ($validColNames as $cn): ?>
                    <option value="<?= htmlspecialchars($cn) ?>"><?= htmlspecialchars($cn) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="filter_op[]" class="filter-op-sel" onchange="toggleValueInput(this)">
                    <?php foreach (getFilterOpLabels() as $opKey => $opLabel): ?>
                    <option value="<?= $opKey ?>"><?= $opLabel ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="filter_val[]" class="filter-val-inp" placeholder="nilai...">
                <button type="button" class="btn-icon btn-delete" onclick="removeFilterRow(this)" title="Hapus kondisi">✕</button>
            </div>
        <?php endif; ?>
    </div>

    <div class="filter-panel-footer">
        <button type="submit" form="filter-form" class="btn btn-primary btn-sm">Terapkan Filter</button>
        <a href="<?= $baseUrl ?>" class="btn btn-outline btn-sm">Reset Semua</a>
    </div>
</div>

<?php
// Helper: daftar label operator untuk UI
function getFilterOpLabels(): array {
    return [
        'ilike'       => 'mengandung',
        'not_ilike'   => 'tidak mengandung',
        'eq'          => '= sama dengan',
        'neq'         => '≠ tidak sama',
        'gt'          => '> lebih besar',
        'gte'         => '≥ lebih besar/sama',
        'lt'          => '< lebih kecil',
        'lte'         => '≤ lebih kecil/sama',
        'starts_with' => 'diawali dengan',
        'ends_with'   => 'diakhiri dengan',
        'in'          => 'termasuk dalam (a,b,c)',
        'is_null'     => 'adalah NULL',
        'is_not_null' => 'bukan NULL',
    ];
}
?>

<?php $fkMap = getTableForeignKeys($pdo, $schema, $table); ?>
<!-- Table -->
<div class="table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <?php if ($pkCol): ?>
                <th class="col-chk" style="width:36px">
                    <input type="checkbox" id="chk-all" title="Pilih semua"
                        onchange="toggleSelectAll(this)" style="accent-color:var(--accent)">
                </th>
                <?php endif; ?>
                <?php
                // Kumpulkan kolom yang sudah difilter via column-filter (operator 'in' atau 'eq')
                $colFilteredMap = [];
                foreach ($activeFilters as $f) {
                    if (in_array($f['op'], ['in', 'eq', 'is_null', 'is_not_null'])) {
                        $colFilteredMap[$f['col']] = true;
                    }
                }
                foreach ($columns as $col):
                    $newDir  = ($sortCol === $col['column_name'] && $sortDir === 'ASC') ? 'DESC' : 'ASC';
                    $sortUrl = $baseUrl . '&search=' . urlencode($search) . '&sort=' . urlencode($col['column_name']) . '&dir=' . $newDir . '&per_page=' . $perPage;
                    $isFiltered = isset($colFilteredMap[$col['column_name']]);
                ?>
                <th class="<?= $isFiltered ? 'col-filtered' : '' ?>" data-col="<?= htmlspecialchars($col['column_name']) ?>">
                    <div class="th-inner">
                        <a href="<?= $sortUrl ?>" class="sort-link <?= $sortCol === $col['column_name'] ? 'active' : '' ?>">
                            <?= htmlspecialchars($col['column_name']) ?>
                            <span class="col-type"><?= htmlspecialchars($col['data_type']) ?></span>
                            <?php if ($col['is_primary'] === 'YES'): ?><span class="badge-pk">PK</span><?php endif; ?>
                            <?php if (isset($fkMap[$col['column_name']])): $fk = $fkMap[$col['column_name']]; ?><span class="badge-fk" title="Foreign key → <?= htmlspecialchars($fk['table'] . '.' . $fk['column']) ?>">FK</span><?php endif; ?>
                            <?php if ($sortCol === $col['column_name']): ?>
                            <span class="sort-arrow"><?= $sortDir === 'ASC' ? '↑' : '↓' ?></span>
                            <?php endif; ?>
                        </a>
                        <button type="button"
                            class="col-filter-btn <?= $isFiltered ? 'active' : '' ?>"
                            title="Filter kolom <?= htmlspecialchars($col['column_name']) ?>"
                            onclick="openColFilter(event, '<?= addslashes($col['column_name']) ?>')">
                            <?= $isFiltered ? '▼' : '⌄' ?>
                        </button>
                    </div>
                </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="<?= count($columns) + ($pkCol ? 1 : 0) ?>" class="empty-cell">Tidak ada data</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row):
                $pkVal = $pkCol ? ($row[$pkCol] ?? '') : '';
            ?>
            <tr class="data-row <?= $pkCol ? 'row-clickable' : '' ?>"
                data-pk="<?= htmlspecialchars($pkVal) ?>"
                <?php if ($pkCol): ?>
                data-pk-col="<?= htmlspecialchars($pkCol) ?>"
                data-pk-val="<?= htmlspecialchars($pkVal) ?>"
                data-row='<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>'
                onclick="onRowClick(event, this)"
                <?php endif; ?>>
                <?php if ($pkCol): ?>
                <td class="col-chk" onclick="event.stopPropagation()">
                    <input type="checkbox" class="row-chk" value="<?= htmlspecialchars($pkVal) ?>"
                        onchange="onRowChkChange()" style="accent-color:var(--accent)">
                </td>
                <?php endif; ?>
                <?php foreach ($columns as $col):
                    $val = $row[$col['column_name']] ?? null;
                    $isNull = $val === null;
                    $display = $isNull ? '<span class="null-val">NULL</span>' : htmlspecialchars(mb_strimwidth((string)$val, 0, 200, '…'));
                    $isLong  = !$isNull && (mb_strlen((string)$val) > 80 || preg_match('/^\s*[\[{]/', (string)$val));
                    $fkInfo  = (!$isNull && isset($fkMap[$col['column_name']])) ? $fkMap[$col['column_name']] : null;
                ?>
                <td class="data-cell editable-cell"
                    data-col="<?= htmlspecialchars($col['column_name']) ?>"
                    data-val="<?= htmlspecialchars((string)($val ?? '')) ?>"
                    data-null="<?= $isNull ? '1' : '0' ?>"
                    <?= $isLong ? 'data-long="1"' : '' ?>
                    <?= $fkInfo ? "data-fk='" . htmlspecialchars(json_encode($fkInfo), ENT_QUOTES) . "'" : '' ?>
                    title="Klik untuk edit<?= $fkInfo ? ' · Ctrl+klik: buka baris terkait' : '' ?>"
                    onclick="startCellEdit(event, this)"><?= $display ?></td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<div class="pagination">
    <span class="pagination-info">
        <?= number_format(min(($page_num - 1) * $perPage + 1, $totalRows)) ?>–<?= number_format(min($page_num * $perPage, $totalRows)) ?> dari <?= number_format($totalRows) ?> baris
    </span>
    <div class="pagination-controls">
        <?php
        $pageBase = $baseUrl
            . '&search='   . urlencode($search)
            . '&sort='     . urlencode($sortCol ?? '')
            . '&dir='      . $sortDir
            . '&per_page=' . $perPage
            . $filterQueryStr;
        ?>
        <?php if ($page_num > 1): ?>
        <a href="<?= $pageBase ?>&p=<?= $page_num - 1 ?>" class="btn btn-outline btn-sm">← Prev</a>
        <?php endif; ?>
        <span class="page-info">Halaman <?= $page_num ?> / <?= $totalPages ?></span>
        <?php if ($page_num < $totalPages): ?>
        <a href="<?= $pageBase ?>&p=<?= $page_num + 1 ?>" class="btn btn-outline btn-sm">Next →</a>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Insert -->
<div id="modal-insert" class="modal-overlay" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <h3>Insert Row</h3>
            <button class="modal-close" onclick="closeModal('modal-insert')">×</button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="_action" value="insert">
            <div class="modal-body">
                <?php foreach ($columns as $col): ?>
                <div class="form-group">
                    <label>
                        <?= htmlspecialchars($col['column_name']) ?>
                        <span class="label-type"><?= htmlspecialchars($col['data_type']) ?></span>
                        <?php if ($col['is_primary'] === 'YES'): ?><span class="badge-pk">PK</span><?php endif; ?>
                    </label>
                    <input type="hidden" name="col[]" value="<?= htmlspecialchars($col['column_name']) ?>">
                    <input type="text" name="val[]" placeholder="<?= $col['column_default'] ? htmlspecialchars($col['column_default']) : ($col['is_nullable'] === 'YES' ? 'NULL' : '') ?>">
                </div>
                <?php endforeach; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-insert')">Batal</button>
                <button type="submit" class="btn btn-primary">Insert</button>
            </div>
        </form>
    </div>
</div>

<!-- Inline edit: tidak perlu modal -->

<!-- Modal Struktur -->
<div id="modal-structure" class="modal-overlay" style="display:none">
    <div class="modal modal-wide">
        <div class="modal-header">
            <h3>Struktur Tabel: <?= htmlspecialchars($table) ?></h3>
            <button class="modal-close" onclick="closeModal('modal-structure')">×</button>
        </div>
        <div class="modal-body">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Kolom</th><th>Tipe Data</th><th>Nullable</th><th>Default</th><th>Primary Key</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($columns as $col): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($col['column_name']) ?></strong></td>
                        <td><code><?= htmlspecialchars($col['data_type']) ?></code></td>
                        <td><?= $col['is_nullable'] === 'YES' ? '✓' : '✗' ?></td>
                        <td><?= $col['column_default'] ? htmlspecialchars($col['column_default']) : '<span class="null-val">—</span>' ?></td>
                        <td><?= $col['is_primary'] === 'YES' ? '<span class="badge-pk">PK</span>' : '' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('modal-structure')">Tutup</button>
        </div>
    </div>
</div>

<!-- Modal Truncate -->
<div id="modal-truncate" class="modal-overlay" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <h3>Truncate Tabel</h3>
            <button class="modal-close" onclick="closeModal('modal-truncate')">×</button>
        </div>
        <div class="modal-body">
            <div class="alert alert-error">
                ⚠️ Semua data di tabel <strong><?= htmlspecialchars($table) ?></strong> akan dihapus permanen.<br>
                Sequence/auto-increment akan direset ke awal.
            </div>
            <p style="margin-top:8px;color:var(--text-secondary)">Tindakan ini tidak bisa dibatalkan. Pastikan sudah backup terlebih dahulu.</p>
            <p style="margin-top:12px">Ketik nama tabel untuk konfirmasi:</p>
            <input type="text" id="truncate-confirm-input" placeholder="<?= htmlspecialchars($table) ?>" autocomplete="off">
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('modal-truncate')">Batal</button>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="_action" value="truncate">
                <input type="hidden" name="confirm_name" id="truncate-confirm-name">
                <button type="submit" class="btn btn-danger" id="truncate-submit-btn" disabled
                        onclick="document.getElementById('truncate-confirm-name').value = document.getElementById('truncate-confirm-input').value">Ya, Truncate Sekarang</button>
            </form>
        </div>
    </div>
</div>

<!-- Modal Drop -->
<div id="modal-drop" class="modal-overlay" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <h3>Konfirmasi Hapus Tabel</h3>
            <button class="modal-close" onclick="closeModal('modal-drop')">×</button>
        </div>
        <div class="modal-body">
            <div class="alert alert-error">Tindakan ini akan menghapus tabel <strong><?= htmlspecialchars($table) ?></strong> secara permanen beserta semua datanya!</div>
            <p>Ketik nama tabel untuk konfirmasi:</p>
            <input type="text" id="drop-confirm-input" placeholder="<?= htmlspecialchars($table) ?>">
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('modal-drop')">Batal</button>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="_action" value="drop">
                <button type="submit" class="btn btn-danger" id="drop-submit-btn" disabled onclick="return confirmDrop()">Drop Tabel</button>
            </form>
        </div>
    </div>
</div>

<script>
const TABLE_NAME = '<?= addslashes($table) ?>';
const COLUMNS    = <?= json_encode(array_column($columns, 'column_name')) ?>;
const PK_COL     = '<?= addslashes($pkCol ?? '') ?>';

document.getElementById('truncate-confirm-input')?.addEventListener('input', function() {
    document.getElementById('truncate-submit-btn').disabled = this.value !== TABLE_NAME;
});
document.getElementById('drop-confirm-input')?.addEventListener('input', function() {
    document.getElementById('drop-submit-btn').disabled = this.value !== TABLE_NAME;
});

// ── Checkbox & bulk delete ────────────────────────────────────
function toggleSelectAll(chk) {
    document.querySelectorAll('.row-chk').forEach(c => c.checked = chk.checked);
    onRowChkChange();
}

function onRowChkChange() {
    const checked = document.querySelectorAll('.row-chk:checked');
    const total   = document.querySelectorAll('.row-chk');
    const btn     = document.getElementById('btn-bulk-delete');
    const countEl = document.getElementById('bulk-count');
    const allChk  = document.getElementById('chk-all');

    if (btn)     btn.style.display     = checked.length > 0 ? '' : 'none';
    if (countEl) countEl.textContent   = checked.length;
    if (allChk)  allChk.indeterminate  = checked.length > 0 && checked.length < total.length;
    if (allChk)  allChk.checked        = checked.length > 0 && checked.length === total.length;
}

function bulkDelete() {
    const checked = [...document.querySelectorAll('.row-chk:checked')].map(c => c.value);
    if (!checked.length) return;
    pgmConfirm({ title: 'Hapus Baris', message: `Hapus ${checked.length} baris yang dipilih? Tindakan ini tidak bisa dibatalkan.`,
                 danger: true, confirmText: 'Hapus' }).then(ok => { if (ok) doBulkDelete(checked); });
}

function doBulkDelete(checked) {

    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
        <input type="hidden" name="_action" value="bulk_delete">
        <input type="hidden" name="pk_col"  value="${PK_COL}">
        <input type="hidden" name="pk_vals" value='${JSON.stringify(checked)}'>
    `;
    document.body.appendChild(form);
    form.submit();
}

// ── Tata letak kolom: sembunyikan, lebar, urutan, kunci kolom ──
const LAYOUT_ID  = <?= json_encode($schema . '.' . $table) ?>;
const COLVIS_KEY = 'pgm_hidden_cols:' + LAYOUT_ID;
const LAYOUT_KEY = 'pgm_layout:' + LAYOUT_ID;
const DATA_TABLE = document.querySelector('.data-table');
if (DATA_TABLE) DATA_TABLE.dataset.resizeInited = '1'; // resize global (app.js) diganti manajer ini

let layout = { widths: {}, order: [], freeze: true };
try { Object.assign(layout, JSON.parse(localStorage.getItem(LAYOUT_KEY) || '{}')); } catch (e) {}
const saveLayout = () => { try { localStorage.setItem(LAYOUT_KEY, JSON.stringify(layout)); } catch (e) {} };
const cssEsc = v => String(v).replace(/["\\]/g, '\\$&');

function toggleColVis(e) {
    e.stopPropagation();
    document.getElementById('colvis-menu').classList.toggle('open');
}
document.addEventListener('click', () => document.getElementById('colvis-menu')?.classList.remove('open'));

function hiddenCols() { return [...document.querySelectorAll('.colvis-chk')].filter(c => !c.checked).map(c => c.dataset.col); }
function visibleColsInOrder() {
    const hidden = hiddenCols();
    return [...DATA_TABLE.querySelectorAll('thead th[data-col]')].map(th => th.dataset.col).filter(c => !hidden.includes(c));
}

function applyLayout() {
    const hidden = hiddenCols();
    let style = document.getElementById('layout-style');
    if (!style) { style = document.createElement('style'); style.id = 'layout-style'; document.head.appendChild(style); }
    let css = hidden.map(c => `.data-table [data-col="${cssEsc(c)}"] { display: none; }`).join('\n');
    const vis = visibleColsInOrder();
    const hasW = vis.some(c => layout.widths[c]);
    if (hasW) {
        let total = PK_COL ? 40 : 0;
        vis.forEach(c => {
            const w = layout.widths[c] || 150;
            total += w;
            css += `\n.data-table th[data-col="${cssEsc(c)}"], .data-table td[data-col="${cssEsc(c)}"] { width:${w}px; min-width:${w}px; max-width:${w}px; }`;
        });
        css += `\n.data-table { table-layout: fixed; width: ${total}px; }`;
    }
    style.textContent = css;

    const badge = document.getElementById('colvis-badge');
    badge.style.display = hidden.length ? '' : 'none';
    badge.textContent   = hidden.length;
    applyFreeze();
}

function applyFreeze() {
    DATA_TABLE.querySelectorAll('.frz').forEach(el => { el.classList.remove('frz', 'frz-chk'); el.style.left = ''; });
    const chk = document.getElementById('freeze-chk'); if (chk) chk.checked = !!layout.freeze;
    if (!layout.freeze) return;
    const first = visibleColsInOrder()[0];
    let left = 0;
    if (PK_COL) {
        DATA_TABLE.querySelectorAll('th.col-chk, td.col-chk').forEach(el => { el.classList.add('frz', 'frz-chk'); el.style.left = '0px'; });
        left = DATA_TABLE.querySelector('th.col-chk')?.offsetWidth || 40;
    }
    if (first) DATA_TABLE.querySelectorAll(`[data-col="${cssEsc(first)}"]`).forEach(el => { el.classList.add('frz'); el.style.left = left + 'px'; });
}

function onColVisChange() {
    try { localStorage.setItem(COLVIS_KEY, JSON.stringify(hiddenCols())); } catch (e) {}
    applyLayout();
}
function setAllCols(show) {
    const chks = [...document.querySelectorAll('.colvis-chk')];
    chks.forEach(c => c.checked = show);
    if (!show && chks.length) chks[0].checked = true; // minimal satu kolom tetap tampil
    onColVisChange();
}
function toggleFreeze(on) { layout.freeze = on; saveLayout(); applyFreeze(); }
function resetLayout() {
    layout = { widths: {}, order: [], freeze: true };
    try { localStorage.removeItem(LAYOUT_KEY); localStorage.removeItem(COLVIS_KEY); } catch (e) {}
    location.reload();
}

// Urutan kolom: pindahkan sel di setiap baris sesuai urutan
function reorderDom(order) {
    DATA_TABLE.querySelectorAll('tr').forEach(tr => {
        const cells = [...tr.children].filter(c => c.dataset && c.dataset.col);
        if (!cells.length) return;
        const byCol = Object.fromEntries(cells.map(c => [c.dataset.col, c]));
        order.forEach(c => { if (byCol[c]) tr.appendChild(byCol[c]); });
    });
}

function initColumnHeaders() {
    const ths = DATA_TABLE.querySelectorAll('thead th[data-col]');
    ths.forEach(th => {
        th.draggable = true;
        th.querySelectorAll('a').forEach(a => a.draggable = false);
        const handle = document.createElement('div');
        handle.className = 'col-resizer';
        handle.title = 'Drag untuk mengubah lebar (klik dua kali: reset)';
        th.appendChild(handle);

        handle.addEventListener('dblclick', e => { e.stopPropagation(); delete layout.widths[th.dataset.col]; saveLayout(); applyLayout(); });
        handle.addEventListener('mousedown', e => {
            e.preventDefault(); e.stopPropagation();
            th.draggable = false;
            // Snapshot lebar semua kolom tampil agar layout konsisten
            visibleColsInOrder().forEach(c => {
                if (!layout.widths[c]) layout.widths[c] = Math.max(60, DATA_TABLE.querySelector(`th[data-col="${cssEsc(c)}"]`).offsetWidth);
            });
            const col = th.dataset.col, startX = e.clientX, startW = layout.widths[col];
            handle.classList.add('resizing');
            document.body.style.cursor = 'col-resize'; document.body.style.userSelect = 'none';
            const move = ev => { layout.widths[col] = Math.max(60, startW + ev.clientX - startX); applyLayout(); };
            const up = () => {
                document.removeEventListener('mousemove', move); document.removeEventListener('mouseup', up);
                handle.classList.remove('resizing'); document.body.style.cursor = ''; document.body.style.userSelect = '';
                th.draggable = true; saveLayout();
            };
            document.addEventListener('mousemove', move); document.addEventListener('mouseup', up);
        });

        // Drag & drop urutan kolom
        th.addEventListener('dragstart', e => { window._dragCol = th.dataset.col; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', th.dataset.col); th.classList.add('col-dragging'); });
        th.addEventListener('dragend',   () => { th.classList.remove('col-dragging'); ths.forEach(t => t.classList.remove('col-drop-left', 'col-drop-right')); });
        th.addEventListener('dragover',  e => {
            if (!window._dragCol || window._dragCol === th.dataset.col) return;
            e.preventDefault();
            const left = e.clientX < th.getBoundingClientRect().left + th.offsetWidth / 2;
            ths.forEach(t => t.classList.remove('col-drop-left', 'col-drop-right'));
            th.classList.add(left ? 'col-drop-left' : 'col-drop-right');
        });
        th.addEventListener('drop', e => {
            e.preventDefault();
            const from = window._dragCol, to = th.dataset.col;
            if (!from || from === to) return;
            const before = e.clientX < th.getBoundingClientRect().left + th.offsetWidth / 2;
            const order = [...DATA_TABLE.querySelectorAll('thead th[data-col]')].map(t => t.dataset.col).filter(c => c !== from);
            order.splice(order.indexOf(to) + (before ? 0 : 1), 0, from);
            layout.order = order; saveLayout();
            reorderDom(order); applyLayout();
            window._dragCol = null;
        });
    });
}

(function() {
    if (!DATA_TABLE) return;
    let saved = [];
    try { saved = JSON.parse(localStorage.getItem(COLVIS_KEY) || '[]'); } catch (e) {}
    document.querySelectorAll('.colvis-chk').forEach(c => { if (saved.includes(c.dataset.col)) c.checked = false; });
    // Terapkan urutan tersimpan (abaikan kolom yang sudah tidak ada, tambahkan kolom baru di akhir)
    const existing = [...DATA_TABLE.querySelectorAll('thead th[data-col]')].map(t => t.dataset.col);
    if (layout.order.length) {
        const order = layout.order.filter(c => existing.includes(c)).concat(existing.filter(c => !layout.order.includes(c)));
        reorderDom(order);
    }
    initColumnHeaders();
    applyLayout();
})();

// ── Menu klik kanan, viewer nilai, foreign key ───────────────
const ACTIVE_FILTERS = <?= json_encode($activeFilters) ?>;
const isLongVal = v => v !== null && (String(v).length > 80 || /^\s*[\[{]/.test(String(v)));

function cellValue(td) { return td.dataset.null === '1' ? null : td.dataset.val; }

function gotoWithFilter(col, op, val) {
    const url = new URL(window.location.href);
    ['filter_col[]', 'filter_op[]', 'filter_val[]', 'filter_logic[]', 'p'].forEach(k => url.searchParams.delete(k));
    ACTIVE_FILTERS.concat([{ col, op, val: val ?? '', logic: 'AND' }]).forEach(f => {
        url.searchParams.append('filter_col[]', f.col);
        url.searchParams.append('filter_op[]', f.op);
        url.searchParams.append('filter_val[]', f.val);
        url.searchParams.append('filter_logic[]', f.logic || 'AND');
    });
    window.location.href = url.toString();
}

function gotoFk(td) {
    const fk = JSON.parse(td.dataset.fk);
    const u = new URLSearchParams({ page: 'table-editor', schema: fk.schema, table: fk.table });
    u.append('filter_col[]', fk.column); u.append('filter_op[]', 'eq');
    u.append('filter_val[]', cellValue(td)); u.append('filter_logic[]', 'AND');
    window.location.href = 'app.php?' + u.toString();
}

function viewCellValue(td) {
    const v = cellValue(td);
    let text = v === null ? 'NULL' : String(v);
    let isJson = false;
    try { if (/^\s*[\[{]/.test(text)) { text = JSON.stringify(JSON.parse(text), null, 2); isJson = true; } } catch (e) {}
    pgmViewValue(td.dataset.col + (isJson ? ' (JSON)' : ''), text);
}

let _ctxMenu = null;
function closeCtxMenu() { _ctxMenu?.remove(); _ctxMenu = null; }
document.addEventListener('click', closeCtxMenu);
document.addEventListener('scroll', closeCtxMenu, true);
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeCtxMenu(); });

document.addEventListener('contextmenu', e => {
    const td = e.target.closest('.data-table td.editable-cell');
    if (!td) return;
    e.preventDefault();
    closeCtxMenu();
    const tr  = td.closest('tr');
    const val = cellValue(td);
    const col = td.dataset.col;
    const items = [
        PK_COL && ['✎ Edit cell', () => startCellEdit({ stopPropagation(){} }, td)],
        ['📋 Salin nilai', () => pgmCopy(val === null ? '' : String(val), 'Nilai disalin')],
        tr.dataset.row && ['📋 Salin baris (JSON)', () => pgmCopy(JSON.stringify(JSON.parse(tr.dataset.row), null, 2), 'Baris disalin')],
        ['🔍 Lihat nilai lengkap', () => viewCellValue(td)],
        '-',
        val === null ? ['⊕ Filter: NULL', () => gotoWithFilter(col, 'is_null', '')]
                     : ['⊕ Filter: = nilai ini', () => gotoWithFilter(col, 'eq', val)],
        val === null ? ['⊖ Filter: bukan NULL', () => gotoWithFilter(col, 'is_not_null', '')]
                     : ['⊖ Filter: ≠ nilai ini', () => gotoWithFilter(col, 'neq', val)],
        td.dataset.fk && '-',
        td.dataset.fk && ['➜ Buka baris terkait (' + JSON.parse(td.dataset.fk).table + ')', () => gotoFk(td)],
        PK_COL && val !== null && '-',
        PK_COL && val !== null && ['∅ Set NULL', () => { td.dataset.origHtml = td.innerHTML; _activeCell = td; saveCellEdit(td, tr.dataset.pkVal, col, null, true); }],
    ].filter(Boolean);

    const m = document.createElement('div');
    m.className = 'cell-ctx-menu';
    items.forEach(it => {
        if (it === '-') { const hr = document.createElement('div'); hr.className = 'ctx-sep'; m.appendChild(hr); return; }
        const b = document.createElement('button');
        b.type = 'button'; b.textContent = it[0];
        b.addEventListener('click', ev => { ev.stopPropagation(); closeCtxMenu(); it[1](); });
        m.appendChild(b);
    });
    document.body.appendChild(m);
    const r = m.getBoundingClientRect();
    m.style.left = Math.min(e.clientX, window.innerWidth  - r.width  - 8) + 'px';
    m.style.top  = Math.min(e.clientY, window.innerHeight - r.height - 8) + 'px';
    _ctxMenu = m;
});

// Ikon ⤢ (nilai panjang) & Ctrl+klik FK — ditangani sebelum handler edit cell
document.addEventListener('click', e => {
    const td = e.target.closest?.('.data-table td.editable-cell');
    if (!td || td.classList.contains('cell-editing')) return;
    if (td.dataset.fk && (e.ctrlKey || e.metaKey)) { e.preventDefault(); e.stopPropagation(); gotoFk(td); return; }
    if (td.dataset.long === '1' && e.clientX > td.getBoundingClientRect().right - 26) { e.preventDefault(); e.stopPropagation(); viewCellValue(td); }
}, true);

// ── Inline cell editing ───────────────────────────────────────
const DS_SCHEMA = '<?= addslashes($schema) ?>';
const DS_TABLE  = '<?= addslashes($table) ?>';
let _activeCell = null;

function onRowClick(event, tr) {
    if (event.target.closest('.col-chk') || event.target.closest('a')) return;
    // Klik baris tidak melakukan apa-apa — edit lewat klik cell langsung
}

// Jaga posisi scroll & lebar kolom agar tidak "lompat" saat edit/simpan cell
function keepScroll(fn) {
    const wrap = document.querySelector('.table-wrap');
    const main = document.querySelector('.main-content');
    const pos  = { wl: wrap?.scrollLeft, wt: wrap?.scrollTop, ml: main?.scrollLeft, mt: main?.scrollTop };
    fn();
    if (wrap) { wrap.scrollLeft = pos.wl; wrap.scrollTop = pos.wt; }
    if (main) { main.scrollLeft = pos.ml; main.scrollTop = pos.mt; }
}
function lockCellWidth(td) {
    const w = td.offsetWidth + 'px';
    td.style.width = td.style.minWidth = td.style.maxWidth = w;
}
function unlockCellWidth(td) { td.style.width = td.style.minWidth = td.style.maxWidth = ''; }

function startCellEdit(event, td) {
    event.stopPropagation();
    if (!PK_COL) return;
    if (_activeCell === td) return; // sudah aktif
    if (_activeCell) cancelCellEdit(_activeCell); // tutup cell lain

    const tr      = td.closest('tr');
    const pkVal   = tr.dataset.pkVal;
    const col     = td.dataset.col;
    const origVal = td.dataset.null === '1' ? null : td.dataset.val;
    const dispW   = td.offsetWidth;

    _activeCell = td;
    lockCellWidth(td);
    td.classList.add('cell-editing');

    // Simpan konten asli
    td.dataset.origHtml = td.innerHTML;

    // Buat input
    const inp = document.createElement('input');
    inp.type  = 'text';
    inp.className = 'cell-input';
    inp.value = origVal !== null ? origVal : '';
    inp.placeholder = origVal === null ? 'NULL' : '';

    // Tombol NULL
    const nullBtn = document.createElement('button');
    nullBtn.type = 'button';
    nullBtn.className = 'cell-null-btn';
    nullBtn.textContent = 'NULL';
    nullBtn.title = 'Set nilai menjadi NULL';
    nullBtn.onclick = (e) => { e.stopPropagation(); saveCellEdit(td, pkVal, col, null, true); };

    keepScroll(() => {
        td.innerHTML = '';
        td.appendChild(inp);
        td.appendChild(nullBtn);
        inp.focus({ preventScroll: true });
        inp.select();
    });

    inp.addEventListener('keydown', (e) => {
        if (e.key === 'Enter')  { e.preventDefault(); saveCellEdit(td, pkVal, col, inp.value, false); }
        if (e.key === 'Escape') { e.preventDefault(); cancelCellEdit(td); }
        if (e.key === 'Tab')    { e.preventDefault();
            saveCellEdit(td, pkVal, col, inp.value, false);
            // Pindah ke cell berikutnya
            const cells = [...tr.querySelectorAll('.editable-cell')];
            const idx   = cells.indexOf(td);
            if (cells[idx + 1]) setTimeout(() => startCellEdit({ stopPropagation(){} }, cells[idx + 1]), 50);
        }
    });

    inp.addEventListener('blur', (e) => {
        // Delay agar klik NULL button tidak langsung cancel
        setTimeout(() => {
            if (_activeCell === td && document.activeElement !== inp &&
                !td.querySelector('.cell-null-btn:hover')) {
                saveCellEdit(td, pkVal, col, inp.value, false);
            }
        }, 150);
    });
}

function saveCellEdit(td, pkVal, col, val, setNull) {
    if (_activeCell !== td) return;
    const origVal  = td.dataset.null === '1' ? null : td.dataset.val;
    const origHtml = td.dataset.origHtml;

    // Tidak ada perubahan
    if (!setNull && val === (origVal ?? '')) { cancelCellEdit(td); return; }
    if (setNull && td.dataset.null === '1')  { cancelCellEdit(td); return; }

    _activeCell = null;
    keepScroll(() => {
        td.classList.remove('cell-editing');
        td.classList.add('cell-saving');
        td.innerHTML = '<span style="color:var(--text-muted)">Menyimpan...</span>';
    });

    const body = new FormData();
    body.append('schema',   DS_SCHEMA);
    body.append('table',    DS_TABLE);
    body.append('pk_col',   PK_COL);
    body.append('pk_val',   pkVal);
    body.append('col',      col);
    body.append('val',      val ?? '');
    if (setNull) body.append('set_null', '1');

    body.append('csrf_token', <?= json_encode(csrfToken()) ?>);
    fetch('api/cell_update.php', { method: 'POST', body })
        .then(r => r.json())
        .then(data => {
            td.classList.remove('cell-saving');
            unlockCellWidth(td);
            if (data.ok) {
                const newVal = data.val;
                td.dataset.val  = newVal ?? '';
                td.dataset.null = newVal === null ? '1' : '0';
                if (isLongVal(newVal)) td.dataset.long = '1'; else delete td.dataset.long;
                td.innerHTML    = newVal === null
                    ? '<span class="null-val">NULL</span>'
                    : escHtml(String(newVal).length > 200 ? String(newVal).slice(0,200)+'…' : String(newVal));
                td.classList.add('cell-saved');
                setTimeout(() => td.classList.remove('cell-saved'), 1200);
            } else {
                td.innerHTML = origHtml;
                showCellError(td, data.error || 'Gagal menyimpan');
            }
        })
        .catch(() => {
            td.classList.remove('cell-saving');
            unlockCellWidth(td);
            td.innerHTML = origHtml;
            showCellError(td, 'Koneksi gagal');
        });
}

function cancelCellEdit(td) {
    if (!td) return;
    keepScroll(() => {
        td.classList.remove('cell-editing');
        td.innerHTML = td.dataset.origHtml || td.innerHTML;
    });
    unlockCellWidth(td);
    if (_activeCell === td) _activeCell = null;
}

function showCellError(td, msg) {
    td.classList.add('cell-error-flash');
    setTimeout(() => td.classList.remove('cell-error-flash'), 2000);
    // Tampilkan tooltip error singkat
    const tip = document.createElement('div');
    tip.className = 'cell-error-tip';
    tip.textContent = msg;
    td.appendChild(tip);
    setTimeout(() => tip.remove(), 3000);
}

// Tutup cell aktif saat klik di luar tabel
document.addEventListener('click', (e) => {
    if (_activeCell && !_activeCell.contains(e.target)) {
        const inp = _activeCell.querySelector('.cell-input');
        if (inp) {
            const tr    = _activeCell.closest('tr');
            const pkVal = tr?.dataset.pkVal;
            const col   = _activeCell.dataset.col;
            saveCellEdit(_activeCell, pkVal, col, inp.value, false);
        }
    }
});

// ══════════════════════════════════════════════
// Filter Panel JS
// ══════════════════════════════════════════════
const FILTER_COLS = <?= json_encode($validColNames) ?>;
const FILTER_OPS  = <?= json_encode(getFilterOpLabels()) ?>;
const OPS_NO_VAL  = ['is_null', 'is_not_null'];

function toggleFilterPanel() {
    const panel = document.getElementById('filter-panel');
    const shown = panel.style.display !== 'none';
    panel.style.display = shown ? 'none' : 'block';
}

function addFilterRow() {
    const rows   = document.getElementById('filter-rows');
    const idx    = rows.querySelectorAll('.filter-row').length;
    const div    = document.createElement('div');
    div.className = 'filter-row';
    div.dataset.idx = idx;

    // Kolom select
    let colOpts = FILTER_COLS.map(c => `<option value="${escHtml(c)}">${escHtml(c)}</option>`).join('');
    // Operator select
    let opOpts  = Object.entries(FILTER_OPS).map(([k,v]) => `<option value="${k}">${escHtml(v)}</option>`).join('');

    div.innerHTML = `
        <div class="filter-logic">
            <select name="filter_logic[]" class="filter-logic-sel">
                <option value="AND">AND</option>
                <option value="OR">OR</option>
            </select>
        </div>
        <select name="filter_col[]" class="filter-col-sel" onchange="updateOpOptions(this)">${colOpts}</select>
        <select name="filter_op[]"  class="filter-op-sel" onchange="toggleValueInput(this)">${opOpts}</select>
        <input type="text" name="filter_val[]" class="filter-val-inp" placeholder="nilai...">
        <button type="button" class="btn-icon btn-delete" onclick="removeFilterRow(this)" title="Hapus">✕</button>
    `;
    rows.appendChild(div);
    div.querySelector('.filter-val-inp').focus();
    syncFirstRowLogic();
}

function removeFilterRow(btn) {
    const row = btn.closest('.filter-row');
    row.remove();
    syncFirstRowLogic();
}

function syncFirstRowLogic() {
    // Baris pertama tidak perlu AND/OR — tampilkan "WHERE"
    const rows = document.querySelectorAll('#filter-rows .filter-row');
    rows.forEach((row, i) => {
        const logicDiv = row.querySelector('.filter-logic');
        if (i === 0) {
            logicDiv.innerHTML = '<span class="filter-where-label">WHERE</span>';
            logicDiv.className = 'filter-logic filter-logic-first';
        } else {
            if (!logicDiv.querySelector('select')) {
                logicDiv.innerHTML = `<select name="filter_logic[]" class="filter-logic-sel">
                    <option value="AND">AND</option><option value="OR">OR</option></select>`;
            }
            logicDiv.className = 'filter-logic';
        }
    });
}

function toggleValueInput(sel) {
    const valInp = sel.closest('.filter-row').querySelector('.filter-val-inp');
    if (OPS_NO_VAL.includes(sel.value)) {
        valInp.style.display = 'none';
        valInp.value = '';
    } else {
        valInp.style.display = '';
        valInp.placeholder = sel.value === 'in' ? 'a, b, c' : 'nilai...';
    }
}

function updateOpOptions(sel) {
    // Bisa dikembangkan: tampilkan operator berbeda per tipe kolom
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Init: sembunyikan value input untuk operator tanpa nilai
document.querySelectorAll('.filter-op-sel').forEach(sel => toggleValueInput(sel));

// ── Export tabel ──────────────────────────────────────────────
function exportTableData(fmt) {
    const url = new URL(window.location.href);
    url.searchParams.set('export', fmt);
    window.location.href = url.toString();
}
</script>

<!-- ══════════════════════════════════════════════════════════
     Column Filter Dropdown (Excel-style)
══════════════════════════════════════════════════════════ -->
<div class="col-filter-dropdown" id="col-filter-dropdown">
    <div class="cfd-header">
        <span id="cfd-col-name">Kolom</span>
        <small id="cfd-count" style="color:var(--text-muted)"></small>
    </div>
    <div class="cfd-search">
        <input type="text" id="cfd-search-input" placeholder="Cari nilai..." oninput="cfdSearch(this.value)">
    </div>
    <div class="cfd-list" id="cfd-list">
        <div class="cfd-loading">Memuat...</div>
    </div>
    <div class="cfd-footer">
        <button class="btn btn-outline" onclick="closeCfd()">Batal</button>
        <button class="btn btn-primary" onclick="applyCfd()">Terapkan</button>
    </div>
</div>

<script>
// ══════════════════════════════════════════════
// Column Filter Dropdown
// ══════════════════════════════════════════════
const CFD = {
    el:       document.getElementById('col-filter-dropdown'),
    listEl:   document.getElementById('cfd-list'),
    nameEl:   document.getElementById('cfd-col-name'),
    countEl:  document.getElementById('cfd-count'),
    searchEl: document.getElementById('cfd-search-input'),

    col:      null,
    allItems: [],   // [{val, isNull}]
    schema:   '<?= addslashes($schema) ?>',
    table:    '<?= addslashes($table) ?>',

    // nilai yang sudah aktif dari URL saat ini
    activeVals: <?php
        // Kumpulkan nilai aktif per kolom dari activeFilters
        $colActiveVals = [];
        foreach ($activeFilters as $f) {
            if ($f['op'] === 'in') {
                $colActiveVals[$f['col']] = array_map('trim', explode(',', $f['val']));
            } elseif ($f['op'] === 'eq') {
                $colActiveVals[$f['col']] = [$f['val']];
            } elseif ($f['op'] === 'is_null') {
                $colActiveVals[$f['col']] = ['__NULL__'];
            }
        }
        echo json_encode($colActiveVals);
    ?>,
};

function openColFilter(event, colName) {
    event.stopPropagation();
    event.preventDefault();

    // Toggle — tutup jika sudah buka untuk kolom yang sama
    if (CFD.el.classList.contains('open') && CFD.col === colName) {
        closeCfd(); return;
    }

    CFD.col = colName;
    CFD.nameEl.textContent = colName;
    CFD.countEl.textContent = '';
    CFD.searchEl.value = '';
    CFD.listEl.innerHTML = '<div class="cfd-loading">⏳ Memuat nilai...</div>';
    CFD.allItems = [];

    // Posisikan dropdown di bawah tombol
    const btn    = event.currentTarget;
    const rect   = btn.getBoundingClientRect();
    const dw     = 240;
    let   left   = rect.left;
    if (left + dw > window.innerWidth - 8) left = window.innerWidth - dw - 8;
    CFD.el.style.top  = (rect.bottom + 4) + 'px';
    CFD.el.style.left = left + 'px';
    CFD.el.classList.add('open');

    // Fetch nilai unik
    fetch(`api/col_values.php?schema=${encodeURIComponent(CFD.schema)}&table=${encodeURIComponent(CFD.table)}&col=${encodeURIComponent(colName)}`)
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                CFD.listEl.innerHTML = `<div class="cfd-empty">Error: ${escHtml(data.error)}</div>`;
                return;
            }
            CFD.allItems = [];
            if (data.hasNull) CFD.allItems.push({ val: '__NULL__', label: '(NULL)', isNull: true });
            data.values.forEach(v => CFD.allItems.push({ val: v, label: v, isNull: false }));

            CFD.countEl.textContent = `${CFD.allItems.length} nilai`;
            renderCfdList(CFD.allItems);
            CFD.searchEl.focus();
        })
        .catch(() => {
            CFD.listEl.innerHTML = '<div class="cfd-empty">Gagal memuat data.</div>';
        });
}

function renderCfdList(items) {
    const active = CFD.activeVals[CFD.col] || [];
    const allChecked = items.length > 0 && items.every(it => active.includes(it.val));

    let html = `<div class="cfd-item cfd-item-all" onclick="cfdRowClick(this)">
        <input type="checkbox" id="cfd-all-chk" ${allChecked || active.length === 0 ? 'checked' : ''}
            onclick="event.stopPropagation()" onchange="cfdSyncFromAllChk(this)">
        <span class="cfd-val">Pilih Semua</span>
    </div>`;

    if (items.length === 0) {
        html += '<div class="cfd-empty">Tidak ada nilai</div>';
    } else {
        items.forEach(it => {
            const checked = active.length === 0 || active.includes(it.val) ? 'checked' : '';
            const cls     = it.isNull ? 'cfd-null' : '';
            html += `<div class="cfd-item" onclick="cfdRowClick(this)">
                <input type="checkbox" value="${escHtml(it.val)}" ${checked}
                    onclick="event.stopPropagation()" onchange="cfdSyncAllChk()">
                <span class="cfd-val ${cls}">${escHtml(it.label)}</span>
            </div>`;
        });
    }
    CFD.listEl.innerHTML = html;
}

function cfdSearch(q) {
    q = q.toLowerCase();
    const filtered = q
        ? CFD.allItems.filter(it => it.label.toLowerCase().includes(q))
        : CFD.allItems;
    renderCfdList(filtered);
}

// Klik pada baris (bukan checkbox) → toggle checkbox secara manual
function cfdRowClick(row) {
    const chk = row.querySelector('input[type=checkbox]');
    chk.checked = !chk.checked;
    if (row.classList.contains('cfd-item-all')) {
        cfdSyncFromAllChk(chk);
    } else {
        cfdSyncAllChk();
    }
}

// "Pilih Semua" diubah → sinkron semua item
function cfdSyncFromAllChk(allChk) {
    const items = CFD.listEl.querySelectorAll('.cfd-item:not(.cfd-item-all) input[type=checkbox]');
    items.forEach(c => c.checked = allChk.checked);
}

// Item diubah → update status "Pilih Semua"
function cfdSyncAllChk() {
    const allChk = document.getElementById('cfd-all-chk');
    const items  = CFD.listEl.querySelectorAll('.cfd-item:not(.cfd-item-all) input[type=checkbox]');
    if (allChk) allChk.checked = [...items].every(c => c.checked);
}

function applyCfd() {
    const checks  = CFD.listEl.querySelectorAll('.cfd-item:not(.cfd-item-all) input[type=checkbox]');
    const checked = [...checks].filter(c => c.checked).map(c => c.value);

    // total = semua nilai yang ada (bukan hanya yang tampil setelah pencarian)
    const total = CFD.allItems.length;

    // Bangun URL baru
    const url = new URL(window.location.href);

    const activeFilters = <?= json_encode($activeFilters) ?>;
    const others = activeFilters.filter(f => f.col !== CFD.col);

    // Reset filter params
    url.searchParams.delete('filter_col[]');
    url.searchParams.delete('filter_op[]');
    url.searchParams.delete('filter_val[]');
    url.searchParams.delete('filter_logic[]');
    url.searchParams.delete('p');

    // Pasang kembali filter lain
    others.forEach((f) => {
        url.searchParams.append('filter_col[]', f.col);
        url.searchParams.append('filter_op[]', f.op);
        url.searchParams.append('filter_val[]', f.val);
        url.searchParams.append('filter_logic[]', f.logic);
    });

    // Tambahkan filter kolom ini:
    // - jika ada yang dipilih (walau semua visible terpilih, tapi belum tentu semua nilai terpilih)
    // - bandingkan dengan total SEMUA nilai, bukan hanya yang tampil
    if (checked.length > 0 && checked.length < total) {
        const hasNull = checked.includes('__NULL__');
        const nonNull = checked.filter(v => v !== '__NULL__');

        if (nonNull.length > 0) {
            url.searchParams.append('filter_col[]', CFD.col);
            url.searchParams.append('filter_op[]', 'in');
            url.searchParams.append('filter_val[]', nonNull.join(','));
            url.searchParams.append('filter_logic[]', others.length > 0 ? 'AND' : 'AND');
        }
        if (hasNull) {
            url.searchParams.append('filter_col[]', CFD.col);
            url.searchParams.append('filter_op[]', 'is_null');
            url.searchParams.append('filter_val[]', '');
            url.searchParams.append('filter_logic[]', nonNull.length > 0 ? 'OR' : (others.length > 0 ? 'AND' : 'AND'));
        }
    }
    // Jika semua dipilih atau tidak ada yang dipilih → hapus filter kolom ini

    closeCfd();
    window.location.href = url.toString();
}

function closeCfd() {
    CFD.el.classList.remove('open');
    CFD.col = null;
}

// Tutup dropdown jika klik di luar
document.addEventListener('click', function(e) {
    if (CFD.el.classList.contains('open') && !CFD.el.contains(e.target)) {
        closeCfd();
    }
});
// Tutup jika scroll
// Tutup hanya jika scroll terjadi di LUAR dropdown (misalnya tabel di bawah)
window.addEventListener('scroll', function(e) {
    if (CFD.el.classList.contains('open') && !CFD.el.contains(e.target)) {
        closeCfd();
    }
}, true);
</script>

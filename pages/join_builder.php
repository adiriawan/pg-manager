<?php
// ── Ambil semua tabel di schema aktif ────────────────────────
$allTableCols = [];
try {
    if (isSQLite()) {
        $sqliteTbls = getTablesSQLite($pdo);
        $sqliteFks  = getForeignKeysSQLite($pdo);
        $fkColSet   = [];
        foreach ($sqliteFks as $fk) {
            $fkColSet[$fk['from_table'] . '.' . $fk['from_column']] = true;
        }
        foreach ($sqliteTbls as $tblName) {
            $cols = getColumnsInfo($pdo, 'main', $tblName);
            foreach ($cols as $col) {
                $allTableCols[$tblName][] = [
                    'table_name'  => $tblName,
                    'column_name' => $col['column_name'],
                    'data_type'   => $col['data_type'],
                    'is_primary'  => $col['is_primary'] === 'YES' ? 1 : 0,
                    'is_foreign'  => isset($fkColSet[$tblName . '.' . $col['column_name']]) ? 1 : 0,
                ];
            }
        }
    } elseif (isMySQL()) {
        $stmt = $pdo->prepare("
            SELECT c.table_name, c.column_name, c.data_type,
                   CASE WHEN c.COLUMN_KEY = 'PRI' THEN 1 ELSE 0 END as is_primary,
                   CASE WHEN kcu.COLUMN_NAME IS NOT NULL THEN 1 ELSE 0 END as is_foreign
            FROM information_schema.COLUMNS c
            LEFT JOIN information_schema.KEY_COLUMN_USAGE kcu
                ON kcu.TABLE_SCHEMA = c.TABLE_SCHEMA AND kcu.TABLE_NAME = c.TABLE_NAME
                AND kcu.COLUMN_NAME = c.COLUMN_NAME AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            WHERE c.TABLE_SCHEMA = ?
            ORDER BY c.table_name, c.ordinal_position
        ");
        $stmt->execute([$schema]);
        foreach ($stmt->fetchAll() as $row) {
            $allTableCols[$row['table_name']][] = $row;
        }
    } else {
        $stmt = $pdo->prepare("
            SELECT c.table_name, c.column_name, c.data_type,
                   CASE WHEN pk.column_name IS NOT NULL THEN true ELSE false END as is_primary,
                   CASE WHEN fk.column_name IS NOT NULL THEN true ELSE false END as is_foreign
            FROM information_schema.columns c
            LEFT JOIN (
                SELECT ku.table_name, ku.column_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage ku
                    ON tc.constraint_name = ku.constraint_name AND tc.table_schema = ku.table_schema
                WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = ?
            ) pk ON c.table_name = pk.table_name AND c.column_name = pk.column_name
            LEFT JOIN (
                SELECT kcu.table_name, kcu.column_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                    ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
                WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = ?
            ) fk ON c.table_name = fk.table_name AND c.column_name = fk.column_name
            WHERE c.table_schema = ?
            ORDER BY c.table_name, c.ordinal_position
        ");
        $stmt->execute([$schema, $schema, $schema]);
        foreach ($stmt->fetchAll() as $row) {
            $allTableCols[$row['table_name']][] = $row;
        }
    }
} catch (Exception $e) {}

// ── Ambil FK otomatis untuk saran relasi ─────────────────────
$fkSuggestions = [];
try {
    if (isSQLite()) {
        $rawFks = getForeignKeysSQLite($pdo);
        foreach ($rawFks as $fk) {
            $fkSuggestions[] = [
                'from_table' => $fk['from_table'],
                'from_col'   => $fk['from_column'],
                'to_table'   => $fk['to_table'],
                'to_col'     => $fk['to_column'],
            ];
        }
    } elseif (isMySQL()) {
        $stmt = $pdo->prepare("
            SELECT kcu.TABLE_NAME as from_table, kcu.COLUMN_NAME as from_col,
                   kcu.REFERENCED_TABLE_NAME as to_table, kcu.REFERENCED_COLUMN_NAME as to_col
            FROM information_schema.KEY_COLUMN_USAGE kcu
            WHERE kcu.TABLE_SCHEMA = ? AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
        ");
        $stmt->execute([$schema]);
        $fkSuggestions = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("
            SELECT kcu.table_name as from_table, kcu.column_name as from_col,
                   ccu.table_name as to_table,   ccu.column_name as to_col
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage ccu
                ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = ?
        ");
        $stmt->execute([$schema]);
        $fkSuggestions = $stmt->fetchAll();
    }
} catch (Exception $e) {}

// ── Eksekusi query JOIN ───────────────────────────────────────
$result      = null;
$resultCols  = [];
$rowCount    = null;
$execTime    = null;
$queryError  = null;
$builtSql    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_join'])) {
    // Kumpulkan data dari POST
    $joinTables   = $_POST['join_table']   ?? [];   // ['tabel1','tabel2',...]
    $joinTypes    = $_POST['join_type']    ?? [];   // ['INNER','LEFT',...]
    $joinOnLeft   = $_POST['join_on_left'] ?? [];   // ['t1.col','...']
    $joinOnRight  = $_POST['join_on_right']?? [];   // ['t2.col','...']
    $selectCols   = $_POST['select_col']   ?? [];   // ['t1.id AS t1_id',...]
    $whereCols    = $_POST['where_col']    ?? [];
    $whereOps     = $_POST['where_op']     ?? [];
    $whereVals    = $_POST['where_val']    ?? [];
    $whereLogics  = $_POST['where_logic']  ?? [];
    $limitRows    = min(5000, max(1, (int)($_POST['limit_rows'] ?? 500)));
    $orderByCol   = $_POST['order_by']     ?? '';
    $orderDir     = strtoupper($_POST['order_dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

    // Alias tabel: t1, t2, t3, ...
    $tableAliases = [];
    foreach ($joinTables as $i => $t) {
        $tableAliases[$t] = 't' . ($i + 1);
    }

    // BUILD SELECT
    $selectParts = [];
    if (!empty($selectCols)) {
        foreach ($selectCols as $sc) {
            // format: "tablename.colname"
            [$tname, $cname] = explode('.', $sc, 2);
            $alias = $tableAliases[$tname] ?? 't1';
            $selectParts[] = "$alias." . qi($cname) . " AS " . qi("{$alias}_{$cname}");
        }
    } else {
        // Pilih semua kolom
        foreach ($tableAliases as $tname => $alias) {
            $selectParts[] = "$alias.*";
        }
    }

    // BUILD FROM + JOIN
    $firstTable = array_shift($joinTables);
    $firstAlias = $tableAliases[$firstTable];
    $fromClause = qualifiedTable($schema, $firstTable) . " AS $firstAlias";

    $joinClauses = [];
    foreach ($joinTables as $i => $t) {
        $alias    = $tableAliases[$t];
        $jtype    = in_array($joinTypes[$i] ?? '', ['INNER','LEFT','RIGHT','FULL']) ? ($joinTypes[$i]) : 'INNER';
        $onLeft   = $joinOnLeft[$i]  ?? '';
        $onRight  = $joinOnRight[$i] ?? '';

        if ($onLeft && $onRight) {
            [$lt, $lc] = explode('.', $onLeft,  2);
            [$rt, $rc] = explode('.', $onRight, 2);
            $la = $tableAliases[$lt] ?? $lt;
            $ra = $tableAliases[$rt] ?? $rt;
            $on = "$la." . qi($lc) . " = $ra." . qi($rc);
        } else {
            $on = '1=1';
        }
        $joinClauses[] = "$jtype JOIN " . qualifiedTable($schema, $t) . " AS $alias ON $on";
    }
    // Kembalikan firstTable ke array
    array_unshift($joinTables, $firstTable);

    // BUILD WHERE
    $whereParts  = [];
    $whereParams = [];
    $noValOps    = ['IS NULL', 'IS NOT NULL'];
    $likeSql    = isMySQL() ? 'LIKE ?' : 'ILIKE ?';
    $notLikeSql = isMySQL() ? 'NOT LIKE ?' : 'NOT ILIKE ?';
    $opMap = [
        'eq'          => '= ?',         'neq'        => '!= ?',
        'ilike'       => $likeSql,       'not_ilike'  => $notLikeSql,
        'gt'          => '> ?',          'gte'        => '>= ?',
        'lt'          => '< ?',          'lte'        => '<= ?',
        'is_null'     => 'IS NULL',      'is_not_null' => 'IS NOT NULL',
        'starts_with' => $likeSql,       'ends_with'  => $likeSql,
    ];
    foreach ($whereCols as $i => $wc) {
        if (!$wc) continue;
        [$wt, $wcol] = explode('.', $wc, 2);
        $wa    = $tableAliases[$wt] ?? 't1';
        $wop   = $whereOps[$i]    ?? 'ilike';
        $wval  = $whereVals[$i]   ?? '';
        $wlog  = strtoupper($whereLogics[$i] ?? 'AND');
        $wlog  = in_array($wlog, ['AND','OR']) ? $wlog : 'AND';
        $sqlOp = $opMap[$wop] ?? $likeSql;

        $colExpr = castText("$wa." . qi($wcol));
        $expr    = "$colExpr $sqlOp";
        if (strpos($sqlOp, '?') !== false) {
            $pv = $wval;
            if ($wop === 'ilike' || $wop === 'not_ilike') $pv = "%$wval%";
            elseif ($wop === 'starts_with') $pv = "$wval%";
            elseif ($wop === 'ends_with')   $pv = "%$wval";
            $whereParams[] = $pv;
        }
        if ($i === 0) $whereParts[] = $expr;
        else          $whereParts[] = "$wlog $expr";
    }

    // ORDER BY
    $orderClause = '';
    if ($orderByCol) {
        [$ot, $oc] = explode('.', $orderByCol, 2);
        $oa = $tableAliases[$ot] ?? 't1';
        $orderClause = "ORDER BY $oa.\"$oc\" $orderDir";
    }

    // GABUNG SQL
    $builtSql = "SELECT " . implode(",\n       ", $selectParts)
              . "\nFROM $fromClause"
              . ($joinClauses ? "\n" . implode("\n", $joinClauses) : '')
              . ($whereParts  ? "\nWHERE " . implode(' ', $whereParts) : '')
              . ($orderClause ? "\n$orderClause" : '')
              . "\nLIMIT $limitRows";

    // EKSEKUSI
    $start = microtime(true);
    try {
        $stmt = $pdo->prepare($builtSql);
        $stmt->execute($whereParams);
        $result     = $stmt->fetchAll();
        $resultCols = $result ? array_keys($result[0]) : [];
        $rowCount   = count($result);
        $execTime   = round((microtime(true) - $start) * 1000, 2);
    } catch (Exception $e) {
        $queryError = $e->getMessage();
        $execTime   = round((microtime(true) - $start) * 1000, 2);
    }
}

// State dari POST (untuk re-render form)
$postTables    = $_POST['join_table']    ?? (count($tables) >= 2 ? [$tables[0], $tables[1]] : ($tables ? [$tables[0]] : []));
$postJoinTypes = $_POST['join_type']     ?? ['INNER'];
$postOnLeft    = $_POST['join_on_left']  ?? [];
$postOnRight   = $_POST['join_on_right'] ?? [];
$postSelCols   = $_POST['select_col']    ?? [];
$postWhereCols = $_POST['where_col']     ?? [];
$postWhereOps  = $_POST['where_op']      ?? [];
$postWhereVals = $_POST['where_val']     ?? [];
$postWhereLogs = $_POST['where_logic']   ?? [];
$postLimit     = (int)($_POST['limit_rows'] ?? 500);
$postOrder     = $_POST['order_by']      ?? '';
$postOrderDir  = $_POST['order_dir']     ?? 'ASC';

// Saran FK otomatis: buat map [from_table.from_col] => [to_table, to_col]
$fkMap = [];
foreach ($fkSuggestions as $fk) {
    $fkMap[$fk['from_table'] . '.' . $fk['from_col']] = $fk;
}

function shortType2(string $t): string {
    $m = ['character varying'=>'varchar','timestamp without time zone'=>'timestamp',
          'timestamp with time zone'=>'timestamptz','double precision'=>'float8',
          'integer'=>'int4','bigint'=>'int8','boolean'=>'bool'];
    return $m[$t] ?? $t;
}
?>

<div class="page-header">
    <div>
        <h1>Join Builder</h1>
        <p class="page-sub">Gabungkan beberapa tabel secara visual — schema: <strong><?= htmlspecialchars($schema) ?></strong></p>
    </div>
    <?php if ($builtSql): ?>
    <div class="header-actions">
        <button class="btn btn-outline btn-sm" onclick="openModal('modal-view-sql')">Lihat SQL</button>
        <?php if ($result && count($result)): ?>
        <button class="btn btn-outline btn-sm" onclick="exportJoinCsv()">⬇ Export CSV</button>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<form method="POST" id="join-form">
    <input type="hidden" name="run_join" value="1">

    <div class="jb-layout">

        <!-- ══════════════ PANEL KIRI: Builder ══════════════ -->
        <div class="jb-panel">

            <!-- 1. Pilih Tabel -->
            <div class="jb-section">
                <div class="jb-section-header">
                    <span class="jb-section-num">1</span>
                    <span>Tabel yang Digabungkan</span>
                    <button type="button" class="btn btn-outline btn-sm" onclick="addJoinRow()">+ Tambah JOIN</button>
                </div>

                <div id="join-rows">
                    <?php foreach ($postTables as $i => $pt): ?>
                    <div class="join-row" data-idx="<?= $i ?>">
                        <?php if ($i === 0): ?>
                        <!-- Tabel pertama -->
                        <div class="join-row-label">FROM</div>
                        <select name="join_table[]" class="join-table-sel" onchange="onTableChange(this)">
                            <?php foreach ($tables as $t): ?>
                            <option value="<?= htmlspecialchars($t) ?>" <?= $t === $pt ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="join-alias-tag">alias: t1</div>
                        <?php else: ?>
                        <!-- Tabel JOIN berikutnya -->
                        <select name="join_type[]" class="join-type-sel">
                            <?php foreach (['INNER'=>'INNER JOIN','LEFT'=>'LEFT JOIN','RIGHT'=>'RIGHT JOIN','FULL'=>'FULL JOIN'] as $k=>$v): ?>
                            <option value="<?= $k ?>" <?= ($postJoinTypes[$i-1] ?? 'INNER') === $k ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="join_table[]" class="join-table-sel" onchange="onTableChange(this)">
                            <?php foreach ($tables as $t): ?>
                            <option value="<?= htmlspecialchars($t) ?>" <?= $t === $pt ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="join-alias-tag">alias: t<?= $i+1 ?></div>
                        <button type="button" class="btn-icon btn-delete" onclick="removeJoinRow(this)">✕</button>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 2. Kondisi ON -->
            <div class="jb-section">
                <div class="jb-section-header">
                    <span class="jb-section-num">2</span>
                    <span>Kondisi ON (Penghubung)</span>
                    <button type="button" class="btn btn-outline btn-sm" onclick="addOnRow()">+ Tambah ON</button>
                </div>
                <div id="on-rows">
                    <?php
                    $onCount = max(count($postTables) - 1, count($postOnLeft));
                    for ($i = 0; $i < $onCount; $i++):
                        $ol = $postOnLeft[$i]  ?? '';
                        $or_ = $postOnRight[$i] ?? '';
                    ?>
                    <div class="on-row">
                        <select name="join_on_left[]" class="on-col-sel">
                            <option value="">— pilih kolom —</option>
                            <?php foreach ($allTableCols as $tname => $tcols): ?>
                            <optgroup label="<?= htmlspecialchars($tname) ?>">
                                <?php foreach ($tcols as $col): ?>
                                <option value="<?= htmlspecialchars($tname.'.'.$col['column_name']) ?>"
                                    <?= $ol === $tname.'.'.$col['column_name'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($col['column_name']) ?>
                                    <? if ($col['is_primary']) echo ' 🔑'; ?>
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <span class="on-eq">=</span>
                        <select name="join_on_right[]" class="on-col-sel">
                            <option value="">— pilih kolom —</option>
                            <?php foreach ($allTableCols as $tname => $tcols): ?>
                            <optgroup label="<?= htmlspecialchars($tname) ?>">
                                <?php foreach ($tcols as $col): ?>
                                <option value="<?= htmlspecialchars($tname.'.'.$col['column_name']) ?>"
                                    <?= $or_ === $tname.'.'.$col['column_name'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($col['column_name']) ?>
                                    <? if ($col['is_primary']) echo ' 🔑'; ?>
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn-icon btn-delete" onclick="this.closest('.on-row').remove()">✕</button>
                    </div>
                    <?php endfor; ?>
                </div>

                <!-- Saran FK otomatis -->
                <?php if ($fkSuggestions): ?>
                <div class="fk-suggestions">
                    <div class="fk-suggestions-label">💡 Saran relasi dari Foreign Key:</div>
                    <?php foreach ($fkSuggestions as $fk): ?>
                    <button type="button" class="fk-chip"
                        data-left="<?= htmlspecialchars($fk['from_table'].'.'.$fk['from_col']) ?>"
                        data-right="<?= htmlspecialchars($fk['to_table'].'.'.$fk['to_col']) ?>"
                        onclick="applyFkSuggestion(this)">
                        <?= htmlspecialchars($fk['from_table']) ?>.<em><?= htmlspecialchars($fk['from_col']) ?></em>
                        → <?= htmlspecialchars($fk['to_table']) ?>.<em><?= htmlspecialchars($fk['to_col']) ?></em>
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- 3. Pilih Kolom Output -->
            <div class="jb-section">
                <div class="jb-section-header">
                    <span class="jb-section-num">3</span>
                    <span>Kolom yang Ditampilkan</span>
                    <div style="display:flex;gap:6px">
                        <button type="button" class="btn btn-outline btn-sm" onclick="selectAllCols()">Semua</button>
                        <button type="button" class="btn btn-outline btn-sm" onclick="clearAllCols()">Kosongkan</button>
                    </div>
                </div>
                <div class="col-picker" id="col-picker">
                    <?php foreach ($allTableCols as $tname => $tcols): ?>
                    <div class="col-picker-group">
                        <div class="col-picker-table"><?= htmlspecialchars($tname) ?></div>
                        <?php foreach ($tcols as $col):
                            $key = $tname . '.' . $col['column_name'];
                            $checked = empty($postSelCols) || in_array($key, $postSelCols);
                        ?>
                        <label class="col-picker-item <?= $col['is_primary'] ? 'is-pk' : ($col['is_foreign'] ? 'is-fk' : '') ?>">
                            <input type="checkbox" name="select_col[]" value="<?= htmlspecialchars($key) ?>"
                                <?= $checked ? 'checked' : '' ?>>
                            <span class="col-picker-name"><?= htmlspecialchars($col['column_name']) ?></span>
                            <span class="col-picker-type"><?= shortType2($col['data_type']) ?></span>
                            <?php if ($col['is_primary']): ?><span class="badge-pk">PK</span><?php endif; ?>
                            <?php if ($col['is_foreign']): ?><span class="badge-fk">FK</span><?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 4. Filter WHERE -->
            <div class="jb-section">
                <div class="jb-section-header">
                    <span class="jb-section-num">4</span>
                    <span>Filter WHERE (opsional)</span>
                    <button type="button" class="btn btn-outline btn-sm" onclick="addWhereRow()">+ Tambah Kondisi</button>
                </div>
                <div id="where-rows">
                    <?php foreach ($postWhereCols as $i => $wc): ?>
                    <div class="filter-row">
                        <div class="filter-logic <?= $i === 0 ? 'filter-logic-first' : '' ?>">
                            <?php if ($i === 0): ?>
                            <span class="filter-where-label">WHERE</span>
                            <?php else: ?>
                            <select name="where_logic[]" class="filter-logic-sel">
                                <option value="AND" <?= ($postWhereLogs[$i] ?? 'AND') === 'AND' ? 'selected' : '' ?>>AND</option>
                                <option value="OR"  <?= ($postWhereLogs[$i] ?? 'AND') === 'OR'  ? 'selected' : '' ?>>OR</option>
                            </select>
                            <?php endif; ?>
                        </div>
                        <select name="where_col[]" class="filter-col-sel">
                            <option value="">— pilih kolom —</option>
                            <?php foreach ($allTableCols as $tname => $tcols): ?>
                            <optgroup label="<?= htmlspecialchars($tname) ?>">
                                <?php foreach ($tcols as $col): ?>
                                <option value="<?= htmlspecialchars($tname.'.'.$col['column_name']) ?>"
                                    <?= $wc === $tname.'.'.$col['column_name'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($col['column_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <select name="where_op[]" class="filter-op-sel" onchange="toggleJoinWhereVal(this)">
                            <?php foreach ([
                                'ilike'=>'mengandung','not_ilike'=>'tidak mengandung',
                                'eq'=>'= sama dengan','neq'=>'≠ tidak sama',
                                'gt'=>'> lebih besar','gte'=>'>= lebih besar/sama',
                                'lt'=>'< lebih kecil','lte'=>'<= lebih kecil/sama',
                                'starts_with'=>'diawali','ends_with'=>'diakhiri',
                                'is_null'=>'adalah NULL','is_not_null'=>'bukan NULL',
                            ] as $k => $v): ?>
                            <option value="<?= $k ?>" <?= ($postWhereOps[$i] ?? 'ilike') === $k ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="where_val[]" class="filter-val-inp"
                               value="<?= htmlspecialchars($postWhereVals[$i] ?? '') ?>" placeholder="nilai...">
                        <button type="button" class="btn-icon btn-delete" onclick="removeWhereRow(this)">✕</button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 5. Opsi Lainnya -->
            <div class="jb-section">
                <div class="jb-section-header">
                    <span class="jb-section-num">5</span>
                    <span>Opsi Lainnya</span>
                </div>
                <div class="jb-options-grid">
                    <div class="form-group">
                        <label>Urut Berdasarkan</label>
                        <select name="order_by">
                            <option value="">— tanpa urutan —</option>
                            <?php foreach ($allTableCols as $tname => $tcols): ?>
                            <optgroup label="<?= htmlspecialchars($tname) ?>">
                                <?php foreach ($tcols as $col): ?>
                                <option value="<?= htmlspecialchars($tname.'.'.$col['column_name']) ?>"
                                    <?= $postOrder === $tname.'.'.$col['column_name'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($col['column_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Arah Urutan</label>
                        <select name="order_dir">
                            <option value="ASC"  <?= $postOrderDir === 'ASC'  ? 'selected' : '' ?>>ASC (A→Z, 0→9)</option>
                            <option value="DESC" <?= $postOrderDir === 'DESC' ? 'selected' : '' ?>>DESC (Z→A, 9→0)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Batas Baris (LIMIT)</label>
                        <select name="limit_rows">
                            <?php foreach ([100, 250, 500, 1000, 2000, 5000] as $n): ?>
                            <option value="<?= $n ?>" <?= $postLimit === $n ? 'selected' : '' ?>><?= number_format($n) ?> baris</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Tombol Jalankan -->
            <div class="jb-run-bar">
                <button type="submit" class="btn btn-primary">
                    ▶ Jalankan JOIN
                </button>
                <?php if ($builtSql): ?>
                <span class="jb-run-info">
                    <?php if ($queryError): ?>
                    <span style="color:var(--danger)">✗ Error (<?= $execTime ?>ms)</span>
                    <?php else: ?>
                    <span style="color:var(--accent)">✓ <?= number_format($rowCount) ?> baris (<?= $execTime ?>ms)</span>
                    <?php endif; ?>
                </span>
                <?php endif; ?>
            </div>

        </div><!-- /jb-panel -->

        <!-- ══════════════ PANEL KANAN: Hasil ══════════════ -->
        <div class="jb-result">

            <?php if (!$builtSql): ?>
            <div class="empty-state" style="height:300px">
                <svg width="44" height="44" fill="none" viewBox="0 0 44 44">
                    <rect x="2" y="8" width="18" height="28" rx="3" stroke="#3ECF8E" stroke-width="1.5"/>
                    <rect x="24" y="8" width="18" height="28" rx="3" stroke="#3ECF8E" stroke-width="1.5"/>
                    <path d="M20 22h4" stroke="#3ECF8E" stroke-width="2" stroke-linecap="round"/>
                    <path d="M18 18l-4 4 4 4M26 18l4 4-4 4" stroke="#3ECF8E" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <h3>Belum ada hasil</h3>
                <p>Pilih tabel &amp; kondisi JOIN di sebelah kiri,<br>lalu klik <strong>Jalankan JOIN</strong>.</p>
            </div>

            <?php elseif ($queryError): ?>
            <div class="sql-result-header error">
                <span class="result-icon">✗</span> Error (<?= $execTime ?>ms)
            </div>
            <div class="alert alert-error" style="font-family:var(--mono);font-size:12px"><?= htmlspecialchars($queryError) ?></div>

            <?php else: ?>
            <div class="jb-result-header">
                <span class="result-badge">✓ <?= number_format($rowCount) ?> baris &middot; <?= $execTime ?>ms</span>
                <span><?= count($resultCols) ?> kolom</span>
            </div>
            <div class="table-wrap" style="max-height:65vh">
                <table class="data-table" id="join-result-table">
                    <thead>
                        <tr>
                            <?php foreach ($resultCols as $col): ?>
                            <th title="<?= htmlspecialchars($col) ?>"><?= htmlspecialchars($col) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($result as $row): ?>
                        <tr>
                            <?php foreach ($row as $val): ?>
                            <td class="data-cell">
                                <?= $val === null
                                    ? '<span class="null-val">NULL</span>'
                                    : htmlspecialchars(mb_strimwidth((string)$val, 0, 120, '…')) ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        </div><!-- /jb-result -->
    </div><!-- /jb-layout -->
</form>

<!-- Modal: Lihat SQL -->
<div id="modal-view-sql" class="modal-overlay" style="display:none">
    <div class="modal modal-wide">
        <div class="modal-header">
            <h3>Query SQL yang Dihasilkan</h3>
            <button class="modal-close" onclick="closeModal('modal-view-sql')">×</button>
        </div>
        <div class="modal-body">
            <div class="code-block">
                <div class="code-header">
                    SQL
                    <button type="button" class="btn btn-outline btn-sm" style="float:right;margin-top:-2px"
                        onclick="copySQL()">📋 Salin</button>
                </div>
                <pre id="sql-preview"><?= htmlspecialchars($builtSql) ?></pre>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('modal-view-sql')">Tutup</button>
            <a href="app.php?page=sql-editor&schema=<?= urlencode($schema) ?>"
               class="btn btn-primary"
               onclick="sessionStorage.setItem('paste_sql', document.getElementById('sql-preview').textContent)">
                Buka di SQL Editor
            </a>
        </div>
    </div>
</div>

<style>
/* ── JOIN Builder Layout ─────────────────────────────────── */
.jb-layout {
    display: grid;
    grid-template-columns: 420px 1fr;
    gap: 16px;
    align-items: flex-start;
}
@media (max-width: 1100px) { .jb-layout { grid-template-columns: 1fr; } }

.jb-panel {
    display: flex; flex-direction: column; gap: 10px;
}
.jb-section {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
}
.jb-section-header {
    display: flex; align-items: center; gap: 8px;
    padding: 9px 14px;
    background: var(--bg-sidebar);
    border-bottom: 1px solid var(--border);
    font-size: 13px; font-weight: 600;
}
.jb-section-num {
    width: 20px; height: 20px; border-radius: 50%;
    background: var(--accent); color: #000;
    font-size: 11px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.jb-section-header .btn { margin-left: auto; }

/* JOIN rows */
.join-row {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 12px;
    border-bottom: 1px solid var(--border);
}
.join-row:last-child { border-bottom: none; }
.join-row-label {
    font-size: 11px; font-weight: 700; color: var(--text-muted);
    text-transform: uppercase; letter-spacing: .06em;
    flex: 0 0 40px;
}
.join-type-sel { flex: 0 0 120px; font-size: 12px !important; padding: 5px 6px !important; }
.join-table-sel { flex: 1; font-size: 12px !important; padding: 5px 8px !important; font-family: var(--mono) !important; }
.join-alias-tag {
    font-size: 11px; color: var(--accent); background: var(--accent-dim);
    padding: 2px 7px; border-radius: 10px; white-space: nowrap;
}

/* ON rows */
.on-row {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 12px; border-bottom: 1px solid var(--border);
}
.on-row:last-child { border-bottom: none; }
.on-col-sel { flex: 1; font-size: 12px !important; padding: 5px 8px !important; font-family: var(--mono) !important; }
.on-eq { flex: 0 0 16px; text-align: center; color: var(--accent); font-weight: 700; }

/* FK suggestions */
.fk-suggestions {
    padding: 8px 12px;
    border-top: 1px solid var(--border);
    display: flex; flex-wrap: wrap; gap: 6px; align-items: center;
}
.fk-suggestions-label { font-size: 11px; color: var(--text-muted); width: 100%; }
.fk-chip {
    font-size: 11px; padding: 3px 10px;
    background: var(--bg-input); border: 1px solid var(--border);
    border-radius: 20px; cursor: pointer; color: var(--text-secondary);
    transition: all .1s; font-family: var(--mono);
}
.fk-chip:hover { border-color: var(--accent); color: var(--accent); background: var(--accent-dim); }
.fk-chip em { font-style: normal; color: var(--accent); }

/* Column picker */
.col-picker {
    max-height: 220px; overflow-y: auto;
    padding: 8px 12px;
    display: flex; flex-direction: column; gap: 10px;
}
.col-picker::-webkit-scrollbar { width: 4px; }
.col-picker::-webkit-scrollbar-thumb { background: var(--border-light); border-radius: 2px; }
.col-picker-group {}
.col-picker-table {
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .07em; color: var(--accent);
    margin-bottom: 4px; padding-bottom: 3px;
    border-bottom: 1px solid var(--accent-dim);
}
.col-picker-item {
    display: flex; align-items: center; gap: 6px;
    padding: 3px 4px; border-radius: 3px; cursor: pointer;
    font-size: 12px; transition: background .1s;
}
.col-picker-item:hover { background: var(--bg-hover); }
.col-picker-item input { margin: 0; flex-shrink: 0; }
.col-picker-name { flex: 1; font-family: var(--mono); }
.col-picker-type { font-size: 10px; color: var(--text-muted); }
.col-picker-item.is-pk .col-picker-name { color: #f6e05e; }
.col-picker-item.is-fk .col-picker-name { color: #90cdf4; }
.badge-fk {
    font-size: 9px; font-weight: 700; padding: 1px 4px;
    background: #1a2d3d; color: #90cdf4;
    border-radius: 3px; letter-spacing: .05em;
}

/* Opsi grid */
.jb-options-grid {
    display: grid; grid-template-columns: 1fr 1fr 1fr;
    gap: 10px; padding: 12px;
}
.jb-options-grid .form-group { margin: 0; }

/* Run bar */
.jb-run-bar {
    display: flex; align-items: center; gap: 12px;
    padding: 4px 0;
}
.jb-run-info { font-size: 12px; }

/* Result panel */
.jb-result {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    min-height: 200px;
}
.jb-result-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 8px 14px;
    background: var(--accent-dim);
    border-bottom: 1px solid var(--border);
    font-size: 12px; color: var(--text-secondary);
}
.result-badge { color: var(--accent); font-weight: 600; }

/* Code block (reuse) */
.code-block { background: var(--bg-sidebar); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; margin: 0; }
.code-header { padding: 6px 12px; font-size: 11px; color: var(--text-muted); border-bottom: 1px solid var(--border); background: var(--bg-card); }
.code-block pre { margin:0; padding:12px 14px; font-family:var(--mono); font-size:12px; color:var(--text-primary); overflow-x:auto; line-height:1.6; white-space:pre-wrap; }
</style>

<script>
const ALL_TABLE_COLS = <?= json_encode($allTableCols, JSON_UNESCAPED_UNICODE) ?>;
const SCHEMA_TABLES  = <?= json_encode($tables,       JSON_UNESCAPED_UNICODE) ?>;
let joinCount = <?= count($postTables) ?>;

// ── Tipe JOIN & alias tag ─────────────────────────────────
function rebuildAliases() {
    document.querySelectorAll('#join-rows .join-row').forEach((row, i) => {
        const tag = row.querySelector('.join-alias-tag');
        if (tag) tag.textContent = `alias: t${i+1}`;
        row.dataset.idx = i;
    });
    joinCount = document.querySelectorAll('#join-rows .join-row').length;
}

function addJoinRow() {
    const container = document.getElementById('join-rows');
    const idx = container.querySelectorAll('.join-row').length;
    const tableOpts = SCHEMA_TABLES.map(t =>
        `<option value="${esc(t)}">${esc(t)}</option>`).join('');
    const div = document.createElement('div');
    div.className = 'join-row';
    div.dataset.idx = idx;
    div.innerHTML = `
        <select name="join_type[]" class="join-type-sel">
            <option value="INNER">INNER JOIN</option>
            <option value="LEFT">LEFT JOIN</option>
            <option value="RIGHT">RIGHT JOIN</option>
            <option value="FULL">FULL JOIN</option>
        </select>
        <select name="join_table[]" class="join-table-sel" onchange="onTableChange(this)">${tableOpts}</select>
        <div class="join-alias-tag">alias: t${idx+1}</div>
        <button type="button" class="btn-icon btn-delete" onclick="removeJoinRow(this)">✕</button>`;
    container.appendChild(div);
    rebuildAliases();
    addOnRow();
}

function removeJoinRow(btn) {
    const rows = document.querySelectorAll('#join-rows .join-row');
    if (rows.length <= 1) return;
    btn.closest('.join-row').remove();
    rebuildAliases();
}

function onTableChange(sel) { rebuildAliases(); }

// ── ON rows ──────────────────────────────────────────────
function buildColOptions(selectedVal = '') {
    let html = '<option value="">— pilih kolom —</option>';
    Object.entries(ALL_TABLE_COLS).forEach(([tname, cols]) => {
        html += `<optgroup label="${esc(tname)}">`;
        cols.forEach(col => {
            const val = `${tname}.${col.column_name}`;
            const sel = val === selectedVal ? 'selected' : '';
            const pk  = col.is_primary ? ' 🔑' : '';
            html += `<option value="${esc(val)}" ${sel}>${esc(col.column_name)}${pk}</option>`;
        });
        html += '</optgroup>';
    });
    return html;
}

function addOnRow(leftVal = '', rightVal = '') {
    const container = document.getElementById('on-rows');
    const div = document.createElement('div');
    div.className = 'on-row';
    div.innerHTML = `
        <select name="join_on_left[]"  class="on-col-sel">${buildColOptions(leftVal)}</select>
        <span class="on-eq">=</span>
        <select name="join_on_right[]" class="on-col-sel">${buildColOptions(rightVal)}</select>
        <button type="button" class="btn-icon btn-delete" onclick="this.closest('.on-row').remove()">✕</button>`;
    container.appendChild(div);
}

function applyFkSuggestion(btn) {
    const left  = btn.dataset.left;
    const right = btn.dataset.right;
    // Cek apakah sudah ada ON row kosong, isi itu dulu
    const empties = [...document.querySelectorAll('.on-row')].filter(r => {
        const sels = r.querySelectorAll('select');
        return !sels[0].value && !sels[1].value;
    });
    if (empties.length) {
        const sels = empties[0].querySelectorAll('select');
        sels[0].value = left;
        sels[1].value = right;
    } else {
        addOnRow(left, right);
    }
}

// ── Column picker ─────────────────────────────────────────
function selectAllCols() {
    document.querySelectorAll('#col-picker input[type=checkbox]').forEach(c => c.checked = true);
}
function clearAllCols() {
    document.querySelectorAll('#col-picker input[type=checkbox]').forEach(c => c.checked = false);
}

// ── WHERE rows ────────────────────────────────────────────
function addWhereRow() {
    const container = document.getElementById('where-rows');
    const idx = container.querySelectorAll('.filter-row').length;
    const colOpts = buildColOptions();
    const opOpts = Object.entries({
        ilike:'mengandung', not_ilike:'tidak mengandung',
        eq:'= sama dengan',  neq:'≠ tidak sama',
        gt:'> lebih besar',  gte:'>= lebih besar/sama',
        lt:'< lebih kecil',  lte:'<= lebih kecil/sama',
        starts_with:'diawali', ends_with:'diakhiri',
        is_null:'adalah NULL', is_not_null:'bukan NULL',
    }).map(([k,v]) => `<option value="${k}">${esc(v)}</option>`).join('');

    const div = document.createElement('div');
    div.className = 'filter-row';
    div.innerHTML = `
        <div class="filter-logic">
            <select name="where_logic[]" class="filter-logic-sel">
                <option value="AND">AND</option><option value="OR">OR</option>
            </select>
        </div>
        <select name="where_col[]" class="filter-col-sel">${colOpts}</select>
        <select name="where_op[]" class="filter-op-sel" onchange="toggleJoinWhereVal(this)">${opOpts}</select>
        <input type="text" name="where_val[]" class="filter-val-inp" placeholder="nilai...">
        <button type="button" class="btn-icon btn-delete" onclick="removeWhereRow(this)">✕</button>`;
    container.appendChild(div);

    if (idx === 0) {
        const logicDiv = div.querySelector('.filter-logic');
        logicDiv.innerHTML = '<span class="filter-where-label">WHERE</span>';
        logicDiv.className = 'filter-logic filter-logic-first';
    }
}

function removeWhereRow(btn) {
    btn.closest('.filter-row').remove();
    const rows = document.querySelectorAll('#where-rows .filter-row');
    rows.forEach((row, i) => {
        const ld = row.querySelector('.filter-logic');
        if (i === 0) {
            ld.innerHTML = '<span class="filter-where-label">WHERE</span>';
            ld.className = 'filter-logic filter-logic-first';
        } else if (!ld.querySelector('select')) {
            ld.innerHTML = `<select name="where_logic[]" class="filter-logic-sel"><option>AND</option><option>OR</option></select>`;
            ld.className = 'filter-logic';
        }
    });
}

function toggleJoinWhereVal(sel) {
    const inp = sel.closest('.filter-row').querySelector('.filter-val-inp');
    const noVal = ['is_null','is_not_null'];
    inp.style.display = noVal.includes(sel.value) ? 'none' : '';
}

// ── SQL copy & CSV export ─────────────────────────────────
function copySQL() {
    const text = document.getElementById('sql-preview').textContent;
    navigator.clipboard.writeText(text).then(() => pgmAlert('SQL disalin!'));
}

function exportJoinCsv() {
    const table = document.getElementById('join-result-table');
    if (!table) return;
    const rows = [...table.querySelectorAll('tr')];
    const csv  = rows.map(row =>
        [...row.querySelectorAll('th,td')].map(cell => {
            const text = cell.querySelector('.null-val') ? '' : cell.textContent.trim();
            return '"' + text.replace(/"/g, '""') + '"';
        }).join(',')
    ).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const a = Object.assign(document.createElement('a'), {
        href: URL.createObjectURL(blob), download: 'join_result.csv'
    });
    a.click();
}

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Init: toggle value input pada operator yg ada
document.querySelectorAll('.filter-op-sel').forEach(s => toggleJoinWhereVal(s));

// Paste SQL ke SQL Editor jika ada flag di sessionStorage
if (sessionStorage.getItem('paste_sql')) {
    const el = document.getElementById('sql-input');
    if (el) { el.value = sessionStorage.getItem('paste_sql'); sessionStorage.removeItem('paste_sql'); }
}
</script>

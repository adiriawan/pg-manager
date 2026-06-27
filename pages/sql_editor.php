<?php
$result   = null;
$error    = null;
$execTime = null;
$rowCount = null;
$lastSql  = $_SESSION['last_sql'] ?? '';

// BUG-06 FIX: Batas maksimum baris yang dikembalikan SQL Editor
define('SQL_EDITOR_MAX_ROWS', 1000);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sql'])) {
    $sql = trim($_POST['sql']);
    $_SESSION['last_sql'] = $sql;
    $start = microtime(true);
    try {
        $stmt = $pdo->query($sql);
        $execTime = round((microtime(true) - $start) * 1000, 2);
        if ($stmt->columnCount() > 0) {
            $result    = $stmt->fetchAll();
            $rowCount  = count($result);
            // BUG-06 FIX: Truncate tampilan jika melebihi batas
            $truncated = $rowCount > SQL_EDITOR_MAX_ROWS;
            if ($truncated) $result = array_slice($result, 0, SQL_EDITOR_MAX_ROWS);
        } else {
            $rowCount  = $stmt->rowCount();
            $result    = [];
            $truncated = false;
        }
    } catch (Exception $e) {
        $error     = $e->getMessage();
        $execTime  = round((microtime(true) - $start) * 1000, 2);
        $truncated = false;
    }
    $lastSql = $sql;
} else {
    $truncated = false;
}

// Export handler
if (isset($_GET['export']) && isset($_SESSION['last_sql'])) {
    $fmt = $_GET['export'];
    try {
        $rows = $pdo->query($_SESSION['last_sql'])->fetchAll();
        if ($fmt === 'json') {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="query_result.json"');
            echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($fmt === 'excel') {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="query_result.csv"');
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            if ($rows) {
                fputcsv($out, array_keys($rows[0]), ',', '"', '\\');
                foreach ($rows as $row) {
                    fputcsv($out, array_map(fn($v) => $v ?? '', $row), ',', '"', '\\');
                }
            }
            fclose($out);
            exit;
        }
        if ($fmt === 'csv') {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="query_result.csv"');
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            if ($rows) {
                fputcsv($out, array_keys($rows[0]));
                foreach ($rows as $row) fputcsv($out, $row);
            }
            fclose($out);
            exit;
        }
    } catch (Exception $e) {}
}
?>

<div class="page-header" style="margin-bottom:8px">
    <div>
        <h1>SQL Editor</h1>
        <p class="page-sub">Jalankan query SQL langsung ke database</p>
    </div>
    <div class="header-actions">
        <button class="sql-history-toggle" id="btn-history-toggle" onclick="toggleHistoryPanel()">🕐 History</button>
        <button class="btn btn-outline" id="btn-sql-fs" onclick="toggleFullscreen('sql-fullscreen-wrap')" title="Fullscreen">⛶ Fullscreen</button>
    </div>
</div>

<!-- Tab bar -->
<div class="sql-tabs" id="sql-tab-bar"><!-- diisi JS --></div>

<div id="sql-fullscreen-wrap" class="sql-layout" id="sql-layout">
    <!-- History panel (hidden by default) -->
    <div id="history-panel" class="sql-history-panel" style="display:none; flex-direction:column">
        <div class="sql-history-header">
            <span>🕐 History</span>
            <button class="btn btn-outline btn-sm" onclick="clearSqlHistory()">Hapus Semua</button>
        </div>
        <div class="sql-history-list" id="history-list"></div>
    </div>

    <!-- Editor utama -->
    <div class="sql-main">
        <form method="POST" id="sql-form">
            <div class="sql-toolbar">
                <button type="submit" class="btn btn-primary">▶ Jalankan <kbd style="font-size:10px;margin-left:4px">Ctrl+↵</kbd></button>
                <button type="button" class="btn btn-outline" onclick="clearEditor()">Bersihkan</button>
                <?php if ($result !== null && count($result) > 0): ?>
                <div class="export-wrap">
                    <button type="button" class="btn btn-outline" onclick="toggleExportMenu('sql-export-menu')">
                        ⬇ Export ▾
                    </button>
                    <div class="export-menu" id="sql-export-menu">
                        <a href="?page=sql-editor&schema=<?= urlencode($schema) ?>&export=csv">📄 CSV</a>
                        <a href="?page=sql-editor&schema=<?= urlencode($schema) ?>&export=json">📋 JSON</a>
                        <a href="?page=sql-editor&schema=<?= urlencode($schema) ?>&export=excel">📊 Excel</a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="sql-editor-container">
                <div class="sql-line-numbers" id="line-numbers">1</div>
                <textarea id="sql-input" name="sql" class="sql-textarea"
                    spellcheck="false"
                    placeholder="-- Ketik atau pilih dari History di kanan atas
SELECT * FROM public.nama_tabel LIMIT 10;"><?= htmlspecialchars($lastSql) ?></textarea>
            </div>
        </form>

        <?php if ($error): ?>
        <div class="sql-result-header error">
            <span class="result-icon">✗</span> Error (<?= $execTime ?>ms)
        </div>
        <div class="alert alert-error sql-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($result !== null && !$error): ?>
        <div class="sql-result-header success">
            <span class="result-icon">✓</span>
            <?php if (!empty($truncated)): ?>
                <?= number_format(SQL_EDITOR_MAX_ROWS) ?> dari <?= number_format($rowCount) ?> baris ditampilkan
                <span style="color:var(--warning);margin-left:6px">⚠ Hasil dipotong — tambahkan LIMIT ke query</span>
            <?php else: ?>
                <?= number_format($rowCount) ?> baris <?= count($result) > 0 ? 'dikembalikan' : 'terpengaruh' ?>
            <?php endif; ?>
            (<?= $execTime ?>ms)
        </div>
        <?php if (count($result) > 0): ?>
        <div class="table-wrap">
            <table class="data-table" id="result-table">
                <thead><tr>
                    <?php foreach (array_keys($result[0]) as $col): ?>
                    <th><?= htmlspecialchars($col) ?></th>
                    <?php endforeach; ?>
                </tr></thead>
                <tbody>
                    <?php foreach ($result as $row): ?>
                    <tr><?php foreach ($row as $val): ?>
                        <td class="data-cell">
                            <?= $val === null ? '<span class="null-val">NULL</span>' : htmlspecialchars(mb_strimwidth((string)$val,0,200,'…')) ?>
                        </td>
                    <?php endforeach; ?></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="sql-success-msg">✓ Query berhasil dijalankan.</div>
        <?php endif; ?>
        <?php endif; ?>
    </div><!-- /sql-main -->
</div><!-- /sql-fullscreen-wrap -->

<script>
// ── Line numbers ─────────────────────────────────────────────
const sqlInput    = document.getElementById('sql-input');
const lineNumbers = document.getElementById('line-numbers');

function updateLineNumbers() {
    const lines = sqlInput.value.split('\n').length;
    lineNumbers.innerHTML = Array.from({length: lines}, (_, i) => i + 1).join('\n');
}
sqlInput.addEventListener('input',  updateLineNumbers);
sqlInput.addEventListener('scroll', () => { lineNumbers.scrollTop = sqlInput.scrollTop; });
updateLineNumbers();

sqlInput.addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.key === 'Enter') {
        e.preventDefault();
        // Simpan ke tab aktif + history
        saveCurrentTab();
        document.getElementById('sql-form').submit();
    }
    if (e.key === 'Tab') {
        e.preventDefault();
        const s = this.selectionStart, end = this.selectionEnd;
        this.value = this.value.substring(0,s) + '  ' + this.value.substring(end);
        this.selectionStart = this.selectionEnd = s + 2;
        updateLineNumbers();
    }
});

function clearEditor() {
    sqlInput.value = '';
    updateLineNumbers();
    sqlInput.focus();
    saveCurrentTab();
}

function saveCurrentTab() {
    const tabs = getSqlTabs();
    const cur  = tabs.find(t => t.id === getActiveTabId());
    if (cur) { cur.sql = sqlInput.value; saveSqlTabs(tabs); }
}

// Auto-save on input
sqlInput.addEventListener('input', () => {
    clearTimeout(window._saveTimer);
    window._saveTimer = setTimeout(saveCurrentTab, 500);
});

// ── Init tabs ────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    // Simpan SQL dari POST ke tab aktif
    <?php if ($lastSql): ?>
    (function() {
        const tabs = getSqlTabs();
        const cur  = tabs.find(t => t.id === getActiveTabId());
        if (cur) { cur.sql = <?= json_encode($lastSql) ?>; saveSqlTabs(tabs); }
        // Simpan ke history
        <?php if (!$error && $result !== null): ?>
        addSqlHistory(<?= json_encode($lastSql) ?>, <?= $execTime ?>, <?= $rowCount ?>);
        <?php elseif ($error): ?>
        addSqlHistory(<?= json_encode($lastSql) ?>, <?= $execTime ?>, null);
        <?php endif; ?>
    })();
    <?php endif; ?>

    renderSqlTabs();

    // Load SQL dari tab aktif
    const tabs   = getSqlTabs();
    const active = tabs.find(t => t.id === getActiveTabId());
    if (active && active.sql && !<?= json_encode((bool)$lastSql) ?>) {
        sqlInput.value = active.sql;
        updateLineNumbers();
    }
});
</script>

<?php
// Ambil semua tabel + kolom
$tableColumns = [];
try {
    if (isMySQL()) {
        $stmt = $pdo->prepare("
            SELECT c.table_name, c.column_name, c.data_type, c.is_nullable, c.column_default,
                   CASE WHEN c.COLUMN_KEY = 'PRI' THEN 1 ELSE 0 END as is_primary
            FROM information_schema.COLUMNS c
            WHERE c.TABLE_SCHEMA = ?
            ORDER BY c.table_name, c.ordinal_position
        ");
        $stmt->execute([$schema]);
    } else {
        $stmt = $pdo->prepare("
            SELECT c.table_name, c.column_name, c.data_type, c.is_nullable, c.column_default,
                   CASE WHEN pk.column_name IS NOT NULL THEN true ELSE false END as is_primary
            FROM information_schema.columns c
            LEFT JOIN (
                SELECT ku.table_name, ku.column_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage ku
                    ON tc.constraint_name = ku.constraint_name AND tc.table_schema = ku.table_schema
                WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = ?
            ) pk ON c.table_name = pk.table_name AND c.column_name = pk.column_name
            WHERE c.table_schema = ?
            ORDER BY c.table_name, c.ordinal_position
        ");
        $stmt->execute([$schema, $schema]);
    }
    foreach ($stmt->fetchAll() as $row) {
        $tableColumns[$row['table_name']][] = $row;
    }
} catch (Exception $e) {}

// Ambil semua foreign key
$foreignKeys = [];
try {
    if (isMySQL()) {
        $stmt = $pdo->prepare("
            SELECT kcu.TABLE_NAME as from_table, kcu.COLUMN_NAME as from_column,
                   kcu.REFERENCED_TABLE_NAME as to_table, kcu.REFERENCED_COLUMN_NAME as to_column,
                   kcu.CONSTRAINT_NAME as constraint_name
            FROM information_schema.KEY_COLUMN_USAGE kcu
            WHERE kcu.TABLE_SCHEMA = ? AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY kcu.TABLE_NAME, kcu.COLUMN_NAME
        ");
        $stmt->execute([$schema]);
    } else {
        $stmt = $pdo->prepare("
            SELECT tc.table_name as from_table, kcu.column_name as from_column,
                   ccu.table_name as to_table, ccu.column_name as to_column, tc.constraint_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage ccu
                ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = ?
            ORDER BY tc.table_name, kcu.column_name
        ");
        $stmt->execute([$schema]);
    }
    $foreignKeys = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header">
    <div>
        <h1>Entity Relationship Diagram</h1>
        <p class="page-sub">Schema: <strong><?= htmlspecialchars($schema) ?></strong> &mdash; <span id="erd-stat"><?= count($tableColumns) ?> tabel, <?= count($foreignKeys) ?> relasi</span></p>
    </div>
    <div class="header-actions">
        <button class="btn btn-outline btn-sm" onclick="erdResetLayout()">Reset Layout</button>
        <button class="btn btn-outline btn-sm" onclick="erdZoom(0.2)">＋ Zoom In</button>
        <button class="btn btn-outline btn-sm" onclick="erdZoom(-0.2)">－ Zoom Out</button>
        <button class="btn btn-outline btn-sm" onclick="erdFit()">Fit</button>
        <button class="btn btn-outline btn-sm" id="btn-erd-fs" onclick="toggleFullscreen('erd-wrap')">⛶ Fullscreen</button>
    </div>
</div>

<div id="erd-legend" class="erd-legend">
    <span class="legend-item"><span class="legend-dot pk"></span> Primary Key</span>
    <span class="legend-item"><span class="legend-dot fk"></span> Foreign Key</span>
    <span class="legend-item"><span class="legend-line"></span> Relasi FK</span>
    <span class="legend-hint">Scroll = zoom &nbsp;|&nbsp; Drag canvas = geser &nbsp;|&nbsp; Drag tabel = pindah</span>
</div>

<!-- Layout: panel kiri + canvas kanan -->
<div id="erd-wrap" style="display:flex;flex:1;overflow:hidden;min-height:0">

<!-- ── Panel pemilih tabel ─────────────────────────────────── -->
<div id="erd-panel" class="erd-panel">
    <div class="erd-panel-header">
        <span>Tabel</span>
        <div style="display:flex;gap:4px">
            <button class="btn btn-outline btn-sm" onclick="erdSelectAll(true)"  title="Pilih semua">✓ Semua</button>
            <button class="btn btn-outline btn-sm" onclick="erdSelectAll(false)" title="Hapus semua">✕</button>
        </div>
    </div>
    <div class="erd-panel-search">
        <input type="text" id="erd-table-search" placeholder="Cari tabel..." oninput="erdFilterPanel(this.value)">
    </div>
    <div class="erd-panel-list" id="erd-panel-list">
        <?php foreach (array_keys($tableColumns) as $tname): ?>
        <label class="erd-panel-item" data-table="<?= htmlspecialchars($tname) ?>">
            <input type="checkbox" class="erd-tbl-chk" value="<?= htmlspecialchars($tname) ?>" checked
                onchange="erdUpdateVisible()">
            <span><?= htmlspecialchars($tname) ?></span>
        </label>
        <?php endforeach; ?>
    </div>
</div>

<div id="erd-canvas-wrap" style="flex:1;position:relative;overflow:hidden">
    <svg id="erd-svg" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <marker id="arrow-end" markerWidth="8" markerHeight="8" refX="7" refY="3" orient="auto">
                <path d="M0,0 L0,6 L8,3 z" fill="#3ECF8E" opacity="0.8"/>
            </marker>
            <marker id="arrow-start" markerWidth="8" markerHeight="8" refX="1" refY="3" orient="auto">
                <circle cx="3" cy="3" r="2.5" fill="#3ECF8E" opacity="0.8"/>
            </marker>
            <filter id="shadow">
                <feDropShadow dx="0" dy="2" stdDeviation="4" flood-color="#000" flood-opacity="0.4"/>
            </filter>
        </defs>
        <g id="erd-root">
            <g id="erd-edges"></g>
            <g id="erd-nodes"></g>
        </g>
    </svg>
</div>

<!-- Tooltip -->
<div id="erd-tooltip" class="erd-tooltip" style="display:none"></div>
</div><!-- /erd-canvas-wrap -->
</div><!-- /erd-wrap -->

<script>
const ERD_TABLES = <?= json_encode($tableColumns, JSON_UNESCAPED_UNICODE) ?>;
const ERD_FKS    = <?= json_encode($foreignKeys,  JSON_UNESCAPED_UNICODE) ?>;

// ===== Layout constants =====
const COL_HEIGHT  = 26;
const HEADER_H    = 36;
const TABLE_W     = 210;
const PADDING     = 8;

// ===== State =====
let scale    = 1;
let panX     = 0;
let panY     = 0;
let isPanning = false;
let panStart  = { x: 0, y: 0 };
let tablePositions = {};  // { tableName: {x, y} }
let dragState = null;
let highlightFk = null;
let visibleTables = new Set(Object.keys(ERD_TABLES)); // tabel yang dipilih

const svg   = document.getElementById('erd-svg');
const root  = document.getElementById('erd-root');
const edges = document.getElementById('erd-edges');
const nodes = document.getElementById('erd-nodes');
const wrap  = document.getElementById('erd-canvas-wrap');

// ===== Panel: update tabel yang ditampilkan =====
function erdUpdateVisible() {
    visibleTables = new Set(
        [...document.querySelectorAll('.erd-tbl-chk:checked')].map(c => c.value)
    );
    // Layout hanya tabel baru yang belum ada posisinya
    layoutNewTables();
    render();
    updateErdStat();
}

function erdSelectAll(checked) {
    document.querySelectorAll('.erd-tbl-chk').forEach(c => c.checked = checked);
    erdUpdateVisible();
}

function erdFilterPanel(q) {
    q = q.toLowerCase();
    document.querySelectorAll('#erd-panel-list .erd-panel-item').forEach(item => {
        item.style.display = item.dataset.table.toLowerCase().includes(q) ? '' : 'none';
    });
}

function updateErdStat() {
    const visFK = ERD_FKS.filter(fk => visibleTables.has(fk.from_table) && visibleTables.has(fk.to_table));
    document.getElementById('erd-stat').textContent =
        `${visibleTables.size} tabel ditampilkan, ${visFK.length} relasi`;
}

// ===== Init layout (grid) — hanya tabel yang belum ada posisinya =====
function layoutNewTables() {
    const toLayout = [...visibleTables].filter(n => !tablePositions[n]);
    if (!toLayout.length) return;

    const allVisible = [...visibleTables];
    const cols   = Math.max(1, Math.ceil(Math.sqrt(allVisible.length * 1.4)));
    const gapX   = TABLE_W + 60;
    const startX = 40;
    const startY = 40;

    allVisible.forEach((name, i) => {
        if (tablePositions[name]) return; // sudah ada, jangan geser
        const col  = i % cols;
        const row  = Math.floor(i / cols);
        const h    = HEADER_H + (ERD_TABLES[name]?.length || 0) * COL_HEIGHT + PADDING;
        tablePositions[name] = {
            x: startX + col * gapX,
            y: startY + row * (h + 50)
        };
    });
}

function initLayout() {
    const tableNames = Object.keys(ERD_TABLES);
    const cols   = Math.max(1, Math.ceil(Math.sqrt(tableNames.length * 1.4)));
    const gapX   = TABLE_W + 60;
    const startX = 40;
    const startY = 40;

    tableNames.forEach((name, i) => {
        const col = i % cols;
        const row = Math.floor(i / cols);
        const h   = HEADER_H + (ERD_TABLES[name]?.length || 0) * COL_HEIGHT + PADDING;
        tablePositions[name] = {
            x: startX + col * gapX,
            y: startY + row * (h + 50)
        };
    });
}

// ===== Render — hanya tabel & FK yang terpilih =====
function render() {
    nodes.innerHTML = '';
    edges.innerHTML = '';

    // Build FK lookup hanya untuk tabel yang visible
    const fkMap = {};
    ERD_FKS.forEach(fk => {
        if (visibleTables.has(fk.from_table))
            fkMap[fk.from_table + '.' + fk.from_column] = fk;
    });

    // Draw tables
    visibleTables.forEach(name => {
        if (!ERD_TABLES[name]) return;
        const cols = ERD_TABLES[name];
        const pos  = tablePositions[name];
        if (!pos) return;
        const h = HEADER_H + cols.length * COL_HEIGHT + PADDING;
        drawTable(name, cols, pos.x, pos.y, h, fkMap);
    });

    // Draw edges — hanya jika kedua tabel visible
    ERD_FKS.forEach(fk => {
        if (visibleTables.has(fk.from_table) && visibleTables.has(fk.to_table))
            drawEdge(fk);
    });

    applyTransform();
}

function drawTable(name, cols, x, y, h, fkMap) {
    const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
    g.setAttribute('class', 'erd-table');
    g.setAttribute('data-table', name);
    g.setAttribute('transform', `translate(${x},${y})`);

    // Shadow rect
    const shadow = svgEl('rect', {
        x: 3, y: 3, width: TABLE_W, height: h,
        rx: 7, ry: 7,
        fill: 'rgba(0,0,0,0.35)', filter: 'url(#shadow)'
    });
    g.appendChild(shadow);

    // Background
    const bg = svgEl('rect', {
        x: 0, y: 0, width: TABLE_W, height: h,
        rx: 7, ry: 7,
        fill: '#252525', stroke: '#3ECF8E', 'stroke-width': 1.5,
        class: 'table-bg', 'data-table': name
    });
    g.appendChild(bg);

    // Header
    const headerBg = svgEl('rect', {
        x: 0, y: 0, width: TABLE_W, height: HEADER_H,
        rx: 7, ry: 7, fill: '#1a3d2b'
    });
    g.appendChild(headerBg);
    // Header bottom flat
    const headerFlat = svgEl('rect', {
        x: 0, y: HEADER_H - 7, width: TABLE_W, height: 7, fill: '#1a3d2b'
    });
    g.appendChild(headerFlat);

    // Table name
    const title = svgEl('text', {
        x: 12, y: 23, fill: '#3ECF8E',
        'font-weight': '700', 'font-size': '13',
        'font-family': '-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif',
        class: 'table-title'
    });
    title.textContent = name;
    g.appendChild(title);

    // Row count badge
    const badge = svgEl('text', {
        x: TABLE_W - 10, y: 23, fill: '#3ECF8E', opacity: '0.5',
        'font-size': '10', 'text-anchor': 'end',
        'font-family': '-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif'
    });
    badge.textContent = cols.length + ' col';
    g.appendChild(badge);

    // Divider
    g.appendChild(svgEl('line', {
        x1: 0, y1: HEADER_H, x2: TABLE_W, y2: HEADER_H,
        stroke: '#3ECF8E', 'stroke-width': 1, opacity: '0.4'
    }));

    // Columns
    cols.forEach((col, i) => {
        const cy    = HEADER_H + i * COL_HEIGHT + COL_HEIGHT / 2 + 4;
        const isFk  = !!(fkMap[name + '.' + col.column_name]);
        const isPk  = col.is_primary;

        // Row hover bg
        const rowBg = svgEl('rect', {
            x: 1, y: HEADER_H + i * COL_HEIGHT + 1,
            width: TABLE_W - 2, height: COL_HEIGHT - 1,
            fill: 'transparent', class: 'col-hover-bg',
            rx: 2
        });
        g.appendChild(rowBg);

        // Divider between rows
        if (i > 0) {
            g.appendChild(svgEl('line', {
                x1: 8, y1: HEADER_H + i * COL_HEIGHT,
                x2: TABLE_W - 8, y2: HEADER_H + i * COL_HEIGHT,
                stroke: '#333', 'stroke-width': 0.5
            }));
        }

        // Icon PK / FK / normal
        let iconText = '';
        let iconColor = '#666';
        if (isPk)  { iconText = '🔑'; }
        else if (isFk) { iconText = '🔗'; }

        if (iconText) {
            const icon = svgEl('text', { x: 8, y: cy + 4, 'font-size': '9' });
            icon.textContent = iconText;
            g.appendChild(icon);
        }

        // Col name
        const colText = svgEl('text', {
            x: isPk || isFk ? 24 : 12, y: cy + 1,
            fill: isPk ? '#f6e05e' : isFk ? '#90cdf4' : '#d1d5db',
            'font-size': '12', 'font-weight': isPk ? '600' : '400',
            'font-family': 'SF Mono,Fira Code,Cascadia Code,Consolas,monospace',
            class: 'col-name'
        });
        colText.textContent = truncate(col.column_name, 18);
        colText.setAttribute('data-col', col.column_name);
        colText.setAttribute('data-table', name);
        g.appendChild(colText);

        // Data type
        const typeText = svgEl('text', {
            x: TABLE_W - 8, y: cy + 1,
            fill: '#555', 'font-size': '10',
            'text-anchor': 'end',
            'font-family': 'SF Mono,Fira Code,Cascadia Code,Consolas,monospace'
        });
        typeText.textContent = shortType(col.data_type);
        g.appendChild(typeText);

        // Nullable dot
        if (col.is_nullable === 'YES') {
            g.appendChild(svgEl('circle', {
                cx: TABLE_W - 6, cy: cy - 6, r: 2, fill: '#555'
            }));
        }
    });

    // Drag handle
    const dragArea = svgEl('rect', {
        x: 0, y: 0, width: TABLE_W, height: HEADER_H,
        fill: 'transparent', cursor: 'grab',
        class: 'drag-handle', 'data-table': name
    });
    dragArea.addEventListener('mousedown', startDrag);
    g.appendChild(dragArea);

    nodes.appendChild(g);
}

function drawEdge(fk) {
    const fromPos = tablePositions[fk.from_table];
    const toPos   = tablePositions[fk.to_table];
    if (!fromPos || !toPos || fk.from_table === fk.to_table) return;

    const fromCols = ERD_TABLES[fk.from_table] || [];
    const toCols   = ERD_TABLES[fk.to_table]   || [];

    const fromIdx = fromCols.findIndex(c => c.column_name === fk.from_column);
    const toIdx   = toCols.findIndex(c => c.column_name === fk.to_column);

    const fromY = fromPos.y + HEADER_H + (fromIdx + 0.5) * COL_HEIGHT + 4;
    const toY   = toPos.y  + HEADER_H + (toIdx  + 0.5) * COL_HEIGHT + 4;

    // Determine which side to connect
    const fromRight = fromPos.x + TABLE_W < toPos.x;
    const fromLeft  = fromPos.x > toPos.x + TABLE_W;

    let x1, x2;
    if (fromRight) {
        x1 = fromPos.x + TABLE_W;
        x2 = toPos.x;
    } else if (fromLeft) {
        x1 = fromPos.x;
        x2 = toPos.x + TABLE_W;
    } else {
        // Overlapping horizontally — use sides based on center
        const fromCx = fromPos.x + TABLE_W / 2;
        const toCx   = toPos.x + TABLE_W / 2;
        if (fromCx < toCx) {
            x1 = fromPos.x + TABLE_W; x2 = toPos.x;
        } else {
            x1 = fromPos.x; x2 = toPos.x + TABLE_W;
        }
    }

    const dx = Math.abs(x2 - x1) * 0.45;
    const path = `M${x1},${fromY} C${x1 + (x1 < x2 ? dx : -dx)},${fromY} ${x2 + (x1 < x2 ? -dx : dx)},${toY} ${x2},${toY}`;

    const line = svgEl('path', {
        d: path,
        fill: 'none',
        stroke: '#3ECF8E',
        'stroke-width': 1.5,
        opacity: '0.45',
        'marker-end': 'url(#arrow-end)',
        'marker-start': 'url(#arrow-start)',
        class: 'fk-edge',
        'data-from': fk.from_table,
        'data-to': fk.to_table,
        'data-from-col': fk.from_column,
        'data-to-col': fk.to_column,
        cursor: 'pointer'
    });

    line.addEventListener('mouseenter', function(e) {
        this.setAttribute('opacity', '1');
        this.setAttribute('stroke-width', '2.5');
        showTooltip(e, `${fk.from_table}.${fk.from_column} → ${fk.to_table}.${fk.to_column}`);
    });
    line.addEventListener('mousemove', moveTooltip);
    line.addEventListener('mouseleave', function() {
        this.setAttribute('opacity', '0.45');
        this.setAttribute('stroke-width', '1.5');
        hideTooltip();
    });

    edges.appendChild(line);
}

// ===== Drag tables =====
function startDrag(e) {
    e.stopPropagation();
    const tableName = e.currentTarget.getAttribute('data-table');
    dragState = {
        table: tableName,
        startX: e.clientX,
        startY: e.clientY,
        origX: tablePositions[tableName].x,
        origY: tablePositions[tableName].y
    };
    e.currentTarget.style.cursor = 'grabbing';
    document.addEventListener('mousemove', onDrag);
    document.addEventListener('mouseup', stopDrag);
}

function onDrag(e) {
    if (!dragState) return;
    const dx = (e.clientX - dragState.startX) / scale;
    const dy = (e.clientY - dragState.startY) / scale;
    tablePositions[dragState.table].x = dragState.origX + dx;
    tablePositions[dragState.table].y = dragState.origY + dy;
    render();
}

function stopDrag() {
    dragState = null;
    document.removeEventListener('mousemove', onDrag);
    document.removeEventListener('mouseup', stopDrag);
}

// ===== Pan canvas =====
wrap.addEventListener('mousedown', function(e) {
    if (e.target.closest('.drag-handle')) return;
    isPanning = true;
    panStart = { x: e.clientX - panX, y: e.clientY - panY };
    wrap.style.cursor = 'grabbing';
});
document.addEventListener('mousemove', function(e) {
    if (!isPanning) return;
    panX = e.clientX - panStart.x;
    panY = e.clientY - panStart.y;
    applyTransform();
});
document.addEventListener('mouseup', function() {
    isPanning = false;
    wrap.style.cursor = '';
});

// ===== Zoom =====
wrap.addEventListener('wheel', function(e) {
    e.preventDefault();
    const delta = e.deltaY < 0 ? 0.1 : -0.1;
    const rect  = wrap.getBoundingClientRect();
    const mx    = e.clientX - rect.left;
    const my    = e.clientY - rect.top;
    const newScale = Math.min(3, Math.max(0.2, scale + delta));
    panX = mx - (mx - panX) * (newScale / scale);
    panY = my - (my - panY) * (newScale / scale);
    scale = newScale;
    applyTransform();
}, { passive: false });

function erdZoom(delta) {
    scale = Math.min(3, Math.max(0.2, scale + delta));
    applyTransform();
}

function applyTransform() {
    root.setAttribute('transform', `translate(${panX},${panY}) scale(${scale})`);
}

function erdFit() {
    if (!visibleTables.size) return;
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    visibleTables.forEach(name => {
        const p = tablePositions[name];
        if (!p) return;
        const h = HEADER_H + (ERD_TABLES[name]?.length || 0) * COL_HEIGHT + PADDING;
        minX = Math.min(minX, p.x);
        minY = Math.min(minY, p.y);
        maxX = Math.max(maxX, p.x + TABLE_W);
        maxY = Math.max(maxY, p.y + h);
    });
    if (minX === Infinity) return;
    const pw = wrap.clientWidth  - 80;
    const ph = wrap.clientHeight - 80;
    const cw = maxX - minX || 1;
    const ch = maxY - minY || 1;
    scale = Math.min(3, Math.max(0.15, Math.min(pw / cw, ph / ch)));
    panX  = (pw - cw * scale) / 2 + 40 - minX * scale;
    panY  = (ph - ch * scale) / 2 + 40 - minY * scale;
    applyTransform();
}

function erdResetLayout() {
    scale = 1; panX = 0; panY = 0;
    tablePositions = {};
    layoutNewTables();
    render();
    setTimeout(erdFit, 50);
}

// ===== Tooltip =====
const tooltip = document.getElementById('erd-tooltip');
function showTooltip(e, text) {
    tooltip.textContent = text;
    tooltip.style.display = 'block';
    moveTooltip(e);
}
function moveTooltip(e) {
    tooltip.style.left = (e.clientX + 12) + 'px';
    tooltip.style.top  = (e.clientY - 28) + 'px';
}
function hideTooltip() { tooltip.style.display = 'none'; }

// ===== Helpers =====
function svgEl(tag, attrs) {
    const el = document.createElementNS('http://www.w3.org/2000/svg', tag);
    Object.entries(attrs).forEach(([k, v]) => el.setAttribute(k, v));
    return el;
}
function truncate(str, max) {
    return str.length > max ? str.slice(0, max - 1) + '…' : str;
}
function shortType(type) {
    const map = {
        'character varying': 'varchar', 'timestamp without time zone': 'timestamp',
        'timestamp with time zone': 'timestamptz', 'double precision': 'float8',
        'integer': 'int4', 'bigint': 'int8', 'smallint': 'int2',
        'boolean': 'bool', 'character': 'char'
    };
    return map[type] || type;
}

// ===== Init =====
if (!Object.keys(ERD_TABLES).length) {
    wrap.innerHTML = `<div class="empty-state" style="height:100%">
        <svg width="48" height="48" fill="none" viewBox="0 0 48 48"><rect x="4" y="8" width="40" height="32" rx="4" stroke="#3ECF8E" stroke-width="2"/></svg>
        <h3>Tidak ada tabel di schema ini</h3><p>Pilih schema lain dari sidebar.</p>
    </div>`;
} else {
    initLayout();
    render();
    updateErdStat();
    setTimeout(erdFit, 50);
}
</script>

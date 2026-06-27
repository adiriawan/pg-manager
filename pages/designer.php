<?php
// ── Eksekusi SQL dari designer ────────────────────────────────
$designerMsg  = null;
$designerType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action'])) {
    if ($_POST['_action'] === 'execute_sql') {
        $sqls = json_decode($_POST['sql_statements'] ?? '[]', true) ?: [];
        $executed = [];
        $failed   = null;
        try {
            $pdo->beginTransaction();
            foreach ($sqls as $sql) {
                $sql = trim($sql);
                if (!$sql) continue;
                $pdo->exec($sql);
                $executed[] = $sql;
            }
            $pdo->commit();
            $designerMsg  = count($executed) . ' statement berhasil dieksekusi.';
            $designerType = 'success';
        } catch (Exception $e) {
            $pdo->rollBack();
            $designerMsg  = $e->getMessage();
            $designerType = 'error';
        }
    }
}

// Ambil tipe data PostgreSQL yang umum
$pgTypes = [
    'integer','bigint','smallint','serial','bigserial',
    'numeric','decimal','real','double precision',
    'varchar','character varying','text','char','character',
    'boolean',
    'date','time','timestamp','timestamptz',
    'timestamp without time zone','timestamp with time zone',
    'uuid','json','jsonb','bytea',
];

// Ambil tabel yang sudah ada (untuk deteksi nama duplikat)
$existingTables = [];
try {
    $stmt = $pdo->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'");
    $stmt->execute([$schema]);
    $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}
?>

<div class="page-header">
    <div>
        <h1>Database Designer</h1>
        <p class="page-sub">Rancang tabel baru secara visual lalu generate & execute SQL</p>
    </div>
    <div class="header-actions">
        <button class="btn btn-outline" onclick="dsAddTable()">＋ Tambah Tabel</button>
        <button class="btn btn-outline" onclick="dsClearAll()" title="Hapus semua tabel dari canvas">🗑 Bersihkan</button>
        <button class="btn btn-outline" onclick="dsPreviewSql()">👁 Preview SQL</button>
        <button class="btn btn-primary" onclick="dsExecute()">▶ Execute ke DB</button>
    </div>
</div>

<?php if ($designerMsg): ?>
<div class="alert alert-<?= $designerType ?>" style="margin-bottom:12px"><?= htmlspecialchars($designerMsg) ?></div>
<?php endif; ?>

<!-- Toolbar hint -->
<div class="ds-toolbar-hint">
    <span>💡 Klik canvas untuk tambah tabel &nbsp;|&nbsp; Drag header tabel untuk pindah &nbsp;|&nbsp; Tarik titik FK untuk buat relasi</span>
    <span id="ds-stat" style="color:var(--accent)"></span>
</div>

<!-- Canvas -->
<div id="ds-wrap">
    <svg id="ds-svg" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <marker id="ds-arrow" markerWidth="8" markerHeight="8" refX="7" refY="3" orient="auto">
                <path d="M0,0 L0,6 L8,3 z" fill="#3ECF8E" opacity="0.9"/>
            </marker>
        </defs>
        <g id="ds-root">
            <g id="ds-edges"></g>
            <g id="ds-nodes"></g>
        </g>
    </svg>
    <!-- FK sedang ditarik -->
    <svg id="ds-drag-svg" style="position:absolute;inset:0;pointer-events:none;width:100%;height:100%">
        <path id="ds-drag-line" fill="none" stroke="#3ECF8E" stroke-width="2"
              stroke-dasharray="6,3" opacity="0.8" d=""/>
    </svg>
</div>

<!-- Modal Preview SQL -->
<div id="ds-modal-sql" class="modal-overlay" style="display:none">
    <div class="modal modal-wide">
        <div class="modal-header">
            <h3>Preview SQL</h3>
            <button class="modal-close" onclick="closeModal('ds-modal-sql')">×</button>
        </div>
        <div class="modal-body">
            <pre id="ds-sql-preview" class="ds-sql-pre"></pre>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="dsCopySql()">📋 Copy SQL</button>
            <button class="btn btn-outline" onclick="closeModal('ds-modal-sql')">Tutup</button>
            <button class="btn btn-primary" onclick="closeModal('ds-modal-sql');dsExecute()">▶ Execute</button>
        </div>
    </div>
</div>

<!-- Modal Execute -->
<div id="ds-modal-execute" class="modal-overlay" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <h3>Konfirmasi Execute</h3>
            <button class="modal-close" onclick="closeModal('ds-modal-execute')">×</button>
        </div>
        <div class="modal-body">
            <div class="alert alert-error" style="margin-bottom:12px">
                ⚠️ SQL akan langsung dieksekusi ke database <strong><?= htmlspecialchars($creds['dbname'] ?? '') ?></strong>.
                Tindakan ini tidak bisa dibatalkan.
            </div>
            <pre id="ds-execute-preview" class="ds-sql-pre" style="max-height:300px;overflow:auto"></pre>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('ds-modal-execute')">Batal</button>
            <form method="POST" id="ds-execute-form">
                <input type="hidden" name="_action" value="execute_sql">
                <input type="hidden" name="sql_statements" id="ds-sql-input">
                <button type="submit" class="btn btn-primary">▶ Ya, Execute Sekarang</button>
            </form>
        </div>
    </div>
</div>

<script>
// ══════════════════════════════════════════════════════════════
//  Database Designer — State
// ══════════════════════════════════════════════════════════════
const DS_PG_TYPES    = <?= json_encode($pgTypes) ?>;
const DS_EXISTING    = <?= json_encode($existingTables) ?>;
const DS_SCHEMA      = '<?= addslashes($schema) ?>';

const DS_COL_H  = 34;   // tinggi per baris kolom
const DS_HDR_H  = 40;   // tinggi header tabel
const DS_W      = 260;  // lebar tabel
const DS_PAD    = 10;   // padding bawah

let dsScale  = 1;
let dsPanX   = 0;
let dsPanY   = 0;
let dsPanning = false;
let dsPanStart = {x:0, y:0};

let dsTables  = {};   // { id: { name, x, y, columns:[{id,name,type,pk,notnull,unique,default}] } }
let dsFKs     = [];   // [{id, fromTable, fromCol, toTable, toCol}]
let dsNextId  = 1;

let dsDragState   = null;  // drag tabel
let dsFkDrag      = null;  // drag FK line

const dsSvg   = document.getElementById('ds-svg');
const dsRoot  = document.getElementById('ds-root');
const dsEdges = document.getElementById('ds-edges');
const dsNodes = document.getElementById('ds-nodes');
const dsWrap  = document.getElementById('ds-wrap');
const dsDragLine = document.getElementById('ds-drag-line');

// ══════════════════════════════════════════════════════════════
//  Tambah tabel
// ══════════════════════════════════════════════════════════════
function dsAddTable(x, y) {
    const id  = 'tbl_' + (dsNextId++);
    const cx  = dsWrap.clientWidth;
    const cy  = dsWrap.clientHeight;
    const px  = x !== undefined ? x : (cx / 2 - dsPanX) / dsScale - DS_W / 2;
    const py  = y !== undefined ? y : (cy / 2 - dsPanY) / dsScale - DS_HDR_H;

    dsTables[id] = {
        name: 'tabel_' + Object.keys(dsTables).length,
        x: px, y: py,
        columns: [
            { id: 'col_'+(dsNextId++), name: 'id', type: 'serial', pk: true, notnull: true, unique: false, default: '' }
        ]
    };
    dsRender();
    dsUpdateStat();
    // Langsung buka edit nama tabel
    setTimeout(() => dsEditTableName(id), 50);
}

function dsClearAll() {
    if (!Object.keys(dsTables).length) return;
    if (!confirm('Hapus semua tabel dari canvas?')) return;
    dsTables = {}; dsFKs = []; dsNextId = 1;
    dsRender(); dsUpdateStat();
}

// ══════════════════════════════════════════════════════════════
//  Render SVG
// ══════════════════════════════════════════════════════════════
function dsRender() {
    dsNodes.innerHTML = '';
    dsEdges.innerHTML = '';

    Object.entries(dsTables).forEach(([id, tbl]) => dsDrawTable(id, tbl));
    dsFKs.forEach(fk => dsDrawEdge(fk));
    dsApplyTransform();
}

function dsTableHeight(tbl) {
    return DS_HDR_H + tbl.columns.length * DS_COL_H + DS_PAD;
}

function dsDrawTable(id, tbl) {
    const h  = dsTableHeight(tbl);
    const g  = dsSvgEl('g', { class:'ds-table', 'data-id': id,
                               transform: `translate(${tbl.x},${tbl.y})` });

    // Shadow
    g.appendChild(dsSvgEl('rect', { x:3, y:3, width:DS_W, height:h,
        rx:8, ry:8, fill:'rgba(0,0,0,0.3)' }));

    // Body
    const body = dsSvgEl('rect', { x:0, y:0, width:DS_W, height:h,
        rx:8, ry:8, fill:'#252525', stroke:'#3ECF8E', 'stroke-width':1.5, class:'ds-table-body' });
    g.appendChild(body);

    // Header bg
    const hbg = dsSvgEl('rect', { x:0, y:0, width:DS_W, height:DS_HDR_H,
        rx:8, ry:8, fill:'#1a3d2b' });
    g.appendChild(hbg);
    g.appendChild(dsSvgEl('rect', { x:0, y:DS_HDR_H-8, width:DS_W, height:8, fill:'#1a3d2b' }));

    // Table name (editable on dblclick)
    const title = dsSvgEl('text', { x:12, y:26, fill:'#3ECF8E',
        'font-weight':'700', 'font-size':'13',
        'font-family':'-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif',
        cursor:'pointer' });
    title.textContent = tbl.name;
    title.addEventListener('dblclick', (e) => { e.stopPropagation(); dsEditTableName(id); });
    g.appendChild(title);

    // Delete table btn
    const delBtn = dsSvgEl('text', { x: DS_W-12, y:26, fill:'#e53e3e',
        'font-size':'14', 'text-anchor':'end', cursor:'pointer', opacity:'0.7' });
    delBtn.textContent = '×';
    delBtn.addEventListener('click', (e) => { e.stopPropagation(); dsDeleteTable(id); });
    g.appendChild(delBtn);

    // Drag handle (header)
    const drag = dsSvgEl('rect', { x:0, y:0, width:DS_W-24, height:DS_HDR_H,
        fill:'transparent', cursor:'grab', class:'ds-drag-handle' });
    drag.addEventListener('mousedown', (e) => { e.stopPropagation(); dsStartDrag(e, id); });
    g.appendChild(drag);

    // Divider
    g.appendChild(dsSvgEl('line', { x1:0, y1:DS_HDR_H, x2:DS_W, y2:DS_HDR_H,
        stroke:'#3ECF8E', 'stroke-width':1, opacity:'0.3' }));

    // Columns
    tbl.columns.forEach((col, ci) => {
        const cy = DS_HDR_H + ci * DS_COL_H;

        if (ci > 0) {
            g.appendChild(dsSvgEl('line', { x1:8, y1:cy, x2:DS_W-8, y2:cy,
                stroke:'#333', 'stroke-width':0.5 }));
        }

        // Row hover
        const rowBg = dsSvgEl('rect', { x:1, y:cy+1, width:DS_W-2, height:DS_COL_H-1,
            fill:'transparent', class:'ds-col-row', rx:2, cursor:'pointer' });
        rowBg.addEventListener('click', (e) => { e.stopPropagation(); dsEditColumn(id, col.id); });
        g.appendChild(rowBg);

        // PK icon
        if (col.pk) {
            const pk = dsSvgEl('text', { x:8, y:cy+22, 'font-size':'10' });
            pk.textContent = '🔑';
            g.appendChild(pk);
        }

        // Col name
        const cname = dsSvgEl('text', {
            x: col.pk ? 24 : 10, y: cy + 22,
            fill: col.pk ? '#f6e05e' : '#d1d5db',
            'font-size':'12',
            'font-family':'SF Mono,Fira Code,Consolas,monospace',
            'font-weight': col.pk ? '600' : '400'
        });
        cname.textContent = dsEllipsis(col.name, 16);
        g.appendChild(cname);

        // Type
        const ctype = dsSvgEl('text', {
            x: DS_W - 22, y: cy + 22,
            fill:'#555', 'font-size':'10', 'text-anchor':'end',
            'font-family':'SF Mono,Fira Code,Consolas,monospace'
        });
        ctype.textContent = dsShortType(col.type);
        g.appendChild(ctype);

        // Badges NOT NULL / UNIQUE
        if (col.notnull && !col.pk) {
            const nn = dsSvgEl('text', { x: DS_W-8, y: cy+17, fill:'#e53e3e',
                'font-size':'7', 'text-anchor':'end',
                'font-family':'-apple-system,sans-serif' });
            nn.textContent = 'NN';
            g.appendChild(nn);
        }
        if (col.unique) {
            const uq = dsSvgEl('text', { x: DS_W-8, y: cy+26, fill:'#90cdf4',
                'font-size':'7', 'text-anchor':'end',
                'font-family':'-apple-system,sans-serif' });
            uq.textContent = 'UQ';
            g.appendChild(uq);
        }

        // FK anchor dot (kanan)
        const dot = dsSvgEl('circle', {
            cx: DS_W, cy: cy + DS_COL_H/2,
            r: 5, fill:'#3ECF8E', opacity:'0.5',
            cursor:'crosshair', class:'ds-fk-dot',
            'data-table': id, 'data-col': col.id
        });
        dot.addEventListener('mousedown', (e) => {
            e.stopPropagation();
            dsStartFkDrag(e, id, col.id);
        });
        dot.addEventListener('mouseenter', () => dot.setAttribute('opacity','1'));
        dot.addEventListener('mouseleave', () => dot.setAttribute('opacity','0.5'));
        g.appendChild(dot);

        // FK target dot (kiri)
        const dotL = dsSvgEl('circle', {
            cx: 0, cy: cy + DS_COL_H/2,
            r: 5, fill:'#90cdf4', opacity:'0.3',
            cursor:'crosshair', class:'ds-fk-target',
            'data-table': id, 'data-col': col.id
        });
        dotL.addEventListener('mouseenter', () => dotL.setAttribute('opacity','0.9'));
        dotL.addEventListener('mouseleave', () => dotL.setAttribute('opacity','0.3'));
        g.appendChild(dotL);
    });

    // Add column button
    const addY = DS_HDR_H + tbl.columns.length * DS_COL_H + 4;
    const addBtn = dsSvgEl('text', { x: DS_W/2, y: addY + 14,
        fill:'#3ECF8E', 'font-size':'11', 'text-anchor':'middle',
        cursor:'pointer', opacity:'0.7' });
    addBtn.textContent = '＋ Tambah Kolom';
    addBtn.addEventListener('mouseenter', () => addBtn.setAttribute('opacity','1'));
    addBtn.addEventListener('mouseleave', () => addBtn.setAttribute('opacity','0.7'));
    addBtn.addEventListener('click', (e) => { e.stopPropagation(); dsAddColumn(id); });
    g.appendChild(addBtn);

    dsNodes.appendChild(g);
}

function dsDrawEdge(fk) {
    const from = dsTables[fk.fromTable];
    const to   = dsTables[fk.toTable];
    if (!from || !to) return;

    const fromColIdx = from.columns.findIndex(c => c.id === fk.fromCol);
    const toColIdx   = to.columns.findIndex(c => c.id === fk.toCol);
    if (fromColIdx < 0 || toColIdx < 0) return;

    const fy = from.y + DS_HDR_H + fromColIdx * DS_COL_H + DS_COL_H/2;
    const ty = to.y   + DS_HDR_H + toColIdx   * DS_COL_H + DS_COL_H/2;

    // Pilih sisi kanan atau kiri
    const fromCx = from.x + DS_W/2;
    const toCx   = to.x   + DS_W/2;
    let x1, x2;
    if (fromCx <= toCx) { x1 = from.x + DS_W; x2 = to.x; }
    else                 { x1 = from.x;          x2 = to.x + DS_W; }

    const dx   = Math.abs(x2 - x1) * 0.5;
    const path = `M${x1},${fy} C${x1+(x1<x2?dx:-dx)},${fy} ${x2+(x1<x2?-dx:dx)},${ty} ${x2},${ty}`;

    const line = dsSvgEl('path', {
        d: path, fill:'none', stroke:'#3ECF8E',
        'stroke-width':1.8, opacity:'0.6',
        'marker-end':'url(#ds-arrow)',
        class:'ds-fk-edge', 'data-fk-id': fk.id,
        cursor:'pointer'
    });
    line.addEventListener('click', () => dsDeleteFk(fk.id));
    line.addEventListener('mouseenter', function() {
        this.setAttribute('opacity','1');
        this.setAttribute('stroke-width','2.5');
    });
    line.addEventListener('mouseleave', function() {
        this.setAttribute('opacity','0.6');
        this.setAttribute('stroke-width','1.8');
    });

    // Label FK
    const mx   = (x1 + x2) / 2;
    const my   = (fy + ty) / 2 - 8;
    const lbl  = dsSvgEl('text', { x:mx, y:my, fill:'#3ECF8E',
        'font-size':'9', 'text-anchor':'middle',
        'font-family':'-apple-system,sans-serif', opacity:'0.7' });
    lbl.textContent = 'FK';
    dsEdges.appendChild(lbl);
    dsEdges.appendChild(line);
}

// ══════════════════════════════════════════════════════════════
//  Drag tabel
// ══════════════════════════════════════════════════════════════
function dsStartDrag(e, id) {
    dsDragState = { id, startX: e.clientX, startY: e.clientY,
                    origX: dsTables[id].x, origY: dsTables[id].y };
    document.addEventListener('mousemove', dsOnDrag);
    document.addEventListener('mouseup',   dsStopDrag);
}
function dsOnDrag(e) {
    if (!dsDragState) return;
    const dx = (e.clientX - dsDragState.startX) / dsScale;
    const dy = (e.clientY - dsDragState.startY) / dsScale;
    dsTables[dsDragState.id].x = dsDragState.origX + dx;
    dsTables[dsDragState.id].y = dsDragState.origY + dy;
    dsRender();
}
function dsStopDrag() {
    dsDragState = null;
    document.removeEventListener('mousemove', dsOnDrag);
    document.removeEventListener('mouseup',   dsStopDrag);
}

// ══════════════════════════════════════════════════════════════
//  Pan canvas
// ══════════════════════════════════════════════════════════════
dsWrap.addEventListener('mousedown', e => {
    if (e.target.closest('.ds-drag-handle') || e.target.closest('.ds-fk-dot')) return;
    dsPanning  = true;
    dsPanStart = { x: e.clientX - dsPanX, y: e.clientY - dsPanY };
    dsWrap.style.cursor = 'grabbing';
});
document.addEventListener('mousemove', e => {
    if (!dsPanning) return;
    dsPanX = e.clientX - dsPanStart.x;
    dsPanY = e.clientY - dsPanStart.y;
    dsApplyTransform();
});
document.addEventListener('mouseup', () => { dsPanning = false; dsWrap.style.cursor = ''; });

// Double-click canvas kosong → tambah tabel
dsWrap.addEventListener('dblclick', e => {
    if (e.target !== dsSvg && e.target.id !== 'ds-svg' && e.target.tagName !== 'svg') return;
    const rect = dsWrap.getBoundingClientRect();
    const x = (e.clientX - rect.left - dsPanX) / dsScale - DS_W/2;
    const y = (e.clientY - rect.top  - dsPanY) / dsScale - DS_HDR_H/2;
    dsAddTable(x, y);
});

// Zoom
dsWrap.addEventListener('wheel', e => {
    e.preventDefault();
    const delta = e.deltaY < 0 ? 0.1 : -0.1;
    const rect  = dsWrap.getBoundingClientRect();
    const mx    = e.clientX - rect.left;
    const my    = e.clientY - rect.top;
    const ns    = Math.min(3, Math.max(0.2, dsScale + delta));
    dsPanX = mx - (mx - dsPanX) * (ns / dsScale);
    dsPanY = my - (my - dsPanY) * (ns / dsScale);
    dsScale = ns;
    dsApplyTransform();
}, { passive: false });

function dsApplyTransform() {
    dsRoot.setAttribute('transform', `translate(${dsPanX},${dsPanY}) scale(${dsScale})`);
}

// ══════════════════════════════════════════════════════════════
//  FK drag
// ══════════════════════════════════════════════════════════════
function dsStartFkDrag(e, tableId, colId) {
    const tbl    = dsTables[tableId];
    const colIdx = tbl.columns.findIndex(c => c.id === colId);
    dsFkDrag = { tableId, colId,
        sx: tbl.x + DS_W, sy: tbl.y + DS_HDR_H + colIdx * DS_COL_H + DS_COL_H/2 };
    document.addEventListener('mousemove', dsFkMove);
    document.addEventListener('mouseup',   dsFkDrop);
}

function dsFkMove(e) {
    if (!dsFkDrag) return;
    const rect = dsWrap.getBoundingClientRect();
    const mx   = (e.clientX - rect.left - dsPanX) / dsScale;
    const my   = (e.clientY - rect.top  - dsPanY) / dsScale;
    const dx   = Math.abs(mx - dsFkDrag.sx) * 0.5;
    dsDragLine.setAttribute('d',
        `M${dsFkDrag.sx},${dsFkDrag.sy} C${dsFkDrag.sx+dx},${dsFkDrag.sy} ${mx-dx},${my} ${mx},${my}`);
    // Transform ke koordinat svg
    const svgPt = `translate(${dsPanX},${dsPanY}) scale(${dsScale})`;
    document.getElementById('ds-drag-svg').querySelector('path').setAttribute('transform',
        `translate(${dsPanX},${dsPanY}) scale(${dsScale})`);
}

function dsFkDrop(e) {
    dsDragLine.setAttribute('d', '');
    document.removeEventListener('mousemove', dsFkMove);
    document.removeEventListener('mouseup',   dsFkDrop);
    if (!dsFkDrag) return;

    // Cari dot target di bawah kursor
    const rect  = dsWrap.getBoundingClientRect();
    const elems = document.elementsFromPoint(e.clientX, e.clientY);
    const target = elems.find(el => el.classList.contains('ds-fk-target') || el.classList.contains('ds-fk-dot'));

    if (target) {
        const toTable = target.getAttribute('data-table');
        const toCol   = target.getAttribute('data-col');
        if (toTable && toCol && toTable !== dsFkDrag.tableId) {
            // Hindari duplikat
            const exists = dsFKs.find(fk =>
                fk.fromTable === dsFkDrag.tableId && fk.fromCol === dsFkDrag.colId &&
                fk.toTable === toTable && fk.toCol === toCol);
            if (!exists) {
                dsFKs.push({ id:'fk_'+(dsNextId++),
                    fromTable: dsFkDrag.tableId, fromCol: dsFkDrag.colId,
                    toTable, toCol });
                dsRender();
                dsUpdateStat();
            }
        }
    }
    dsFkDrag = null;
}

// ══════════════════════════════════════════════════════════════
//  Edit tabel & kolom (floating form)
// ══════════════════════════════════════════════════════════════
function dsEditTableName(id) {
    const tbl = dsTables[id];
    const old = tbl.name;
    const val = prompt('Nama tabel:', old);
    if (val === null) return;
    const name = val.trim().replace(/\s+/g, '_');
    if (!name) return;
    tbl.name = name;
    dsRender();
}

function dsDeleteTable(id) {
    if (!confirm(`Hapus tabel "${dsTables[id].name}" dari canvas?`)) return;
    delete dsTables[id];
    dsFKs = dsFKs.filter(fk => fk.fromTable !== id && fk.toTable !== id);
    dsRender(); dsUpdateStat();
}

function dsAddColumn(tableId) {
    const tbl = dsTables[tableId];
    const col = { id:'col_'+(dsNextId++), name:'kolom_'+tbl.columns.length,
                  type:'varchar', pk:false, notnull:false, unique:false, default:'' };
    tbl.columns.push(col);
    dsRender();
    dsEditColumn(tableId, col.id);
}

function dsEditColumn(tableId, colId) {
    const tbl = dsTables[tableId];
    const col = tbl.columns.find(c => c.id === colId);
    if (!col) return;

    // Hapus form lama jika ada
    document.getElementById('ds-col-form')?.remove();

    const tblEl  = dsWrap.getBoundingClientRect();
    const tblObj = dsTables[tableId];
    const colIdx = tbl.columns.findIndex(c => c.id === colId);

    // Posisi form
    const svgX = tblObj.x * dsScale + dsPanX + DS_W * dsScale + 8;
    const svgY = (tblObj.y + DS_HDR_H + colIdx * DS_COL_H) * dsScale + dsPanY;

    const form = document.createElement('div');
    form.id = 'ds-col-form';
    form.className = 'ds-col-form';
    form.style.left = Math.min(svgX, dsWrap.clientWidth - 280) + 'px';
    form.style.top  = Math.max(4, svgY) + 'px';

    const typeOpts = DS_PG_TYPES.map(t =>
        `<option value="${t}" ${t===col.type?'selected':''}>${t}</option>`).join('');

    form.innerHTML = `
        <div class="ds-form-header">
            <span>Edit Kolom</span>
            <button onclick="document.getElementById('ds-col-form').remove()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:16px">×</button>
        </div>
        <div class="ds-form-row">
            <label>Nama</label>
            <input id="dcf-name" type="text" value="${dsEscHtml(col.name)}" placeholder="nama_kolom">
        </div>
        <div class="ds-form-row">
            <label>Tipe Data</label>
            <select id="dcf-type">${typeOpts}</select>
        </div>
        <div class="ds-form-row">
            <label>Default</label>
            <input id="dcf-default" type="text" value="${dsEscHtml(col.default)}" placeholder="nilai default...">
        </div>
        <div class="ds-form-checks">
            <label><input type="checkbox" id="dcf-pk"      ${col.pk?'checked':''}> Primary Key</label>
            <label><input type="checkbox" id="dcf-nn"      ${col.notnull?'checked':''}> NOT NULL</label>
            <label><input type="checkbox" id="dcf-unique"  ${col.unique?'checked':''}> UNIQUE</label>
        </div>
        <div class="ds-form-footer">
            <button class="btn btn-outline btn-sm ds-btn-del-col" onclick="dsDeleteColumn('${tableId}','${colId}')">🗑 Hapus</button>
            <button class="btn btn-primary btn-sm" onclick="dsSaveColumn('${tableId}','${colId}')">✓ Simpan</button>
        </div>
    `;
    dsWrap.appendChild(form);
    document.getElementById('dcf-name').focus();
    document.getElementById('dcf-name').select();

    // Save on Enter
    form.addEventListener('keydown', e => {
        if (e.key === 'Enter') dsSaveColumn(tableId, colId);
        if (e.key === 'Escape') form.remove();
    });
}

function dsSaveColumn(tableId, colId) {
    const tbl = dsTables[tableId];
    const col = tbl.columns.find(c => c.id === colId);
    if (!col) return;
    col.name    = document.getElementById('dcf-name').value.trim() || col.name;
    col.type    = document.getElementById('dcf-type').value;
    col.default = document.getElementById('dcf-default').value.trim();
    col.pk      = document.getElementById('dcf-pk').checked;
    col.notnull = document.getElementById('dcf-nn').checked || col.pk;
    col.unique  = document.getElementById('dcf-unique').checked;
    document.getElementById('ds-col-form')?.remove();
    dsRender();
}

function dsDeleteColumn(tableId, colId) {
    const tbl = dsTables[tableId];
    if (tbl.columns.length <= 1) { alert('Tabel harus punya minimal 1 kolom.'); return; }
    tbl.columns = tbl.columns.filter(c => c.id !== colId);
    dsFKs = dsFKs.filter(fk => !(fk.fromTable===tableId && fk.fromCol===colId) &&
                                !(fk.toTable===tableId   && fk.toCol===colId));
    document.getElementById('ds-col-form')?.remove();
    dsRender(); dsUpdateStat();
}

function dsDeleteFk(fkId) {
    if (!confirm('Hapus relasi FK ini?')) return;
    dsFKs = dsFKs.filter(fk => fk.id !== fkId);
    dsRender(); dsUpdateStat();
}

// ══════════════════════════════════════════════════════════════
//  Generate SQL
// ══════════════════════════════════════════════════════════════
function dsGenerateSql() {
    const sqls = [];
    const tableNames = {};  // id → name

    Object.entries(dsTables).forEach(([id, tbl]) => {
        tableNames[id] = tbl.name;
    });

    // CREATE TABLE
    Object.entries(dsTables).forEach(([id, tbl]) => {
        const colDefs = tbl.columns.map(col => {
            let def = `  "${col.name}" ${col.type}`;
            if (col.pk)      def += ' PRIMARY KEY';
            else {
                if (col.notnull) def += ' NOT NULL';
                if (col.unique)  def += ' UNIQUE';
            }
            if (col.default) def += ` DEFAULT ${col.default}`;
            return def;
        });
        sqls.push(`CREATE TABLE "${DS_SCHEMA}"."${tbl.name}" (\n${colDefs.join(',\n')}\n);`);
    });

    // ALTER TABLE ADD CONSTRAINT FK
    dsFKs.forEach(fk => {
        const fromTbl = dsTables[fk.fromTable];
        const toTbl   = dsTables[fk.toTable];
        if (!fromTbl || !toTbl) return;
        const fromCol = fromTbl.columns.find(c => c.id === fk.fromCol);
        const toCol   = toTbl.columns.find(c => c.id === fk.toCol);
        if (!fromCol || !toCol) return;
        const cname = `fk_${fromTbl.name}_${fromCol.name}`;
        sqls.push(
            `ALTER TABLE "${DS_SCHEMA}"."${fromTbl.name}"\n` +
            `  ADD CONSTRAINT "${cname}"\n` +
            `  FOREIGN KEY ("${fromCol.name}")\n` +
            `  REFERENCES "${DS_SCHEMA}"."${toTbl.name}" ("${toCol.name}");`
        );
    });

    return sqls;
}

function dsPreviewSql() {
    if (!Object.keys(dsTables).length) { alert('Belum ada tabel di canvas.'); return; }
    const sqls = dsGenerateSql();
    document.getElementById('ds-sql-preview').textContent = sqls.join('\n\n');
    openModal('ds-modal-sql');
}

function dsCopySql() {
    const text = document.getElementById('ds-sql-preview').textContent;
    navigator.clipboard.writeText(text).then(() => alert('SQL disalin ke clipboard!'));
}

function dsExecute() {
    if (!Object.keys(dsTables).length) { alert('Belum ada tabel di canvas.'); return; }
    const sqls = dsGenerateSql();
    document.getElementById('ds-execute-preview').textContent = sqls.join('\n\n');
    document.getElementById('ds-sql-input').value = JSON.stringify(sqls);
    openModal('ds-modal-execute');
}

// ══════════════════════════════════════════════════════════════
//  Helpers
// ══════════════════════════════════════════════════════════════
function dsUpdateStat() {
    const t = Object.keys(dsTables).length;
    const f = dsFKs.length;
    document.getElementById('ds-stat').textContent =
        t ? `${t} tabel · ${f} relasi` : '';
}

function dsSvgEl(tag, attrs) {
    const el = document.createElementNS('http://www.w3.org/2000/svg', tag);
    Object.entries(attrs).forEach(([k,v]) => el.setAttribute(k, v));
    return el;
}
function dsShortType(t) {
    const m = { 'character varying':'varchar','timestamp without time zone':'timestamp',
                'timestamp with time zone':'timestamptz','double precision':'float8',
                'integer':'int4','bigint':'int8','boolean':'bool','character':'char' };
    return m[t] || t;
}
function dsEllipsis(s, max) { return s.length > max ? s.slice(0,max-1)+'…' : s; }
function dsEscHtml(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;'); }

// Init
dsUpdateStat();
</script>

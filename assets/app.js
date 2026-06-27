// ══════════════════════════════════════════════════════════════
//  PG Manager — Global JS
// ══════════════════════════════════════════════════════════════

// ── Modal helpers ─────────────────────────────────────────────
function openModal(id) {
    document.getElementById(id).style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
    document.body.style.overflow = '';
}
function openInsertModal()    { openModal('modal-insert'); }
function openStructureModal() { openModal('modal-structure'); }
function openDropModal()      { openModal('modal-drop'); }
function confirmDrop()        { return true; }

document.addEventListener('click', e => {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.style.display = 'none';
        document.body.style.overflow = '';
    }
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay').forEach(m => m.style.display = 'none');
        document.body.style.overflow = '';
        // Close export menus
        document.querySelectorAll('.export-menu.open').forEach(m => m.classList.remove('open'));
    }
});

// ── Export dropdown ───────────────────────────────────────────
function toggleExportMenu(id) {
    const menu = document.getElementById(id);
    const isOpen = menu.classList.toggle('open');
    if (isOpen) {
        // Tunggu event saat ini selesai sebelum pasang handler tutup,
        // agar klik yang sama tidak langsung menutup menu.
        setTimeout(() => {
            document.addEventListener('click', function handler(e) {
                if (!menu.closest('.export-wrap').contains(e.target)) {
                    menu.classList.remove('open');
                    document.removeEventListener('click', handler);
                }
            });
        }, 0);
    }
}

// ══════════════════════════════════════════════════════════════
//  1. DARK / LIGHT MODE
// ══════════════════════════════════════════════════════════════
(function initTheme() {
    const saved = localStorage.getItem('pgm_theme') || 'dark';
    applyTheme(saved);
})();

function applyTheme(theme) {
    document.documentElement.classList.toggle('light', theme === 'light');
    localStorage.setItem('pgm_theme', theme);
    const btn = document.getElementById('theme-toggle');
    if (btn) btn.textContent = theme === 'light' ? '🌙' : '☀️';
}

function toggleTheme() {
    const current = localStorage.getItem('pgm_theme') || 'dark';
    applyTheme(current === 'dark' ? 'light' : 'dark');
}

// ══════════════════════════════════════════════════════════════
//  2. FAVORIT TABEL
// ══════════════════════════════════════════════════════════════
function getFavs() {
    try { return JSON.parse(localStorage.getItem('pgm_favs') || '[]'); } catch { return []; }
}
function saveFavs(favs) { localStorage.setItem('pgm_favs', JSON.stringify(favs)); }

function pinTable(schema, table) {
    const favs = getFavs();
    const key  = `${schema}.${table}`;
    if (!favs.includes(key)) { favs.unshift(key); saveFavs(favs); }
    renderFavs();
}

function unpinTable(schema, table, e) {
    if (e) e.stopPropagation();
    const key  = `${schema}.${table}`;
    const favs = getFavs().filter(f => f !== key);
    saveFavs(favs);
    renderFavs();
}

function renderFavs() {
    const container = document.getElementById('fav-list');
    if (!container) return;
    const favs = getFavs();
    if (!favs.length) { container.innerHTML = ''; return; }

    // Dapatkan schema & page dari URL
    const params  = new URLSearchParams(window.location.search);
    const curSchema = params.get('schema') || 'public';

    let html = `<div class="sidebar-fav-label">⭐ Favorit</div>`;
    favs.forEach(key => {
        const [s, t] = key.split('.', 2);
        const url = `app.php?page=table-editor&schema=${encodeURIComponent(s)}&table=${encodeURIComponent(t)}`;
        html += `
        <a href="${url}" class="nav-item nav-item-sub nav-item-fav">
            <svg width="12" height="12" fill="currentColor" viewBox="0 0 16 16">
                <path d="M3.612 15.443c-.386.198-.824-.149-.746-.592l.83-4.73L.173 6.765c-.329-.314-.158-.888.283-.95l4.898-.696L7.538.792c.197-.39.73-.39.927 0l2.184 4.327 4.898.696c.441.062.612.636.282.95l-3.522 3.356.83 4.73c.078.443-.36.79-.746.592L8 13.187l-4.389 2.256z"/>
            </svg>
            ${escHtml(t)}
            <button class="btn-unpin" onclick="unpinTable('${escJs(s)}','${escJs(t)}',event)" title="Lepas pin">✕</button>
        </a>`;
    });
    container.innerHTML = html;
}

// Tombol pin di sidebar table list
function togglePin(schema, table, e) {
    e.preventDefault(); e.stopPropagation();
    const key  = `${schema}.${table}`;
    const favs = getFavs();
    if (favs.includes(key)) unpinTable(schema, table);
    else pinTable(schema, table);
}

// Init favs on load
document.addEventListener('DOMContentLoaded', renderFavs);

// ══════════════════════════════════════════════════════════════
//  3. SQL MULTI-TAB + HISTORY
// ══════════════════════════════════════════════════════════════
const SQL_TABS_KEY    = 'pgm_sql_tabs';
const SQL_ACTIVE_KEY  = 'pgm_sql_active';
const SQL_HISTORY_KEY = 'pgm_sql_history';
const MAX_HISTORY     = 100;

function getSqlTabs() {
    try {
        const t = JSON.parse(localStorage.getItem(SQL_TABS_KEY));
        if (t && t.length) return t;
    } catch {}
    return [{ id: 1, name: 'Query 1', sql: '' }];
}
function saveSqlTabs(tabs) { localStorage.setItem(SQL_TABS_KEY, JSON.stringify(tabs)); }
function getActiveTabId() { return parseInt(localStorage.getItem(SQL_ACTIVE_KEY) || '1'); }
function setActiveTabId(id) { localStorage.setItem(SQL_ACTIVE_KEY, id); }

function getSqlHistory() {
    try { return JSON.parse(localStorage.getItem(SQL_HISTORY_KEY) || '[]'); } catch { return []; }
}
function addSqlHistory(sql, duration, rows) {
    const hist = getSqlHistory();
    hist.unshift({ sql, duration, rows, ts: Date.now() });
    if (hist.length > MAX_HISTORY) hist.pop();
    localStorage.setItem(SQL_HISTORY_KEY, JSON.stringify(hist));
}
function clearSqlHistory() {
    localStorage.removeItem(SQL_HISTORY_KEY);
    renderHistory();
}
function deleteHistoryItem(idx) {
    const hist = getSqlHistory();
    hist.splice(idx, 1);
    localStorage.setItem(SQL_HISTORY_KEY, JSON.stringify(hist));
    renderHistory();
}

function renderSqlTabs() {
    const tabBar = document.getElementById('sql-tab-bar');
    if (!tabBar) return;
    const tabs     = getSqlTabs();
    const activeId = getActiveTabId();
    tabBar.innerHTML = tabs.map(tab => `
        <div class="sql-tab ${tab.id === activeId ? 'active' : ''}"
             onclick="switchTab(${tab.id})" title="${escHtml(tab.sql.slice(0,60))}">
            <span class="sql-tab-name">${escHtml(tab.name)}</span>
            <button class="sql-tab-close" onclick="closeTab(${tab.id}, event)" title="Tutup">×</button>
        </div>`).join('') +
        `<button class="sql-tab-add" onclick="addTab()" title="Tab baru">+</button>`;
}

function switchTab(id) {
    // Simpan SQL aktif sebelum pindah
    const textarea = document.getElementById('sql-input');
    if (textarea) {
        const tabs = getSqlTabs();
        const cur  = tabs.find(t => t.id === getActiveTabId());
        if (cur) { cur.sql = textarea.value; saveSqlTabs(tabs); }
    }
    setActiveTabId(id);
    const tabs   = getSqlTabs();
    const tab    = tabs.find(t => t.id === id);
    if (tab && textarea) {
        textarea.value = tab.sql;
        updateLineNumbers && updateLineNumbers();
    }
    renderSqlTabs();
}

function addTab() {
    const tabs  = getSqlTabs();
    const newId = Math.max(0, ...tabs.map(t => t.id)) + 1;
    tabs.push({ id: newId, name: `Query ${newId}`, sql: '' });
    saveSqlTabs(tabs);
    switchTab(newId);
}

function closeTab(id, e) {
    e.stopPropagation();
    let tabs = getSqlTabs();
    if (tabs.length === 1) { tabs[0].sql = ''; saveSqlTabs(tabs); switchTab(tabs[0].id); return; }
    tabs = tabs.filter(t => t.id !== id);
    saveSqlTabs(tabs);
    if (getActiveTabId() === id) switchTab(tabs[tabs.length - 1].id);
    else renderSqlTabs();
}

function renderHistory() {
    const list = document.getElementById('history-list');
    if (!list) return;
    const hist = getSqlHistory();
    if (!hist.length) {
        list.innerHTML = '<div style="padding:16px;text-align:center;color:var(--text-muted);font-size:12px">Belum ada history</div>';
        return;
    }
    list.innerHTML = hist.map((h, i) => `
        <div class="sql-history-item" onclick="pasteHistory(${i})">
            <div class="history-sql">${escHtml(h.sql.replace(/\s+/g,' ').trim())}</div>
            <div class="history-meta">
                <span>${timeAgo(h.ts)}</span>
                <span>${h.rows != null ? h.rows + ' baris' : ''} ${h.duration ? h.duration + 'ms' : ''}</span>
                <button class="history-delete" onclick="deleteHistoryItem(${i});event.stopPropagation()" title="Hapus">✕</button>
            </div>
        </div>`).join('');
}

function pasteHistory(idx) {
    const h = getSqlHistory()[idx];
    if (!h) return;
    const textarea = document.getElementById('sql-input');
    if (textarea) {
        textarea.value = h.sql;
        typeof updateLineNumbers === 'function' && updateLineNumbers();
        textarea.focus();
        // Simpan ke tab aktif
        const tabs = getSqlTabs();
        const cur  = tabs.find(t => t.id === getActiveTabId());
        if (cur) { cur.sql = h.sql; saveSqlTabs(tabs); }
    }
}

function toggleHistoryPanel() {
    const panel = document.getElementById('history-panel');
    const btn   = document.getElementById('btn-history-toggle');
    if (!panel) return;
    const open = panel.style.display !== 'none';
    panel.style.display = open ? 'none' : 'flex';
    if (btn) btn.classList.toggle('active', !open);
    if (!open) renderHistory();
}

// ── Helpers ──────────────────────────────────────────────────
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escJs(s) { return String(s).replace(/'/g,"\\'"); }

function timeAgo(ts) {
    const sec = Math.floor((Date.now() - ts) / 1000);
    if (sec < 60)   return `${sec}d lalu`;
    if (sec < 3600) return `${Math.floor(sec/60)}m lalu`;
    if (sec < 86400)return `${Math.floor(sec/3600)}j lalu`;
    return new Date(ts).toLocaleDateString('id-ID');
}

// ══════════════════════════════════════════════════════════════
//  4. FULLSCREEN MODE
// ══════════════════════════════════════════════════════════════
let _fsEl = null;
let _fsBtn = null;
let _fsExitBtn = null;

function toggleFullscreen(elId) {
    const el = document.getElementById(elId);
    if (!el) return;

    if (_fsEl) {
        exitFullscreen();
        return;
    }

    // Enter fullscreen
    _fsEl = el;
    el.classList.add('fullscreen-mode');

    // Tombol exit
    _fsExitBtn = document.createElement('button');
    _fsExitBtn.className = 'fs-exit-btn';
    _fsExitBtn.innerHTML = '✕ Keluar Fullscreen <kbd>Esc</kbd>';
    _fsExitBtn.onclick = exitFullscreen;
    document.body.appendChild(_fsExitBtn);

    // Update label tombol pemicu
    const triggerBtn = document.getElementById(
        elId === 'sql-fullscreen-wrap' ? 'btn-sql-fs' : 'btn-erd-fs'
    );
    if (triggerBtn) { _fsBtn = triggerBtn; triggerBtn.textContent = '⊠ Exit'; }

    // Coba Fullscreen API jika tersedia
    if (el.requestFullscreen) el.requestFullscreen().catch(() => {});

    // Resize ERD setelah masuk fullscreen
    if (elId === 'erd-wrap' || elId === 'erd-canvas-wrap') {
        setTimeout(() => typeof erdFit === 'function' && erdFit(), 150);
    }
    // Resize SQL textarea
    if (elId === 'sql-fullscreen-wrap') {
        const ta = document.getElementById('sql-input');
        if (ta) { ta.style.height = '100%'; ta.focus(); }
    }
}

function exitFullscreen() {
    if (!_fsEl) return;
    _fsEl.classList.remove('fullscreen-mode');

    if (_fsExitBtn) { _fsExitBtn.remove(); _fsExitBtn = null; }
    if (_fsBtn)     { _fsBtn.textContent = '⛶ Fullscreen'; _fsBtn = null; }

    if (document.fullscreenElement) document.exitFullscreen().catch(() => {});

    // Re-fit ERD
    if (_fsEl.id === 'erd-wrap' || _fsEl.id === 'erd-canvas-wrap') {
        setTimeout(() => typeof erdFit === 'function' && erdFit(), 100);
    }
    // Reset SQL textarea height
    if (_fsEl.id === 'sql-fullscreen-wrap') {
        const ta = document.getElementById('sql-input');
        if (ta) ta.style.height = '';
    }

    _fsEl = null;
}

// Keluar fullscreen saat Esc
document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && _fsEl) exitFullscreen();
});

// Sinkronisasi dengan browser Fullscreen API (tombol Esc browser)
document.addEventListener('fullscreenchange', () => {
    if (!document.fullscreenElement && _fsEl) exitFullscreen();
});

// ══════════════════════════════════════════════════════════════
//  5. RESIZE KOLOM TABEL
// ══════════════════════════════════════════════════════════════
function initTableResize(table) {
    if (!table || table.dataset.resizeInited) return;
    table.dataset.resizeInited = '1';

    const ths = table.querySelectorAll('thead th');

    // ── Auto-fit: baca lebar alami saat table-layout masih 'auto' ──
    // Snapshot lebar setiap kolom sebelum switch ke fixed
    const naturalWidths = [...ths].map(th => {
        const w = th.getBoundingClientRect().width;
        return Math.max(60, Math.ceil(w));
    });

    // Terapkan lebar eksplisit lalu switch ke fixed layout
    ths.forEach((th, i) => {
        th.style.width = naturalWidths[i] + 'px';
    });
    table.classList.add('resizable'); // triggers table-layout: fixed via CSS

    ths.forEach((th, idx) => {
        const handle = document.createElement('div');
        handle.className = 'col-resizer';
        handle.title = 'Drag untuk resize kolom';
        // Jangan append jika sudah ada (misal dari PHP)
        if (!th.querySelector('.col-resizer')) th.appendChild(handle);

        let startX, startW, isResizing = false;

        handle.addEventListener('mousedown', e => {
            e.preventDefault();
            isResizing = true;
            startX = e.clientX;
            startW = th.offsetWidth;
            th.classList.add('resizing');
            handle.classList.add('resizing');
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';

            const onMove = e => {
                if (!isResizing) return;
                const newW = Math.max(60, startW + (e.clientX - startX));
                th.style.width = newW + 'px';
                // Sinkronkan lebar kolom td
                const cells = table.querySelectorAll(`tbody tr td:nth-child(${idx + 1})`);
                cells.forEach(td => td.style.maxWidth = newW + 'px');
            };

            const onUp = () => {
                isResizing = false;
                th.classList.remove('resizing');
                handle.classList.remove('resizing');
                document.body.style.cursor = '';
                document.body.style.userSelect = '';
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
            };

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });
    });
}

// Auto-init semua .data-table yang ada dan yang akan datang
function initAllTables() {
    document.querySelectorAll('.data-table').forEach(t => initTableResize(t));
}

document.addEventListener('DOMContentLoaded', initAllTables);

// Observer untuk tabel yang di-render setelah load (result SQL, dll)
const _tableObserver = new MutationObserver(mutations => {
    mutations.forEach(m => {
        m.addedNodes.forEach(node => {
            if (node.nodeType !== 1) return;
            if (node.matches?.('.data-table')) initTableResize(node);
            node.querySelectorAll?.('.data-table').forEach(t => initTableResize(t));
        });
    });
});
_tableObserver.observe(document.body, { childList: true, subtree: true });

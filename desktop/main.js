const { app, BrowserWindow, shell, dialog, Menu } = require('electron');
const { spawn, execSync } = require('child_process');
const path = require('path');
const net  = require('net');
const fs   = require('fs');

app.setName('PG Manager');

// ── Lokasi PHP dan app ────────────────────────────────────────
const isDev  = !app.isPackaged;
const appDir = isDev
    ? path.resolve(__dirname, '..')  // development: satu level di atas desktop/
    : path.join(process.resourcesPath, 'app');

const PHP_CANDIDATES = [
    '/Applications/XAMPP/xamppfiles/bin/php',
    '/opt/homebrew/bin/php',
    '/usr/local/bin/php',
    '/usr/bin/php',
];

function findPhp() {
    for (const p of PHP_CANDIDATES) {
        if (fs.existsSync(p)) return p;
    }
    // Fallback: cari di PATH
    try {
        return execSync('which php').toString().trim();
    } catch {
        return null;
    }
}

// ── Cari port bebas ───────────────────────────────────────────
function findFreePort(start = 8765) {
    return new Promise((resolve, reject) => {
        const srv = net.createServer();
        srv.listen(start, '127.0.0.1', () => {
            const port = srv.address().port;
            srv.close(() => resolve(port));
        });
        srv.on('error', () => findFreePort(start + 1).then(resolve).catch(reject));
    });
}

// ── Tunggu sampai server siap ─────────────────────────────────
function waitForServer(port, retries = 30) {
    return new Promise((resolve, reject) => {
        let attempt = 0;
        const check = () => {
            const sock = net.connect(port, '127.0.0.1', () => {
                sock.destroy();
                resolve();
            });
            sock.on('error', () => {
                attempt++;
                if (attempt >= retries) {
                    reject(new Error('Server PHP tidak merespons.'));
                } else {
                    setTimeout(check, 200);
                }
            });
        };
        check();
    });
}

// ── State global ──────────────────────────────────────────────
let phpProcess = null;
let mainWindow = null;
let serverPort = null;

// ── Buat jendela utama ────────────────────────────────────────
function createWindow(port) {
    mainWindow = new BrowserWindow({
        width: 1280,
        height: 800,
        minWidth: 900,
        minHeight: 600,
        title: 'PG Manager',
        backgroundColor: '#1a1a1a',
        webPreferences: {
            preload: path.join(__dirname, 'preload.js'),
            contextIsolation: true,
            nodeIntegration: false,
        },
        titleBarStyle: 'default',
        show: false,
    });

    // Buka di window, bukan browser luar
    mainWindow.webContents.setWindowOpenHandler(({ url }) => {
        if (url.startsWith('http://127.0.0.1') || url.startsWith('http://localhost')) {
            return { action: 'allow' };
        }
        shell.openExternal(url);
        return { action: 'deny' };
    });

    mainWindow.loadURL(`http://127.0.0.1:${port}/`);

    mainWindow.once('ready-to-show', () => {
        mainWindow.show();
    });

    mainWindow.on('closed', () => {
        mainWindow = null;
    });

    buildMenu(port);
}

// ── Menu aplikasi ─────────────────────────────────────────────
function buildMenu(port) {
    const template = [
        {
            label: 'PG Manager',
            submenu: [
                { label: 'Tentang PG Manager', role: 'about' },
                { type: 'separator' },
                { label: 'Keluar', accelerator: 'CmdOrCtrl+Q', click: () => app.quit() },
            ],
        },
        {
            label: 'Edit',
            submenu: [
                { role: 'undo' }, { role: 'redo' }, { type: 'separator' },
                { role: 'cut' }, { role: 'copy' }, { role: 'paste' }, { role: 'selectAll' },
            ],
        },
        {
            label: 'Tampilan',
            submenu: [
                { role: 'reload' },
                { role: 'forceReload' },
                { role: 'toggleDevTools' },
                { type: 'separator' },
                { role: 'resetZoom' },
                { role: 'zoomIn' },
                { role: 'zoomOut' },
                { type: 'separator' },
                { role: 'togglefullscreen' },
            ],
        },
        {
            label: 'Bantuan',
            submenu: [
                {
                    label: 'Buka di Browser',
                    click: () => shell.openExternal(`http://127.0.0.1:${port}/`),
                },
            ],
        },
    ];
    Menu.setApplicationMenu(Menu.buildFromTemplate(template));
}

// ── Main ──────────────────────────────────────────────────────
app.whenReady().then(async () => {
    const phpBin = findPhp();
    if (!phpBin) {
        dialog.showErrorBox(
            'PHP Tidak Ditemukan',
            'PHP tidak ditemukan di sistem ini.\n\nInstal PHP via Homebrew:\n  brew install php\n\nAtau instal XAMPP dari apachefriends.org'
        );
        app.quit();
        return;
    }

    let port;
    try {
        port = await findFreePort(8765);
    } catch {
        port = 8765;
    }
    serverPort = port;

    // Jalankan PHP built-in server
    phpProcess = spawn(phpBin, ['-S', `127.0.0.1:${port}`], {
        cwd: appDir,
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    phpProcess.stderr.on('data', () => {}); // supres log PHP

    phpProcess.on('error', (err) => {
        dialog.showErrorBox('Error', `Gagal menjalankan PHP: ${err.message}`);
        app.quit();
    });

    // Tunggu server siap lalu buka window
    try {
        await waitForServer(port);
        createWindow(port);
    } catch (err) {
        dialog.showErrorBox('Error', err.message);
        app.quit();
    }
});

// ── Bersihkan saat keluar ─────────────────────────────────────
app.on('before-quit', () => {
    if (phpProcess) {
        phpProcess.kill();
        phpProcess = null;
    }
});

app.on('window-all-closed', () => {
    if (process.platform !== 'darwin') app.quit();
});

app.on('activate', () => {
    if (mainWindow === null && serverPort) {
        createWindow(serverPort);
    }
});

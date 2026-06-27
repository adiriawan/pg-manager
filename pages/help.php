<?php
$section = $_GET['section'] ?? 'mulai';
$sections = [
    'mulai'        => 'Memulai',
    'setup'        => 'Setup & Instalasi',
    'server'       => 'Menjalankan Server',
    'koneksi'      => 'Koneksi Database',
    'table-editor' => 'Table Editor',
    'filter'       => 'Filter Data',
    'sql-editor'   => 'SQL Editor',
    'erd'          => 'ERD Diagram',
    'backup'       => 'Backup & Restore',
    'database'     => 'Database Overview',
];
?>
<div class="help-layout">

    <!-- Sidebar navigasi dokumen -->
    <nav class="help-nav">
        <div class="help-nav-title">Panduan Penggunaan</div>
        <?php foreach ($sections as $key => $label): ?>
        <a href="app.php?page=help&schema=<?= urlencode($schema) ?>&section=<?= $key ?>"
           class="help-nav-item <?= $section === $key ? 'active' : '' ?>">
            <?= $label ?>
        </a>
        <?php endforeach; ?>
        <div class="help-nav-divider"></div>
        <div class="help-nav-meta">
            <div>PHP <?= PHP_VERSION ?></div>
            <div>PostgreSQL 16 (Homebrew)</div>
            <div>PG Manager v1.0</div>
        </div>
    </nav>

    <!-- Konten -->
    <article class="help-content">

<?php if ($section === 'mulai'): ?>
<div class="help-hero">
    <div class="help-hero-icon">
        <svg width="48" height="48" viewBox="0 0 32 32" fill="none">
            <circle cx="16" cy="16" r="16" fill="#3ECF8E"/>
            <path d="M9 22V10l7 4 7-4v12l-7-4-7 4z" fill="#fff"/>
        </svg>
    </div>
    <h1>Selamat datang di PG Manager</h1>
    <p>Aplikasi berbasis web untuk melihat dan mengelola database PostgreSQL Anda — dibangun dengan PHP native, tanpa framework.</p>
</div>

<div class="feature-grid">
    <div class="feature-card">
        <div class="feature-icon">📋</div>
        <div class="feature-title">Table Editor</div>
        <div class="feature-desc">Lihat, tambah, edit, dan hapus data tabel secara visual</div>
    </div>
    <div class="feature-card">
        <div class="feature-icon">🔍</div>
        <div class="feature-title">Filter Canggih</div>
        <div class="feature-desc">Filter data dengan banyak kondisi AND/OR pada semua kolom</div>
    </div>
    <div class="feature-card">
        <div class="feature-icon">💻</div>
        <div class="feature-title">SQL Editor</div>
        <div class="feature-desc">Jalankan query SQL bebas dengan hasil instan</div>
    </div>
    <div class="feature-card">
        <div class="feature-icon">🗺️</div>
        <div class="feature-title">ERD Diagram</div>
        <div class="feature-desc">Visualisasi relasi antar tabel secara interaktif</div>
    </div>
    <div class="feature-card">
        <div class="feature-icon">💾</div>
        <div class="feature-title">Backup & Restore</div>
        <div class="feature-desc">Backup ke SQL/Custom/Tar dan restore dari file</div>
    </div>
    <div class="feature-card">
        <div class="feature-icon">📊</div>
        <div class="feature-title">Database Overview</div>
        <div class="feature-desc">Statistik ukuran tabel, baris, dan koneksi aktif</div>
    </div>
</div>

<div class="help-callout callout-info">
    <strong>Langkah pertama:</strong> Baca <a href="app.php?page=help&section=setup">Setup & Instalasi</a> jika baru pertama kali, atau langsung ke <a href="app.php?page=help&section=server">Menjalankan Server</a> jika sudah pernah setup.
</div>

<?php elseif ($section === 'setup'): ?>
<h1>Setup &amp; Instalasi</h1>

<div class="help-callout callout-info">
    Aplikasi ini sudah siap pakai di komputer Anda. Tidak perlu instalasi tambahan selama PHP dan PostgreSQL sudah ada.
</div>

<h2>Prasyarat</h2>
<div class="req-grid">
    <div class="req-item">
        <div class="req-check">✅</div>
        <div>
            <div class="req-title">PHP 8.x</div>
            <div class="req-path"><code>/Applications/XAMPP/bin/php</code> (sudah terdeteksi)</div>
        </div>
    </div>
    <div class="req-item">
        <div class="req-check">✅</div>
        <div>
            <div class="req-title">Ekstensi PDO PostgreSQL</div>
            <div class="req-path"><code>pdo_pgsql</code> (sudah aktif)</div>
        </div>
    </div>
    <div class="req-item">
        <div class="req-check">✅</div>
        <div>
            <div class="req-title">PostgreSQL 16</div>
            <div class="req-path"><code>/opt/homebrew/bin/pg_dump</code> (sudah terdeteksi)</div>
        </div>
    </div>
    <div class="req-item">
        <div class="req-check">✅</div>
        <div>
            <div class="req-title">pg_dump &amp; psql</div>
            <div class="req-path">Diperlukan untuk fitur Backup &amp; Restore</div>
        </div>
    </div>
</div>

<h2>Lokasi File Aplikasi</h2>
<div class="code-block">
    <div class="code-header">Struktur folder</div>
<pre>/Users/adiriawan/lihat-database/
├── index.php          ← Halaman login/koneksi
├── app.php            ← Layout utama + routing
├── config.php         ← Konfigurasi koneksi PDO
├── pages/
│   ├── table_editor.php
│   ├── sql_editor.php
│   ├── erd.php
│   ├── database.php
│   ├── backup.php
│   └── help.php       ← Halaman ini
├── assets/
│   ├── style.css
│   └── app.js
├── api/
│   └── list_databases.php  ← API dropdown database
└── backups/           ← Folder penyimpanan file backup</pre>
</div>

<h2>Konfigurasi pg_dump (untuk Backup)</h2>
<p>Path tool PostgreSQL sudah dikonfigurasi di <code>pages/backup.php</code>:</p>
<div class="code-block">
    <div class="code-header">pages/backup.php</div>
<pre>define('PG_DUMP',    '/opt/homebrew/bin/pg_dump');
define('PG_RESTORE', '/opt/homebrew/bin/pg_restore');
define('PSQL',       '/opt/homebrew/bin/psql');</pre>
</div>
<p>Jika path berbeda, ubah tiga baris di atas sesuai hasil perintah:</p>
<div class="code-block">
    <div class="code-header">Terminal</div>
<pre>which pg_dump
which pg_restore
which psql</pre>
</div>

<?php elseif ($section === 'server'): ?>
<h1>Menjalankan Server</h1>

<h2>Cara 1 — PHP Built-in Server (Direkomendasikan)</h2>
<p>Cara paling mudah. Buka Terminal dan jalankan:</p>
<div class="code-block">
    <div class="code-header">Terminal</div>
<pre>/Applications/XAMPP/bin/php -S localhost:8765 -t /Users/adiriawan/lihat-database</pre>
</div>
<p>Lalu buka browser ke: <a href="http://localhost:8765" target="_blank" class="help-link">http://localhost:8765</a></p>

<div class="help-callout callout-tip">
    <strong>Tip:</strong> Jalankan di background agar Terminal tidak tertutup tidak masalah:
    <div class="code-block" style="margin-top:8px">
        <div class="code-header">Terminal</div>
<pre>/Applications/XAMPP/bin/php -S localhost:8765 -t /Users/adiriawan/lihat-database > /tmp/pgmanager.log 2>&1 &</pre>
    </div>
    Untuk melihat log: <code>tail -f /tmp/pgmanager.log</code><br>
    Untuk menghentikan: <code>kill $(lsof -ti:8765)</code>
</div>

<h2>Cara 2 — XAMPP</h2>
<p>Jika ingin pakai XAMPP yang sudah terinstal:</p>
<div class="steps">
    <div class="step">
        <div class="step-num">1</div>
        <div class="step-body">Buka <strong>XAMPP Control Panel</strong></div>
    </div>
    <div class="step">
        <div class="step-num">2</div>
        <div class="step-body">Klik <strong>Start</strong> pada Apache</div>
    </div>
    <div class="step">
        <div class="step-num">3</div>
        <div class="step-body">
            Salin atau buat symlink folder ke htdocs:<br>
            <code>ln -s /Users/adiriawan/lihat-database /Applications/XAMPP/htdocs/pgmanager</code>
        </div>
    </div>
    <div class="step">
        <div class="step-num">4</div>
        <div class="step-body">Buka <a href="http://localhost/pgmanager" target="_blank" class="help-link">http://localhost/pgmanager</a></div>
    </div>
</div>

<h2>Cara 3 — Alias Terminal (Shortcut Permanen)</h2>
<p>Tambahkan alias ke <code>~/.zshrc</code> agar bisa jalankan dengan satu kata:</p>
<div class="code-block">
    <div class="code-header">~/.zshrc</div>
<pre>alias pgmanager='/Applications/XAMPP/bin/php -S localhost:8765 -t /Users/adiriawan/lihat-database > /tmp/pgmanager.log 2>&1 &amp; && echo "PG Manager berjalan di http://localhost:8765"'</pre>
</div>
<div class="code-block">
    <div class="code-header">Terminal (aktifkan alias)</div>
<pre>source ~/.zshrc

# Setelah itu cukup ketik:
pgmanager</pre>
</div>

<h2>Menghentikan Server</h2>
<div class="code-block">
    <div class="code-header">Terminal</div>
<pre># Hentikan server di port 8765
kill $(lsof -ti:8765)

# Atau cari PID-nya dulu
lsof -i:8765
kill [PID]</pre>
</div>

<h2>Cek Status Server</h2>
<div class="code-block">
    <div class="code-header">Terminal</div>
<pre># Cek apakah server berjalan
curl -s -o /dev/null -w "%{http_code}" http://localhost:8765/
# → 200 = berjalan, 000 = tidak berjalan</pre>
</div>

<?php elseif ($section === 'koneksi'): ?>
<h1>Koneksi Database</h1>

<h2>Form Login</h2>
<p>Saat pertama membuka aplikasi, Anda akan melihat halaman koneksi:</p>
<div class="steps">
    <div class="step">
        <div class="step-num">1</div>
        <div class="step-body">
            Isi <strong>Host</strong> — biasanya <code>localhost</code> untuk database lokal
        </div>
    </div>
    <div class="step">
        <div class="step-num">2</div>
        <div class="step-body">
            Isi <strong>Port</strong> — default PostgreSQL adalah <code>5432</code>
        </div>
    </div>
    <div class="step">
        <div class="step-num">3</div>
        <div class="step-body">
            Isi <strong>Username</strong> — gunakan user yang menjalankan PostgreSQL. Di komputer ini: <code>adiriawan</code>
        </div>
    </div>
    <div class="step">
        <div class="step-num">4</div>
        <div class="step-body">
            Isi <strong>Password</strong> — kosongkan jika tidak pakai password (trust auth)
        </div>
    </div>
    <div class="step">
        <div class="step-num">5</div>
        <div class="step-body">
            Klik <strong>Muat</strong> untuk mengambil daftar database dari server
        </div>
    </div>
    <div class="step">
        <div class="step-num">6</div>
        <div class="step-body">
            Pilih <strong>Database</strong> dari dropdown, lalu klik <strong>Hubungkan</strong>
        </div>
    </div>
</div>

<div class="help-callout callout-tip">
    <strong>Shortcut:</strong> Setelah mengisi password, tekan <kbd>Enter</kbd> untuk langsung memuat daftar database.
</div>

<h2>Troubleshooting Koneksi</h2>
<div class="trouble-list">
    <div class="trouble-item">
        <div class="trouble-error"><code>role "postgres" does not exist</code></div>
        <div class="trouble-fix">Gunakan username sistem Anda, bukan "postgres". Cek dengan: <code>whoami</code></div>
    </div>
    <div class="trouble-item">
        <div class="trouble-error"><code>connection refused</code></div>
        <div class="trouble-fix">PostgreSQL belum berjalan. Jalankan: <code>brew services start postgresql@16</code></div>
    </div>
    <div class="trouble-item">
        <div class="trouble-error"><code>password authentication failed</code></div>
        <div class="trouble-fix">Password salah, atau coba kosongkan jika menggunakan metode auth <em>trust</em></div>
    </div>
    <div class="trouble-item">
        <div class="trouble-error"><code>database does not exist</code></div>
        <div class="trouble-fix">Database belum dibuat. Buat dulu: <code>createdb nama_database</code></div>
    </div>
</div>

<h2>Mengganti Database / Putus Koneksi</h2>
<p>Klik tombol <strong>Putus Koneksi</strong> di bagian bawah sidebar untuk kembali ke halaman login dan ganti database.</p>

<?php elseif ($section === 'table-editor'): ?>
<h1>Table Editor</h1>
<p>Halaman utama untuk melihat dan mengelola isi tabel secara visual.</p>

<h2>Navigasi Tabel</h2>
<div class="steps">
    <div class="step">
        <div class="step-num">1</div>
        <div class="step-body">Pilih <strong>schema</strong> dari sidebar (contoh: <code>public</code>)</div>
    </div>
    <div class="step">
        <div class="step-num">2</div>
        <div class="step-body">Klik nama <strong>tabel</strong> di bawah daftar Tables pada sidebar</div>
    </div>
    <div class="step">
        <div class="step-num">3</div>
        <div class="step-body">Data tabel akan tampil di area utama</div>
    </div>
</div>

<h2>Fitur Tabel</h2>
<div class="feature-table">
    <div class="ft-row">
        <div class="ft-label">Sort kolom</div>
        <div class="ft-desc">Klik nama kolom di header untuk mengurutkan naik/turun (↑↓)</div>
    </div>
    <div class="ft-row">
        <div class="ft-label">Cari data</div>
        <div class="ft-desc">Ketik di kolom pencarian → klik <strong>Terapkan</strong>. Mencari di semua kolom sekaligus</div>
    </div>
    <div class="ft-row">
        <div class="ft-label">Jumlah baris</div>
        <div class="ft-desc">Pilih 25 / 50 / 100 / 500 baris per halaman dari dropdown</div>
    </div>
    <div class="ft-row">
        <div class="ft-label">Edit baris</div>
        <div class="ft-desc">Klik ✏️ di kolom aksi → popup terbuka → klik kolom yang ingin diubah → isi nilai baru → <strong>Simpan</strong></div>
    </div>
    <div class="ft-row">
        <div class="ft-label">Hapus baris</div>
        <div class="ft-desc">Klik 🗑️ di kolom aksi → konfirmasi hapus</div>
    </div>
    <div class="ft-row">
        <div class="ft-label">Insert baris</div>
        <div class="ft-desc">Klik <strong>+ Insert Row</strong> di kanan atas → isi nilai → klik <strong>Insert</strong></div>
    </div>
    <div class="ft-row">
        <div class="ft-label">Struktur</div>
        <div class="ft-desc">Klik <strong>Struktur</strong> untuk melihat kolom, tipe data, nullable, dan Primary Key</div>
    </div>
    <div class="ft-row">
        <div class="ft-label">Drop tabel</div>
        <div class="ft-desc">Klik <strong>Drop</strong> → ketik nama tabel untuk konfirmasi → hapus permanen</div>
    </div>
</div>

<div class="help-callout callout-warning">
    <strong>Perhatian:</strong> Operasi Edit dan Delete langsung dijalankan ke database. Tidak ada undo — pastikan sudah backup sebelum mengubah data penting.
</div>

<?php elseif ($section === 'filter'): ?>
<h1>Filter Data</h1>
<p>Filter canggih berbasis kondisi — mirip seperti klausa WHERE di SQL.</p>

<h2>Membuka Panel Filter</h2>
<p>Klik tombol <strong>Filter</strong> di toolbar. Panel akan muncul di bawah toolbar. Tombol berubah warna hijau dan menampilkan badge jumlah kondisi aktif.</p>

<h2>Menambah Kondisi</h2>
<div class="steps">
    <div class="step">
        <div class="step-num">1</div>
        <div class="step-body">Pilih <strong>Kolom</strong> yang ingin difilter</div>
    </div>
    <div class="step">
        <div class="step-num">2</div>
        <div class="step-body">Pilih <strong>Operator</strong> dari dropdown</div>
    </div>
    <div class="step">
        <div class="step-num">3</div>
        <div class="step-body">Isi <strong>Nilai</strong> (tidak perlu untuk IS NULL / IS NOT NULL)</div>
    </div>
    <div class="step">
        <div class="step-num">4</div>
        <div class="step-body">Klik <strong>+ Tambah Kondisi</strong> untuk menambah baris filter baru</div>
    </div>
    <div class="step">
        <div class="step-num">5</div>
        <div class="step-body">Klik <strong>Terapkan Filter</strong></div>
    </div>
</div>

<h2>Daftar Operator</h2>
<table class="help-table">
    <thead><tr><th>Operator</th><th>SQL Setara</th><th>Contoh</th></tr></thead>
    <tbody>
        <tr><td>mengandung</td><td><code>ILIKE '%nilai%'</code></td><td>Cari "john" → menemukan "Johnson", "john doe"</td></tr>
        <tr><td>tidak mengandung</td><td><code>NOT ILIKE '%nilai%'</code></td><td>Semua yang tidak mengandung kata tertentu</td></tr>
        <tr><td>= sama dengan</td><td><code>= 'nilai'</code></td><td>Status = 'aktif'</td></tr>
        <tr><td>≠ tidak sama</td><td><code>!= 'nilai'</code></td><td>Status ≠ 'nonaktif'</td></tr>
        <tr><td>&gt; lebih besar</td><td><code>&gt; nilai</code></td><td>Harga &gt; 100000</td></tr>
        <tr><td>≥ lebih besar/sama</td><td><code>&gt;= nilai</code></td><td>Stok &gt;= 10</td></tr>
        <tr><td>&lt; lebih kecil</td><td><code>&lt; nilai</code></td><td>Umur &lt; 18</td></tr>
        <tr><td>≤ lebih kecil/sama</td><td><code>&lt;= nilai</code></td><td>Diskon &lt;= 50</td></tr>
        <tr><td>diawali dengan</td><td><code>ILIKE 'nilai%'</code></td><td>Email diawali "admin"</td></tr>
        <tr><td>diakhiri dengan</td><td><code>ILIKE '%nilai'</code></td><td>Email diakhiri "@gmail.com"</td></tr>
        <tr><td>termasuk dalam</td><td><code>IN (a, b, c)</code></td><td>ID dalam: <code>1,5,9,23</code></td></tr>
        <tr><td>adalah NULL</td><td><code>IS NULL</code></td><td>Kolom belum diisi</td></tr>
        <tr><td>bukan NULL</td><td><code>IS NOT NULL</code></td><td>Kolom sudah diisi</td></tr>
    </tbody>
</table>

<h2>AND vs OR</h2>
<div class="help-callout callout-info">
    <strong>AND</strong> — semua kondisi harus terpenuhi (lebih ketat)<br>
    <strong>OR</strong> — salah satu kondisi cukup (lebih luas)<br><br>
    Contoh: <code>kota = "Jakarta"</code> AND <code>umur &gt; 25</code> → hanya yang di Jakarta DAN berumur di atas 25
</div>

<h2>Reset Filter</h2>
<p>Klik tombol <strong>✕ Reset</strong> di toolbar, atau klik <strong>Reset Semua</strong> di dalam panel filter.</p>

<?php elseif ($section === 'sql-editor'): ?>
<h1>SQL Editor</h1>
<p>Jalankan query SQL apapun langsung ke database — SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, dll.</p>

<h2>Cara Menggunakan</h2>
<div class="steps">
    <div class="step">
        <div class="step-num">1</div>
        <div class="step-body">Klik menu <strong>SQL Editor</strong> di sidebar</div>
    </div>
    <div class="step">
        <div class="step-num">2</div>
        <div class="step-body">Ketik atau tempel query SQL di area editor</div>
    </div>
    <div class="step">
        <div class="step-num">3</div>
        <div class="step-body">Tekan <kbd>Ctrl</kbd>+<kbd>Enter</kbd> atau klik tombol <strong>▶ Jalankan</strong></div>
    </div>
    <div class="step">
        <div class="step-num">4</div>
        <div class="step-body">Hasil muncul di bawah editor — termasuk jumlah baris dan waktu eksekusi</div>
    </div>
</div>

<h2>Keyboard Shortcut</h2>
<table class="help-table">
    <thead><tr><th>Shortcut</th><th>Fungsi</th></tr></thead>
    <tbody>
        <tr><td><kbd>Ctrl</kbd>+<kbd>Enter</kbd></td><td>Jalankan query</td></tr>
        <tr><td><kbd>Tab</kbd></td><td>Indent 2 spasi</td></tr>
    </tbody>
</table>

<h2>Export Hasil</h2>
<p>Jika hasil query menghasilkan baris data, tombol <strong>Export CSV</strong> akan muncul di header hasil. Klik untuk mengunduh sebagai file <code>.csv</code>.</p>

<h2>Contoh Query</h2>
<div class="code-block">
    <div class="code-header">Melihat semua tabel di schema public</div>
<pre>SELECT table_name, table_type
FROM information_schema.tables
WHERE table_schema = 'public'
ORDER BY table_name;</pre>
</div>
<div class="code-block">
    <div class="code-header">Ukuran semua tabel</div>
<pre>SELECT
    relname AS tabel,
    pg_size_pretty(pg_total_relation_size(relid)) AS ukuran
FROM pg_catalog.pg_statio_user_tables
ORDER BY pg_total_relation_size(relid) DESC;</pre>
</div>
<div class="code-block">
    <div class="code-header">Lihat koneksi aktif</div>
<pre>SELECT pid, usename, application_name, state, query
FROM pg_stat_activity
WHERE state = 'active';</pre>
</div>
<div class="code-block">
    <div class="code-header">Buat tabel baru</div>
<pre>CREATE TABLE public.produk (
    id        bigserial PRIMARY KEY,
    nama      text NOT NULL,
    harga     numeric(12,2) DEFAULT 0,
    stok      integer DEFAULT 0,
    dibuat_at timestamp DEFAULT now()
);</pre>
</div>

<div class="help-callout callout-warning">
    <strong>Hati-hati:</strong> Query dijalankan langsung tanpa konfirmasi. Perintah <code>DROP</code>, <code>TRUNCATE</code>, dan <code>DELETE</code> tanpa WHERE akan menghapus data secara permanen.
</div>

<?php elseif ($section === 'erd'): ?>
<h1>ERD Diagram</h1>
<p>Visualisasi relasi antar tabel berdasarkan Foreign Key yang ada di database.</p>

<h2>Cara Membuka</h2>
<p>Klik menu <strong>ERD</strong> di sidebar. Diagram otomatis muncul berdasarkan schema yang aktif.</p>

<h2>Membaca Diagram</h2>
<div class="feature-table">
    <div class="ft-row">
        <div class="ft-label">Kotak tabel</div>
        <div class="ft-desc">Header hijau = nama tabel. Isi = daftar kolom beserta tipe data</div>
    </div>
    <div class="ft-row">
        <div class="ft-label">🔑 Ikon kunci</div>
        <div class="ft-desc">Primary Key — kolom warna kuning</div>
    </div>
    <div class="ft-row">
        <div class="ft-label">🔗 Ikon rantai</div>
        <div class="ft-desc">Foreign Key — kolom warna biru</div>
    </div>
    <div class="ft-row">
        <div class="ft-label">Garis kurva</div>
        <div class="ft-desc">Relasi FK — dari kolom FK ke kolom PK tujuan. Hover untuk lihat detail</div>
    </div>
    <div class="ft-row">
        <div class="ft-label">● titik awal</div>
        <div class="ft-desc">Sisi "banyak" (tabel yang punya FK)</div>
    </div>
    <div class="ft-row">
        <div class="ft-label">▶ panah akhir</div>
        <div class="ft-desc">Sisi "satu" (tabel yang direferensikan)</div>
    </div>
</div>

<h2>Kontrol Interaktif</h2>
<table class="help-table">
    <thead><tr><th>Aksi</th><th>Cara</th></tr></thead>
    <tbody>
        <tr><td>Pindah tabel</td><td>Klik &amp; drag header tabel (area hijau)</td></tr>
        <tr><td>Geser canvas</td><td>Klik &amp; drag area kosong</td></tr>
        <tr><td>Zoom in/out</td><td>Scroll mouse, atau tombol <strong>＋/－ Zoom</strong></td></tr>
        <tr><td>Fit semua tabel</td><td>Klik tombol <strong>Fit</strong></td></tr>
        <tr><td>Reset posisi</td><td>Klik tombol <strong>Reset Layout</strong></td></tr>
        <tr><td>Detail relasi</td><td>Hover di atas garis FK</td></tr>
    </tbody>
</table>

<h2>Ganti Schema</h2>
<p>ERD mengikuti schema yang dipilih di sidebar. Klik schema lain untuk melihat ERD schema tersebut.</p>

<div class="help-callout callout-info">
    <strong>Catatan:</strong> ERD hanya menampilkan relasi yang terdefinisi sebagai FOREIGN KEY di database. Relasi "logis" yang tidak terdefinisi di schema tidak akan muncul.
</div>

<?php elseif ($section === 'backup'): ?>
<h1>Backup &amp; Restore</h1>
<p>Buat salinan database dan pulihkan dari file backup menggunakan <code>pg_dump</code> dan <code>psql</code>.</p>

<h2>Membuat Backup</h2>
<div class="steps">
    <div class="step">
        <div class="step-num">1</div>
        <div class="step-body">Klik menu <strong>Backup &amp; Restore</strong> di sidebar</div>
    </div>
    <div class="step">
        <div class="step-num">2</div>
        <div class="step-body">Klik tombol <strong>+ Buat Backup</strong></div>
    </div>
    <div class="step">
        <div class="step-num">3</div>
        <div class="step-body">Pilih <strong>Format</strong>: SQL, Custom, atau Tar</div>
    </div>
    <div class="step">
        <div class="step-num">4</div>
        <div class="step-body">Pilih <strong>Konten</strong>: Schema+Data, Schema saja, atau Data saja</div>
    </div>
    <div class="step">
        <div class="step-num">5</div>
        <div class="step-body">Opsional: centang tabel tertentu (kosong = semua tabel)</div>
    </div>
    <div class="step">
        <div class="step-num">6</div>
        <div class="step-body">Klik <strong>Mulai Backup</strong> — file akan tersimpan di folder <code>backups/</code></div>
    </div>
</div>

<h2>Perbandingan Format</h2>
<table class="help-table">
    <thead><tr><th>Format</th><th>Ekstensi</th><th>Keunggulan</th><th>Kelemahan</th></tr></thead>
    <tbody>
        <tr>
            <td><strong>SQL</strong></td><td><code>.sql</code></td>
            <td>Bisa dibaca &amp; diedit manual, kompatibel luas</td>
            <td>Ukuran lebih besar</td>
        </tr>
        <tr>
            <td><strong>Custom</strong></td><td><code>.dump</code></td>
            <td>Ukuran lebih kecil, bisa restore tabel tertentu saja</td>
            <td>Tidak bisa dibaca langsung</td>
        </tr>
        <tr>
            <td><strong>Tar</strong></td><td><code>.tar</code></td>
            <td>Mendukung restore paralel (lebih cepat untuk DB besar)</td>
            <td>Perlu pg_restore untuk buka</td>
        </tr>
    </tbody>
</table>

<h2>Restore Database</h2>
<h3>Dari file backup tersimpan:</h3>
<div class="steps">
    <div class="step">
        <div class="step-num">1</div>
        <div class="step-body">Temukan file di tabel daftar backup</div>
    </div>
    <div class="step">
        <div class="step-num">2</div>
        <div class="step-body">Klik tombol <strong>↩ Restore</strong></div>
    </div>
    <div class="step">
        <div class="step-num">3</div>
        <div class="step-body">Pilih <strong>database tujuan</strong> dari dropdown</div>
    </div>
    <div class="step">
        <div class="step-num">4</div>
        <div class="step-body">Opsional: centang <em>"Drop schema sebelum restore"</em> untuk restore bersih</div>
    </div>
    <div class="step">
        <div class="step-num">5</div>
        <div class="step-body">Klik <strong>Mulai Restore</strong></div>
    </div>
</div>

<h3>Dari file upload:</h3>
<p>Klik <strong>↑ Restore dari File</strong> → drag &amp; drop atau pilih file dari komputer → pilih database tujuan → <strong>Mulai Restore</strong>.</p>

<div class="help-callout callout-warning">
    <strong>Peringatan:</strong> Jika opsi "Drop schema" diaktifkan, semua data di database tujuan akan dihapus sebelum restore. Pastikan database tujuan sudah benar.
</div>

<h2>Download &amp; Hapus Backup</h2>
<p>Di tabel daftar file backup, setiap file memiliki tombol:
<ul class="help-ul">
    <li><strong>⬇ Download</strong> — unduh file ke komputer lokal</li>
    <li><strong>↩ Restore</strong> — restore langsung ke database</li>
    <li><strong>🗑 Hapus</strong> — hapus file dari server (tidak bisa dibatalkan)</li>
</ul>
</p>

<?php elseif ($section === 'database'): ?>
<h1>Database Overview</h1>
<p>Halaman ringkasan statistik database yang sedang aktif.</p>

<h2>Informasi yang Ditampilkan</h2>
<div class="feature-table">
    <div class="ft-row">
        <div class="ft-label">Ukuran Database</div>
        <div class="ft-desc">Total ukuran database termasuk semua tabel dan index</div>
    </div>
    <div class="ft-row">
        <div class="ft-label">Jumlah Tabel</div>
        <div class="ft-desc">Banyaknya tabel di semua schema (tidak termasuk system schema)</div>
    </div>
    <div class="ft-row">
        <div class="ft-label">Jumlah View</div>
        <div class="ft-desc">Banyaknya view yang terdefinisi</div>
    </div>
    <div class="ft-row">
        <div class="ft-label">Koneksi Aktif</div>
        <div class="ft-desc">Jumlah koneksi yang sedang dalam status <code>active</code></div>
    </div>
    <div class="ft-row">
        <div class="ft-label">Versi PostgreSQL</div>
        <div class="ft-desc">Versi lengkap PostgreSQL yang berjalan</div>
    </div>
    <div class="ft-row">
        <div class="ft-label">Tabel &amp; Ukuran</div>
        <div class="ft-desc">Ukuran per tabel, estimasi jumlah baris, dan waktu autovacuum terakhir</div>
    </div>
</div>

<h2>Membuat Tabel Baru</h2>
<div class="steps">
    <div class="step">
        <div class="step-num">1</div>
        <div class="step-body">Klik <strong>+ Buat Tabel</strong> di pojok kanan atas</div>
    </div>
    <div class="step">
        <div class="step-num">2</div>
        <div class="step-body">Isi nama tabel dan pilih schema tujuan</div>
    </div>
    <div class="step">
        <div class="step-num">3</div>
        <div class="step-body">Tambahkan kolom: nama, tipe data, Primary Key, Nullable</div>
    </div>
    <div class="step">
        <div class="step-num">4</div>
        <div class="step-body">Klik <strong>+ Tambah Kolom</strong> untuk kolom tambahan</div>
    </div>
    <div class="step">
        <div class="step-num">5</div>
        <div class="step-body">Klik <strong>Buat Tabel</strong> — langsung diarahkan ke Table Editor tabel baru</div>
    </div>
</div>

<?php endif; ?>

    </article>
</div>

<style>
/* Help layout */
.help-layout { display: flex; gap: 24px; align-items: flex-start; }
.help-nav {
    width: 200px; flex-shrink: 0;
    position: sticky; top: 0;
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 12px 0; font-size: 13px;
}
.help-nav-title {
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.08em; color: var(--text-muted);
    padding: 0 14px 8px;
}
.help-nav-item {
    display: block; padding: 6px 14px;
    color: var(--text-secondary); transition: all 0.1s;
    border-left: 2px solid transparent;
}
.help-nav-item:hover { color: var(--text-primary); background: var(--bg-hover); }
.help-nav-item.active { color: var(--accent); border-left-color: var(--accent); background: var(--accent-dim); }
.help-nav-divider { border-top: 1px solid var(--border); margin: 10px 0; }
.help-nav-meta { padding: 0 14px; font-size: 11px; color: var(--text-muted); line-height: 1.8; }

.help-content { flex: 1; max-width: 780px; }
.help-content h1 { font-size: 22px; font-weight: 700; margin-bottom: 8px; }
.help-content h2 { font-size: 15px; font-weight: 600; margin: 28px 0 10px; color: var(--text-primary); }
.help-content h3 { font-size: 13px; font-weight: 600; margin: 18px 0 8px; color: var(--text-secondary); }
.help-content p  { color: var(--text-secondary); line-height: 1.7; margin-bottom: 12px; }
.help-content ul { padding-left: 20px; }
.help-ul li { color: var(--text-secondary); margin-bottom: 4px; line-height: 1.7; }

/* Hero */
.help-hero { text-align: center; padding: 32px 0 24px; }
.help-hero-icon { margin-bottom: 16px; }
.help-hero h1 { font-size: 26px; margin-bottom: 8px; }
.help-hero p  { color: var(--text-secondary); font-size: 14px; }

/* Feature grid */
.feature-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 12px; margin: 20px 0; }
.feature-card {
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 16px;
    transition: border-color 0.15s;
}
.feature-card:hover { border-color: var(--accent); }
.feature-icon { font-size: 22px; margin-bottom: 8px; }
.feature-title { font-weight: 600; margin-bottom: 4px; }
.feature-desc { font-size: 12px; color: var(--text-muted); }

/* Callouts */
.help-callout {
    border-radius: var(--radius); padding: 12px 14px;
    margin: 16px 0; font-size: 13px; line-height: 1.6;
}
.callout-info    { background: var(--accent-dim); border-left: 3px solid var(--accent); color: var(--text-secondary); }
.callout-tip     { background: #1a2a3a; border-left: 3px solid #63b3ed; color: var(--text-secondary); }
.callout-warning { background: #2d2000; border-left: 3px solid var(--warning); color: var(--text-secondary); }
.callout-info strong, .callout-tip strong, .callout-warning strong { color: var(--text-primary); }
.help-callout a { color: var(--accent); }

/* Req grid */
.req-grid { display: grid; gap: 10px; margin: 12px 0; }
.req-item { display: flex; gap: 12px; align-items: flex-start; padding: 10px 14px; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); }
.req-check { font-size: 16px; flex-shrink: 0; margin-top: 1px; }
.req-title { font-weight: 600; font-size: 13px; }
.req-path  { font-size: 12px; color: var(--text-muted); margin-top: 2px; }

/* Steps */
.steps { display: flex; flex-direction: column; gap: 8px; margin: 12px 0; }
.step { display: flex; gap: 12px; align-items: flex-start; }
.step-num {
    width: 24px; height: 24px; flex-shrink: 0;
    background: var(--accent); color: #000;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 700;
}
.step-body { padding-top: 3px; color: var(--text-secondary); font-size: 13px; line-height: 1.6; }
.step-body strong { color: var(--text-primary); }
.step-body code { font-family: var(--mono); font-size: 12px; background: var(--bg-input); padding: 1px 5px; border-radius: 3px; }

/* Code block */
.code-block {
    background: var(--bg-sidebar); border: 1px solid var(--border);
    border-radius: var(--radius); overflow: hidden; margin: 12px 0;
}
.code-header {
    padding: 6px 12px; font-size: 11px; color: var(--text-muted);
    border-bottom: 1px solid var(--border); background: var(--bg-card);
    font-weight: 500;
}
.code-block pre {
    margin: 0; padding: 12px 14px;
    font-family: var(--mono); font-size: 12px;
    color: var(--text-primary); overflow-x: auto; line-height: 1.6;
}

/* Help table */
.help-table {
    width: 100%; border-collapse: collapse;
    border: 1px solid var(--border); border-radius: var(--radius);
    overflow: hidden; margin: 12px 0; font-size: 13px;
}
.help-table th {
    background: var(--bg-sidebar); padding: 8px 12px;
    text-align: left; color: var(--text-secondary);
    font-weight: 600; border-bottom: 1px solid var(--border);
}
.help-table td {
    padding: 7px 12px; border-bottom: 1px solid var(--border);
    color: var(--text-secondary); vertical-align: top;
}
.help-table tr:last-child td { border-bottom: none; }
.help-table tbody tr:hover { background: var(--bg-hover); }
.help-table kbd {
    background: var(--bg-input); border: 1px solid var(--border);
    border-radius: 3px; padding: 1px 5px; font-size: 11px;
    font-family: var(--font); color: var(--text-primary);
}

/* Feature table */
.feature-table { margin: 12px 0; border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
.ft-row { display: flex; gap: 0; border-bottom: 1px solid var(--border); }
.ft-row:last-child { border-bottom: none; }
.ft-row:hover { background: var(--bg-hover); }
.ft-label {
    flex: 0 0 160px; padding: 10px 14px;
    font-weight: 600; font-size: 12px; color: var(--text-primary);
    background: var(--bg-sidebar); border-right: 1px solid var(--border);
}
.ft-desc { flex: 1; padding: 10px 14px; font-size: 13px; color: var(--text-secondary); line-height: 1.6; }
.ft-desc strong { color: var(--text-primary); }

/* Trouble list */
.trouble-list { display: flex; flex-direction: column; gap: 10px; margin: 12px 0; }
.trouble-item {
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 12px 14px;
}
.trouble-error { font-family: var(--mono); font-size: 12px; color: #fc8181; margin-bottom: 6px; }
.trouble-fix { font-size: 13px; color: var(--text-secondary); }
.trouble-fix code { font-family: var(--mono); font-size: 11px; background: var(--bg-input); padding: 1px 5px; border-radius: 3px; }

kbd {
    background: var(--bg-input); border: 1px solid var(--border-light);
    border-radius: 3px; padding: 1px 6px; font-size: 12px;
    font-family: var(--font); color: var(--text-primary);
}
.help-link { color: var(--accent); text-decoration: underline; }
</style>

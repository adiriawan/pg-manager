#!/bin/bash
# ══════════════════════════════════════════════════════════════
#  PG Manager — Installer Script
#  Jalankan: bash install.sh
# ══════════════════════════════════════════════════════════════

set -e

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PORT=8765

echo ""
echo -e "${GREEN}╔══════════════════════════════════════╗${NC}"
echo -e "${GREEN}║        PG Manager Installer          ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════╝${NC}"
echo ""

# ── Deteksi OS ────────────────────────────────────────────────
OS="unknown"
if [[ "$OSTYPE" == "darwin"* ]]; then
    OS="macos"
elif [[ "$OSTYPE" == "linux-gnu"* ]]; then
    OS="linux"
fi
echo -e "${BLUE}► OS Terdeteksi:${NC} $OS"

# ══════════════════════════════════════════════════════════════
#  1. Cek / Install Homebrew (macOS)
# ══════════════════════════════════════════════════════════════
if [[ "$OS" == "macos" ]]; then
    if ! command -v brew &>/dev/null; then
        echo -e "\n${YELLOW}► Homebrew tidak ditemukan. Menginstall...${NC}"
        /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
    else
        echo -e "${GREEN}✓ Homebrew sudah ada${NC}"
    fi
fi

# ══════════════════════════════════════════════════════════════
#  2. Cek / Install PHP
# ══════════════════════════════════════════════════════════════
echo ""
echo -e "${BLUE}► Mengecek PHP...${NC}"

PHP_BIN=""
for candidate in php php8 php8.2 php8.1 /opt/homebrew/bin/php /usr/local/bin/php /Applications/XAMPP/bin/php; do
    if command -v "$candidate" &>/dev/null 2>&1; then
        ver=$("$candidate" -r 'echo PHP_MAJOR_VERSION;' 2>/dev/null)
        if [[ "$ver" -ge 8 ]]; then
            PHP_BIN="$candidate"
            break
        fi
    fi
done

if [[ -z "$PHP_BIN" ]]; then
    echo -e "${YELLOW}  PHP 8.x tidak ditemukan. Menginstall...${NC}"
    if [[ "$OS" == "macos" ]]; then
        brew install php
        PHP_BIN="$(brew --prefix)/bin/php"
    elif [[ "$OS" == "linux" ]]; then
        sudo apt-get update -qq
        sudo apt-get install -y php php-pgsql php-cli
        PHP_BIN="php"
    fi
fi

PHP_VER=$("$PHP_BIN" -r 'echo phpversion();')
echo -e "${GREEN}✓ PHP $PHP_VER${NC} → $PHP_BIN"

# ── Cek ekstensi pdo_pgsql ────────────────────────────────────
if ! "$PHP_BIN" -m 2>/dev/null | grep -q pdo_pgsql; then
    echo -e "${YELLOW}  Ekstensi pdo_pgsql tidak aktif.${NC}"
    if [[ "$OS" == "macos" ]]; then
        echo -e "  Coba: brew install php && brew link --force php"
        echo -e "  Atau aktifkan di php.ini: extension=pdo_pgsql"
    elif [[ "$OS" == "linux" ]]; then
        sudo apt-get install -y php-pgsql
    fi
    echo -e "${RED}✗ Silakan aktifkan pdo_pgsql lalu jalankan ulang installer.${NC}"
    exit 1
fi
echo -e "${GREEN}✓ Ekstensi pdo_pgsql aktif${NC}"

# ══════════════════════════════════════════════════════════════
#  3. Cek / Install PostgreSQL
# ══════════════════════════════════════════════════════════════
echo ""
echo -e "${BLUE}► Mengecek PostgreSQL...${NC}"

PG_DUMP=""
PSQL=""
for candidate in pg_dump /opt/homebrew/bin/pg_dump /usr/local/bin/pg_dump /usr/bin/pg_dump; do
    if command -v "$candidate" &>/dev/null 2>&1; then
        PG_DUMP="$candidate"
        break
    fi
done

if [[ -z "$PG_DUMP" ]]; then
    echo -e "${YELLOW}  pg_dump tidak ditemukan. Menginstall PostgreSQL...${NC}"
    if [[ "$OS" == "macos" ]]; then
        brew install postgresql@16
        brew link --force postgresql@16
        PG_DUMP="$(brew --prefix)/bin/pg_dump"
    elif [[ "$OS" == "linux" ]]; then
        sudo apt-get install -y postgresql postgresql-client
        PG_DUMP="/usr/bin/pg_dump"
    fi
fi

for candidate in psql /opt/homebrew/bin/psql /usr/local/bin/psql /usr/bin/psql; do
    if command -v "$candidate" &>/dev/null 2>&1; then
        PSQL="$candidate"
        break
    fi
done

PG_VER=$("$PG_DUMP" --version 2>/dev/null | awk '{print $3}')
echo -e "${GREEN}✓ pg_dump $PG_VER${NC} → $PG_DUMP"
echo -e "${GREEN}✓ psql${NC} → $PSQL"

# ══════════════════════════════════════════════════════════════
#  4. Update path pg_dump di backup.php
# ══════════════════════════════════════════════════════════════
BACKUP_FILE="$SCRIPT_DIR/pages/backup.php"
if [[ -f "$BACKUP_FILE" ]]; then
    PG_RESTORE="${PG_DUMP/pg_dump/pg_restore}"
    sed -i.bak \
        -e "s|define('PG_DUMP',.*|define('PG_DUMP',    '$PG_DUMP');|" \
        -e "s|define('PG_RESTORE',.*|define('PG_RESTORE', '$PG_RESTORE');|" \
        -e "s|define('PSQL',.*|define('PSQL',       '$PSQL');|" \
        "$BACKUP_FILE"
    rm -f "${BACKUP_FILE}.bak"
    echo -e "${GREEN}✓ Path pg_dump diperbarui di backup.php${NC}"
fi

# ══════════════════════════════════════════════════════════════
#  5. Buat folder backups jika belum ada
# ══════════════════════════════════════════════════════════════
mkdir -p "$SCRIPT_DIR/backups"
echo -e "${GREEN}✓ Folder backups siap${NC}"

# ══════════════════════════════════════════════════════════════
#  6. Cek port, matikan proses lama jika ada
# ══════════════════════════════════════════════════════════════
echo ""
echo -e "${BLUE}► Mengecek port $PORT...${NC}"
OLD_PID=$(lsof -ti:$PORT 2>/dev/null || true)
if [[ -n "$OLD_PID" ]]; then
    echo -e "${YELLOW}  Port $PORT dipakai PID $OLD_PID, menghentikan...${NC}"
    kill "$OLD_PID" 2>/dev/null || true
    sleep 1
fi

# ══════════════════════════════════════════════════════════════
#  7. Jalankan server
# ══════════════════════════════════════════════════════════════
LOG_FILE="/tmp/pgmanager.log"
echo -e "${BLUE}► Menjalankan server PHP di port $PORT...${NC}"
"$PHP_BIN" -S "localhost:$PORT" -t "$SCRIPT_DIR" > "$LOG_FILE" 2>&1 &
SERVER_PID=$!
sleep 1

if kill -0 "$SERVER_PID" 2>/dev/null; then
    echo -e "${GREEN}✓ Server berjalan (PID: $SERVER_PID)${NC}"
else
    echo -e "${RED}✗ Server gagal dijalankan. Cek log: $LOG_FILE${NC}"
    cat "$LOG_FILE"
    exit 1
fi

# ══════════════════════════════════════════════════════════════
#  8. Simpan info ke file .pgmanager
# ══════════════════════════════════════════════════════════════
cat > "$SCRIPT_DIR/.pgmanager" << EOF
PHP_BIN=$PHP_BIN
PG_DUMP=$PG_DUMP
PSQL=$PSQL
PORT=$PORT
SERVER_PID=$SERVER_PID
INSTALL_DATE=$(date '+%Y-%m-%d %H:%M:%S')
EOF

# ══════════════════════════════════════════════════════════════
#  9. Buka browser (opsional)
# ══════════════════════════════════════════════════════════════
URL="http://localhost:$PORT"
echo ""
echo -e "${GREEN}╔══════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║  ✓ PG Manager siap digunakan!               ║${NC}"
echo -e "${GREEN}║                                              ║${NC}"
echo -e "${GREEN}║  Buka browser ke: ${BLUE}http://localhost:$PORT${GREEN}     ║${NC}"
echo -e "${GREEN}║                                              ║${NC}"
echo -e "${GREEN}║  Untuk menghentikan server:                  ║${NC}"
echo -e "${GREEN}║  ${NC}bash stop.sh                               ${GREEN}║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════╝${NC}"
echo ""

# Buka browser otomatis
if [[ "$OS" == "macos" ]]; then
    open "$URL" 2>/dev/null || true
elif command -v xdg-open &>/dev/null; then
    xdg-open "$URL" 2>/dev/null || true
fi

#!/bin/bash
# ══════════════════════════════════════════════════════════════
#  PG Manager — Stop Script
#  Jalankan: bash stop.sh
# ══════════════════════════════════════════════════════════════

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INFO_FILE="$SCRIPT_DIR/.pgmanager"
PORT=8765

echo ""
echo -e "${GREEN}╔══════════════════════════════════════╗${NC}"
echo -e "${GREEN}║        PG Manager — Stop             ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════╝${NC}"
echo ""

STOPPED=0

# ── Coba baca PID dari file .pgmanager ────────────────────────
if [[ -f "$INFO_FILE" ]]; then
    SERVER_PID=$(grep '^SERVER_PID=' "$INFO_FILE" | cut -d= -f2)
    if [[ -n "$SERVER_PID" ]] && kill -0 "$SERVER_PID" 2>/dev/null; then
        kill "$SERVER_PID"
        echo -e "${GREEN}✓ Server PID $SERVER_PID dihentikan.${NC}"
        STOPPED=1
    fi
fi

# ── Fallback: cari proses di port 8765 ────────────────────────
OLD_PID=$(lsof -ti:$PORT 2>/dev/null || true)
if [[ -n "$OLD_PID" ]]; then
    kill "$OLD_PID" 2>/dev/null || true
    echo -e "${GREEN}✓ Proses di port $PORT (PID $OLD_PID) dihentikan.${NC}"
    STOPPED=1
fi

if [[ "$STOPPED" -eq 0 ]]; then
    echo -e "${YELLOW}  Tidak ada server PG Manager yang sedang berjalan.${NC}"
fi

# Hapus file info
rm -f "$INFO_FILE"

echo ""
echo -e "  Untuk menjalankan kembali: ${GREEN}bash install.sh${NC}"
echo ""

#!/usr/bin/env bash
# Run in cPanel Terminal as user ocew9596.
set -u

HOME_DIR="/home/ocew9596"
cd "$HOME_DIR" || { echo "Cannot cd to $HOME_DIR"; exit 1; }

echo "=== Account & disk ==="
whoami
pwd
df -h "$HOME_DIR" 2>/dev/null || df -h .

echo ""
echo "=== backup/ ==="
ls -la backup/ 2>/dev/null || echo "(no backup/ or not readable)"

echo ""
echo "=== .trash/ ==="
ls -la .trash/ 2>/dev/null || echo "(no .trash/ or not readable)"

echo ""
echo "=== Archives (*.tar.gz, *.zip) maxdepth 3 ==="
find . -maxdepth 3 \( -name '*.tar.gz' -o -name '*.zip' \) 2>/dev/null | head -50

echo ""
echo "=== public_html_backup* ==="
ls -la public_html_backup* 2>/dev/null || echo "(no public_html_backup* in home)"

echo ""
echo "=== JetBackup / common cPanel backup paths ==="
ls -la "$HOME_DIR"/jetbackup* 2>/dev/null || echo "(no ~/jetbackup* visible to user)"
ls -la /backup 2>/dev/null || echo "(/backup not visible — normal on shared hosting)"
ls -la /var/cpanel/backups 2>/dev/null || echo "(/var/cpanel/backups not visible)"

echo ""
echo "=== public_html snapshot (sanity) ==="
ls -la public_html/ 2>/dev/null | head -15

echo ""
echo "Done. Prefer JetBackup 5 in cPanel for full restore if manual archives are empty."
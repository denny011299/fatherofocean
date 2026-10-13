Noto Sans SC for Terima Barang PDF — Chinese labels (客户, 入库日期, …).

Dompdf requires a real TrueType (.ttf) file, NOT an OTF renamed to .ttf.

Recommended on server (pick one):
  storage/fonts/NotoSansSC-Regular.ttf   (auto-copied on first export)
  public/fonts/NotoSansSC-Regular.ttf    (must be real TTF; check magic bytes)

Minimum size ~500 KB. Full font ~10–18 MB is OK.

If missing, the app tries to download the full TTF on first PDF export (needs HTTPS).

After deploy:
  php artisan view:clear

Manual install (full TTF):
  curl -L -o storage/fonts/NotoSansSC-Regular.ttf ^
    "https://github.com/google/fonts/raw/main/ofl/notosanssc/NotoSansSC-Regular.ttf"

Do NOT upload subset OTF as NotoSansSC-Regular.ttf — Chinese will show as boxes.

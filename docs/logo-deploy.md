# Logo upload — production checklist

The app logo is stored under `storage/app/public/branding/` and served at **`/branding/logo`** (Laravel route). A copy is also mirrored to `public/branding/` when possible. **You do not need `public/storage` symlink for the logo to display.**

## After deploy

1. **Writable directories** (web server user, e.g. `www-data`):
   - `storage/app/public/branding/`
   - `public/branding/` (created automatically on first upload)
   - `storage/` and `bootstrap/cache/` (general Laravel)

2. **PHP upload limits** (logo max 2 MB in app):
   - `upload_max_filesize` ≥ 2M
   - `post_max_size` ≥ 8M (must be larger than upload_max_filesize)

3. **Optional** (other files using `/storage/...` URLs):
   ```bash
   php artisan storage:link
   ```

4. **Verify**
   - Log in as supervisor or super_admin → **Sistem** → upload PNG/JPG/WebP ≤ 2 MB
   - Preview and sidebar should update
   - Open `/branding/logo` in the browser (should return the image, not 404)

## Troubleshooting

| Symptom | Likely cause |
|--------|----------------|
| "Pilih file logo terlebih dahulu" | No file selected, or `post_max_size` exceeded (entire form empty) |
| "File bukan gambar yang valid" | Corrupt file or HEIC from iPhone — convert to JPG first |
| "Logo gagal disimpan" | `storage/app/public` or `public/branding` not writable |
| Success message but no image | DB path missing file — re-upload; check `storage/logs/laravel.log` for `Branding logo` |

## iPhone / WhatsApp photos

HEIC/HEIF is rejected. Save as JPG or PNG before uploading.

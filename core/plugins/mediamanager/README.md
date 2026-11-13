# Media Manager Plugin

A storage cleanup plugin for juwri that scans uploaded images, detects unused/orphaned files, and lets you delete them safely.

## Quick Start

### Option A: Upload via Admin (Recommended)

After the plugin system core change, you can install from the admin panel:

1. Create the zip from the plugin contents:
   ```bash
   cd core/plugins/mediamanager
   zip -r ../../../mediamanager.zip .
   ```

2. Go to `/admin/plugins` in your admin panel.

3. Click **Upload Plugin** and select `mediamanager.zip`.

4. The plugin appears under **Installed Plugins**. Click **Enable**.

5. Tables are created automatically when you first visit the plugin dashboard.

6. Click **Settings** or visit `/admin/plugins/mediamanager` and click **Start Scan**.

### Option B: Run the Enable Script

From the project root:

```bash
php enable-mediamanager.php
```

This command:
- Enables the plugin
- Creates the required database tables
- Clears caches
- Prints the dashboard URL

Delete the script after running:

```bash
rm enable-mediamanager.php
```

### Option C: Manual SQL

```sql
UPDATE options
SET option_value = '["quickorder","mediamanager"]'
WHERE option_name = 'active_plugins';
```

Then visit `/admin/plugins/mediamanager` to auto-create tables.

---

## Features

- Scan all images in `storage/`
- Cross-reference database to identify used vs. orphaned files
- Dashboard with storage statistics
- Paginated orphan viewer with thumbnails
- Bulk delete with move-to-trash safety
- Cleanup audit logs
- Directory filtering


## Important Notes

- **Always back up your `storage/` folder before mass deletion.**
- Files named `default.png` and `default_user.png` are protected and never flagged as orphaned.
- Files in unmapped directories (e.g., `payment_gateways/`) are marked as `unknown`, not orphaned. Review them manually.
- The scanner checks explicit image columns in the database. It does **not** scan HTML content or JSON text fields for embedded image references.

## Reference Map

The scanner checks the following database columns:

| Storage Directory | Database Table(s) | Column(s) |
|---|---|---|
| `storage/allergies` | `allergies` | `image` |
| `storage/blog` | `blog` | `image` |
| `storage/logo` | `options` | `option_value` (site_logo, site_favicon, etc.) |
| `storage/menu` | `menu`, `image_menus` | `image` |
| `storage/profile` | `users` | `image` |
| `storage/store/cover` | `posts` | `cover_image`, `bio_cover_image` |
| `storage/store/logo` | `posts`, `store_options` | `main_image`, `bio_main_image`, `qr_image` |
| `storage/testimonials` | `testimonials` | `image` |

## Troubleshooting

### Plugin not loading

- Ensure the plugin directory is named exactly `mediamanager`
- Ensure the plugin is in `config('settings.active_plugins')`
- Run `php artisan config:clear` and `php artisan view:clear`
- Run `composer dump-autoload`

### Scan fails / times out

- The scan is chunked by directory. If a single directory is too large, increase PHP `max_execution_time` temporarily.
- Clear the scan and try again.

### Images show as orphaned but are actually used

- The file might be referenced in a way the scanner doesn't know about (e.g., in HTML content, custom plugin, or hardcoded template).
- Move to trash first and verify the site still works before emptying trash.

## File Structure

```
core/plugins/mediamanager/
├── plugin.php
├── routes/web.php
├── app/
│   ├── Http/Controllers/Admin/MediaManagerController.php
│   ├── Models/MediaScanFile.php
│   ├── Models/MediaCleanupLog.php
│   └── Services/ImageScanner.php
├── resources/views/admin/
│   ├── dashboard.blade.php
│   ├── install.blade.php
│   ├── orphans.blade.php
│   ├── used.blade.php
│   └── logs.blade.php
├── assets/css/mediamanager.css
└── database/migrations/
    ├── 2026_06_20_000001_create_plugin_mediamanager_files_table.php
    └── 2026_06_20_000002_create_plugin_mediamanager_logs_table.php
```

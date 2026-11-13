<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Console\Commands;

use Juwri\Modules\Menu\Models\Menu;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Intervention\Image\Facades\Image;

/**
 * F11 — Backfill ~200px WebP thumbnails for existing menu item images.
 *
 * New uploads generate a thumb automatically (StorageService::thumbnail, wired
 * into ManagesMenu). This command produces thumbs for images uploaded before
 * that change so srcset has a small candidate to serve. Best-effort and
 * idempotent: it skips items whose thumb already exists and any image it cannot
 * read (e.g. S3-hosted assets not present on local disk).
 */
class BackfillMenuThumbs extends Command
{
    protected $signature = 'menu:backfill-thumbs {--force : Regenerate thumbs even if one already exists}';

    protected $description = 'Generate ~200px WebP thumbnails for existing menu item images (F11).';

    public function handle(): int
    {
        $srcDir  = public_path('storage/menu/');
        $thumbDir = public_path('storage/menu/thumb/');
        File::ensureDirectoryExists($thumbDir);

        $made = 0;
        $skipped = 0;
        $failed = 0;

        Menu::query()
            ->whereNotNull('image')
            ->where('image', '!=', '')
            ->where('image', '!=', 'default.png')
            ->chunkById(200, function ($menus) use ($srcDir, $thumbDir, &$made, &$skipped, &$failed) {
                foreach ($menus as $menu) {
                    $file = $menu->image;
                    $thumbName = pathinfo($file, PATHINFO_FILENAME) . '.webp';
                    $thumbPath = $thumbDir . $thumbName;

                    if (!$this->option('force') && is_file($thumbPath)) {
                        $skipped++;
                        continue;
                    }

                    $srcPath = $srcDir . $file;
                    if (!is_file($srcPath)) {
                        $skipped++; // S3 or missing — nothing to read locally
                        continue;
                    }

                    try {
                        $img = Image::make($srcPath)->resize(200, null, function ($c) {
                            $c->aspectRatio();
                            $c->upsize();
                        });
                        file_put_contents($thumbPath, (string) $img->encode('webp', 82));
                        $made++;
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->warn("Menu #{$menu->id} ({$file}): {$e->getMessage()}");
                    }
                }
            });

        $this->info("Menu thumbnails: {$made} created, {$skipped} skipped, {$failed} failed.");

        return self::SUCCESS;
    }
}

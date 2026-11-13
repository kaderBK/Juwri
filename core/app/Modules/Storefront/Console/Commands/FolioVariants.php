<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Console\Commands;

use App\Models\ImageMenu;
use Illuminate\Console\Command;

/**
 * FP B6 — backfill `-w900.webp` responsive variants for existing image-menu
 * pages. New uploads generate the variant automatically (folio_make_variant,
 * wired into ManagesMenu). This produces variants for pages uploaded before
 * that change so folio_srcset() has a small candidate to serve.
 *
 * Best-effort + idempotent: skips pages whose variant already exists (unless
 * --force) and any image it cannot read on the local disk (e.g. S3-hosted
 * assets not present locally). Scope to one store with --post.
 */
class FolioVariants extends Command
{
    protected $signature = 'folio:variants {--post= : restaurant/post id to scope to} {--force : regenerate even if a variant already exists}';

    protected $description = 'Generate -w900.webp responsive variants for existing image-menu pages (FP B6).';

    public function handle(): int
    {
        $dir = public_path('storage/menu/');
        $made = 0;
        $skipped = 0;
        $failed = 0;

        $query = ImageMenu::query()
            ->whereNotNull('image')
            ->where('image', '!=', '')
            ->where('image', '!=', 'default.png');
        if ($this->option('post')) {
            $query->where('store_id', (int) $this->option('post'));
        }

        $query->chunkById(200, function ($pages) use ($dir, &$made, &$skipped, &$failed) {
            foreach ($pages as $page) {
                $file = (string) $page->image;
                $variant = pathinfo($file, PATHINFO_FILENAME).'-w900.webp';

                if (! $this->option('force') && is_file($dir.$variant)) {
                    $skipped++;
                    continue;
                }
                $src = $dir.$file;
                if (! is_file($src)) {
                    $skipped++; // S3-hosted or missing locally — nothing to read
                    continue;
                }

                if (folio_make_variant($src, $file, (int) $page->store_id)) {
                    $made++;
                } else {
                    $failed++;
                    $this->warn("Image page #{$page->id} ({$file}): variant not written.");
                }
            }
        });

        $this->info("Folio variants: {$made} created, {$skipped} skipped, {$failed} failed.");

        return self::SUCCESS;
    }
}

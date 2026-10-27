<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * BL-036: a view must not link an asset that is not in the repo.
 *
 * The store payment pages (wallet / bank transfer) linked
 * `assets/templates/classic/css/{style,color,rtl}.css` — a bought template's
 * stylesheets that this repo never received, so every one of those pages 404'd
 * three stylesheets on load (and the RTL one only when the locale was RTL).
 * Both pages carry their own inline styles, so the links are simply gone.
 *
 * The guard below is the general form: every LITERAL `asset('assets/…')` path a
 * blade view links must exist on disk (the docroot is the repo root, so the
 * file lives next to `core/`, under `assets/`).
 */
class ClassicAssetReferencesTest extends TestCase
{
    /**
     * Asset paths a view links that are NOT in the repo. They are pre-existing
     * and out of this job's scope (they are not `assets/templates/…`), so they
     * are listed here instead of being fixed: a new dead link must NOT be
     * added to this list — it must be fixed.
     *
     * @var list<string>
     */
    private const ALREADY_MISSING_ASSETS = [
        'assets/images/placeholder.png', // admin menu-template card fallback
        'assets/img/', // runtime upload dir (store main images, staff PWA icons)
    ];

    public function test_every_store_template_asset_a_view_links_exists_on_disk(): void
    {
        $missing = $this->missingAssetPaths();
        $templateMissing = array_values(array_filter(
            $missing,
            fn (string $path): bool => str_starts_with($path, 'assets/templates/')
        ));

        $this->assertSame(
            [],
            $templateMissing,
            'a view links a store-template asset that is not in the repo (404 on every page load)'
        );

        $unexpected = array_values(array_diff($missing, self::ALREADY_MISSING_ASSETS));
        $this->assertSame(
            [],
            $unexpected,
            'a view links an asset that is not in the repo (add it to ALREADY_MISSING_ASSETS only if it is pre-existing)'
        );
    }

    public function test_the_payment_pages_no_longer_link_the_removed_template_stylesheets(): void
    {
        foreach (['wallet-transfer', 'bank-transfer'] as $page) {
            $src = (string) file_get_contents(resource_path('views/store_templates/gateways/'.$page.'.blade.php'));

            $this->assertStringNotContainsString('assets/templates/classic', $src, $page);
            $this->assertStringContainsString('<style nonce="{{ csp_nonce() }}">', $src, $page);
        }
    }

    /**
     * Every literal `asset('assets/…')` path the blade views link that has no
     * file (or directory) behind it in the repo.
     *
     * @return list<string>
     */
    private function missingAssetPaths(): array
    {
        $missing = [];

        foreach ($this->bladeViews() as $view) {
            $src = (string) file_get_contents($view);
            if (preg_match_all("/asset\(\s*'([^']*)'/", $src, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $path) {
                if (! str_starts_with($path, 'assets/')) {
                    continue;
                }
                // a path built at runtime (`{{ … }}`, `$var`) cannot be checked here
                if (str_contains($path, '{{') || str_contains($path, '$')) {
                    continue;
                }

                $path = explode('?', $path, 2)[0];
                if ($path === '' || file_exists(base_path('../'.$path))) {
                    continue;
                }

                $missing[] = $path;
            }
        }

        $missing = array_values(array_unique($missing));
        sort($missing);

        return $missing;
    }

    /**
     * @return list<string>
     */
    private function bladeViews(): array
    {
        $root = resource_path('views');
        if (! is_dir($root)) {
            return [];
        }

        $views = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = (string) $file->getPathname();
            if ($file->isFile() && str_ends_with($path, '.blade.php')) {
                $views[] = $path;
            }
        }

        return $views;
    }
}

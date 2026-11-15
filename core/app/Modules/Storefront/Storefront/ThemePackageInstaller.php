<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront;

use App\Models\Theme;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * P2-5: install a VALIDATED theme package into the versioned store the
 * resolver/renderer already read:
 *
 *   storage/app/marketplace-themes/<slug>/<version>/   (templates, sections, …)
 *   public assets/marketplace-themes/<slug>/<version>/ (the assets/ dir only,
 *     served statically — the theme_asset filter maps into it)
 *
 * Versions are IMMUTABLE (the P2-6/P4-7 pinning contract): installing a
 * slug@version that already exists is refused — sellers ship a new version.
 * Callers MUST run ThemePackageValidator::assertSafe() first; this class
 * re-runs it as a belt-and-braces guard so it cannot be bypassed.
 */
class ThemePackageInstaller
{
    /**
     * @param  string  $status  initial ThemeVersion status — 'review' by default
     *                          (the P4-4 pipeline publishes); resolver serves
     *                          only published versions.
     * @param  int|null  $sellerId  null = platform-owned
     * @return MarketplaceTheme the installed theme version
     *
     * @throws RuntimeException on validation failure or duplicate version.
     */
    public function install(string $zipPath, string $status = 'review', ?int $sellerId = null): MarketplaceTheme
    {
        $manifest = ThemePackageValidator::assertSafe($zipPath);
        $slug = strtolower($manifest['slug']);
        $version = $manifest['version'];

        $storageDir = storage_path('app/marketplace-themes/'.$slug.'/'.$version);
        if (is_dir($storageDir)) {
            throw new RuntimeException("Theme {$slug}@{$version} already exists — versions are immutable, ship a new version.");
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Could not open the theme zip file.');
        }

        try {
            File::ensureDirectoryExists($storageDir);
            if (! $zip->extractTo($storageDir)) {
                throw new RuntimeException('Failed to extract the theme archive.');
            }
        } catch (\Throwable $e) {
            File::deleteDirectory($storageDir); // never leave a half-install
            throw $e;
        } finally {
            $zip->close();
        }

        // Public assets: copy assets/ (and the listing preview) out to the
        // statically-served path; everything else stays out of the doc root.
        $publicDir = public_path('assets/marketplace-themes/'.$slug.'/'.$version);
        if (is_dir($storageDir.'/assets')) {
            File::copyDirectory($storageDir.'/assets', $publicDir);
        }
        if (is_file($storageDir.'/preview.png')) {
            File::ensureDirectoryExists($publicDir);
            File::copy($storageDir.'/preview.png', $publicDir.'/preview.png');
        }

        // P2-6: index the version in the DB (files above are the content; the
        // DB is the ledger the review pipeline + resolver gate work from).
        $themeRow = Theme::firstOrCreate(
            ['slug' => $slug],
            ['name' => $manifest['name'], 'seller_id' => $sellerId, 'status' => 'draft'],
        );
        // The theme set the manifest targets (clothing → retail). Recorded on
        // the row so the catalogue can be filtered by it and the offerability
        // rule enforced when a store applies the theme.
        $themeRow->theme_set = Theme::setForManifest($manifest);
        $themeRow->save();
        $themeRow->versions()->create([
            'semver' => $version,
            'manifest' => $manifest,
            'checksum' => hash_file('sha256', $zipPath),
            'status' => $status,
        ]);

        $theme = MarketplaceThemeResolver::resolve('mp:'.$slug.'@'.$version, gate: false);
        if (! $theme) {
            File::deleteDirectory($storageDir);
            File::deleteDirectory($publicDir);
            throw new RuntimeException('Installed theme did not resolve — package rejected.');
        }

        return $theme;
    }

    /** Remove an installed version (admin/reject path). */
    public function uninstall(string $slug, string $version): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9\-]*$/i', $slug) || ! preg_match('/^[a-z0-9][a-z0-9.\-]*$/i', $version)) {
            throw new RuntimeException('Invalid slug/version.');
        }
        File::deleteDirectory(storage_path('app/marketplace-themes/'.$slug.'/'.$version));
        File::deleteDirectory(public_path('assets/marketplace-themes/'.$slug.'/'.$version));
    }
}

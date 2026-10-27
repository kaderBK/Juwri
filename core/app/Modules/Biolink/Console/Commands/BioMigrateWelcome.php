<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Biolink\Console\Commands;

use App\Services\FeatureAccess;
use App\Support\BioAppearance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Juwri\Core\Models\Store;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Services\BioLinkService;

/**
 * Legacy welcome-page retirement (Phase 2): migrate a store's legacy "Welcome
 * page" CONTENT into its new biolink page — faithfully to the OLD splash design.
 *
 * Mapping (from the old star/lala-theme splash blade; see the retirement plan):
 *   bio_title        -> appearance.display_name          (the card TITLE)
 *   bio_description  -> appearance.tagline (HTML stripped) (the card SUB-TITLE)
 *   bio_main_image   -> appearance.avatar_image           (PROFILE image; copied logo/ -> menu/)
 *   bio_cover_image  -> appearance.cover_image + show_cover (COVER image; copied cover/ -> menu/)
 *   bio_color_12     -> appearance.bg_solid (+bg_type=solid) — the CARD background
 *                       (`.popup-box`); NOT bio_color_1, which is the garish full-
 *                       screen OUTER overlay (`.popup-screen`).
 *   bio_color_3      -> appearance.ink       (title/body text; contrast-guarded vs bg)
 *   bio_color_7      -> appearance.accent + btn_bg (custom-link BUTTON background)
 *   bio_color_8      -> appearance.btn_fg    (button text; contrast-guarded vs btn_bg)
 *   link6..10        -> `link` BLOCKS (the custom-link BUTTONS), label = link_label6..10
 *
 * IGNORED on purpose (owner's request): the SOCIAL row — link1/2/3 + link_label1/2/3
 * (the `.social` icons). Any `link` blocks the earlier `bio:migrate-legacy` created
 * from those legacy URLs are REMOVED here so socials don't linger as buttons.
 *
 * Guarantees:
 *  - STAGED: never sets `enabled` — migrated pages stay draft, and the `biolink`
 *    feature kill-switch is a second gate.
 *  - MERGE-SAFE: appearance only fills keys that are empty; blocks are rebuilt only
 *    from legacy link URLs (never touches an owner-authored block with a fresh URL).
 *  - SKIPS a genuinely hand-built biolink (enabled / non-default preset / any
 *    non-`link` block).
 *  - IDEMPOTENT: re-running converges to the same result. `--dry-run` writes nothing.
 */
class BioMigrateWelcome extends Command
{
    protected $signature = 'bio:migrate-welcome
        {--dry-run : Report what would change without writing}
        {--post= : Restrict to a single store (post id)}
        {--force : Include stores whose biolink looks hand-built (enabled/custom preset/non-link blocks)}';

    protected $description = 'Migrate the legacy welcome-page design (cover/profile/title/subtitle/buttons/card colour) into the new biolink (staged, socials ignored).';

    public function handle(BioLinkService $svc): int
    {
        $dry = (bool) $this->option('dry-run');
        $only = $this->option('post');
        $force = (bool) $this->option('force');

        $scanned = 0;
        $touched = 0;
        $skippedBuilt = 0;
        $blocksMade = 0;

        $query = Store::query()
            ->select(array_merge(
                ['id', 'title', 'slug', 'bio_title', 'bio_description', 'bio_main_image', 'bio_cover_image',
                    'bio_color_3', 'bio_color_7', 'bio_color_8', 'bio_color_12'],
                array_map(fn ($i) => "link{$i}", range(1, 10)),
                array_map(fn ($i) => "link_label{$i}", range(6, 10)),
            ))
            ->where(function ($q) {
                $q->where(fn ($x) => $x->whereNotNull('bio_title')->where('bio_title', '!=', ''))
                    ->orWhere(fn ($x) => $x->whereNotNull('bio_description')->where('bio_description', '!=', ''))
                    ->orWhere(fn ($x) => $x->whereNotNull('bio_main_image')->whereNotIn('bio_main_image', ['', 'default.png']))
                    ->orWhere(fn ($x) => $x->whereNotNull('bio_cover_image')->whereNotIn('bio_cover_image', ['', 'default.png']));
                foreach (range(6, 10) as $i) {
                    $q->orWhere(fn ($x) => $x->whereNotNull("link{$i}")->where("link{$i}", '!=', ''));
                }
            });

        if ($only !== null) {
            $query->where('id', (int) $only);
        }

        $query->orderBy('id')->chunkById(200, function ($stores) use ($svc, $dry, $force, &$scanned, &$touched, &$skippedBuilt, &$blocksMade) {
            foreach ($stores as $store) {
                $scanned++;
                $pid = (int) $store->id;

                $settings = $svc->resolveSettings($pid);
                $appearance = data_get($settings, 'appearance');
                $current = is_array($appearance) ? $appearance : [];

                // Skip a genuinely hand-built biolink (published, custom preset, or a
                // block type the auto link-cutover never creates) unless --force.
                $preset = $settings['preset'];
                $handBuilt = ! empty($settings['enabled'])
                    || ($preset !== '' && $preset !== 'classic_flat')
                    || BioBlock::where('store_id', $pid)->where('type', '!=', 'link')->exists();
                if ($handBuilt && ! $force) {
                    $skippedBuilt++;

                    continue;
                }

                [$backfill, $files, $touchedFields] = $this->buildAppearance($store, $current);
                $buttons = $this->customButtons($store); // link6..10 => [{label,url}]

                if ($backfill === [] && $buttons === []) {
                    continue;
                }

                if ($dry) {
                    $touched++;
                    $bits = $touchedFields;
                    if ($buttons !== []) {
                        $bits[] = count($buttons).' button(s)';
                    }
                    $this->line("  [dry] store {$pid} /{$store->slug}: ".implode(', ', $bits));

                    continue;
                }

                // Copy image files; prune the appearance key if the source is missing
                // (falls back to the store's own logo/cover).
                foreach ($files as [$srcDir, $dstName, $srcName, $key]) {
                    if (! $this->copyMedia($srcDir, $srcName, $dstName)) {
                        unset($backfill[$key]);
                        if ($key === 'cover_image') {
                            unset($backfill['show_cover']);
                        }
                        $touchedFields = array_values(array_filter($touchedFields, fn ($t) => $t !== $key));
                    }
                }

                // Mark migrated FIRST so createBlock/blocksFor never re-synthesize
                // the legacy dual-read while we rebuild.
                $svc->markMigrated($pid);
                $blocksMade += $this->rebuildButtons($store, $buttons);

                if ($backfill !== []) {
                    $planFlags = [
                        'bio_pro' => FeatureAccess::storeAllows($pid, 'bio_pro'),
                        'bio_analytics_pro' => FeatureAccess::storeAllows($pid, 'bio_analytics_pro'),
                    ];
                    $sanitized = BioAppearance::sanitize(array_merge($current, $backfill), $planFlags);
                    $write = ['appearance' => $sanitized]; // STAGED: never sets `enabled`
                    // A cover survived the copy → use a cover-featuring preset so the
                    // banner shows. `cover_card` is a PRO preset; a store not entitled
                    // to pro presets downgrades to classic_flat at render (no cover),
                    // which is the accepted trade-off. Only set when the store is on
                    // the default preset (hand-built pages are skipped upstream).
                    if (isset($backfill['cover_image']) && $preset === 'classic_flat') {
                        $write['preset'] = 'cover_card';
                    }
                    $svc->saveSettings($pid, $write);
                }

                $touched++;
                $label = $touchedFields;
                if ($buttons !== []) {
                    $label[] = count($buttons).' button(s)';
                }
                $this->line("  store {$pid} /{$store->slug}: ".implode(', ', $label));
            }
        });

        $verb = $dry ? 'would update' : 'updated';
        $this->info("bio:migrate-welcome — scanned {$scanned}; {$verb} {$touched}; skipped {$skippedBuilt} hand-built; {$blocksMade} button block(s).");

        return self::SUCCESS;
    }

    /**
     * Build the appearance backfill (fills only empty keys).
     *
     * @param  array<string,mixed>  $current
     * @return array{0: array<string,mixed>, 1: list<array{0:string,1:string,2:string,3:string}>, 2: list<string>}
     */
    private function buildAppearance(Store $store, array $current): array
    {
        $out = [];
        $files = [];
        $touched = [];
        $has = fn (string $k) => isset($current[$k]) && $current[$k] !== '';

        // TITLE + SUB-TITLE (decode HTML entities; legacy fields hold markup).
        $title = $this->plainText((string) $store->bio_title);
        if ($title !== '' && ! $has('display_name')) {
            $out['display_name'] = mb_substr($title, 0, 100);
            $touched[] = 'title';
        }
        $desc = $this->plainText((string) $store->bio_description);
        if ($desc !== '' && ! $has('tagline')) {
            $out['tagline'] = mb_substr($desc, 0, 160);
            $touched[] = 'sub-title';
        }

        // PROFILE image (logo) + COVER image — copied into the media-library dir.
        $logo = (string) $store->bio_main_image;
        if ($logo !== '' && $logo !== 'default.png' && ! $has('avatar_image')) {
            $dst = 'welcome-'.$store->id.'-'.basename($logo);
            $out['avatar_image'] = $dst;
            $files[] = ['storage/store/logo/', $dst, $logo, 'avatar_image'];
            $touched[] = 'profile';
        }
        $cover = (string) $store->bio_cover_image;
        if ($cover !== '' && $cover !== 'default.png' && ! $has('cover_image')) {
            $dst = 'welcome-'.$store->id.'-'.basename($cover);
            $out['cover_image'] = $dst;
            $out['show_cover'] = true;
            $files[] = ['storage/store/cover/', $dst, $cover, 'cover_image'];
            $touched[] = 'cover';
        }

        // CARD colour = bio_color_12 (`.popup-box`), NOT bio_color_1 (the outer
        // full-screen overlay). How it maps depends on the preset the store lands on:
        // a cover kicks it to `cover_card`, whose name/tagline sit on a HARDCODED
        // white heroid — so a dark card there must NOT become a dark page + light ink.
        $colorsSet = [];
        // bio_color_12 as the owner set it. White (#ffffff, the most common card) and
        // black (#000000) are REAL choices, not seed noise — only the platform seed
        // green (#10b981) is skipped, else every white/black card would fall back to
        // the preset's colour (the "why is it green?" bug).
        $bg = $this->normalizeHex((string) $store->getAttribute('bio_color_12'));
        // A migrated cover kicks the store to the `cover_card` preset downstream.
        $coverMigrated = isset($out['cover_image']);
        if ($bg !== null && $bg !== '#10b981') {
            if ($coverMigrated) {
                // cover_card: the name/tagline render on a hardcoded-white heroid
                // (`.bio--cover_card .bio-heroid{background:#fff}`) and the blocks on
                // the light default card. Painting a dark page + light ink (the flat
                // path below) makes the white-heroid name INVISIBLE. So keep the
                // preset's light surfaces (default dark ink) and carry the legacy card
                // colour only as the ACCENT — the primary-CTA tint — with a
                // contrast-guarded label.
                if (! $has('accent')) {
                    $out['accent'] = $bg;
                    $out['accent_ink'] = $this->luma($bg) > 150 ? '#1b1b1b' : '#f7f7f7';
                    $colorsSet[] = 'accent';
                }
            } elseif (! $has('bg_solid')) {
                // classic_flat: the name/tagline render directly on the page bg, so
                // paint it and derive a contrast-guarded ink from the legacy title.
                $out['bg_solid'] = $bg;
                $out['bg_type'] = 'solid';
                $colorsSet[] = 'bg';
                if (! $has('ink')) {
                    $out['ink'] = $this->pickText((string) $store->getAttribute('bio_color_3'), $bg);
                    $colorsSet[] = 'ink';
                }
                // On a DARK flat card the ink is light and the preset's light default
                // button goes light-on-light (a link LABEL uses --ink, not --btn-fg).
                // Make the button the card colour so the light label shows; light
                // cards keep the preset's legible default button.
                if (isset($out['ink']) && $this->luma($out['ink']) > 150) {
                    if (! $has('btn_bg')) {
                        $out['btn_bg'] = $out['bg_solid'];
                        $colorsSet[] = 'btn_bg';
                    }
                    if (! $has('accent')) {
                        $out['accent'] = $out['bg_solid'];
                        $colorsSet[] = 'accent';
                    }
                }
            }
        }
        if ($colorsSet !== []) {
            $touched[] = 'colors('.implode('/', $colorsSet).')';
        }

        return [$out, $files, $touched];
    }

    /** The custom-link BUTTONS: link6..10 (url) + link_label6..10 (label). The
     *  SOCIAL row (link1/2/3) is deliberately excluded.
     *
     * @return list<array{label: string, url: string}>
     */
    private function customButtons(Store $store): array
    {
        $buttons = [];
        foreach (range(6, 10) as $i) {
            $url = trim((string) ($store->{"link{$i}"} ?? ''));
            if ($url === '' || $url === '#') {
                continue;
            }
            $label = $this->plainText((string) ($store->{"link_label{$i}"} ?? ''));
            $buttons[] = ['label' => $label !== '' ? mb_substr($label, 0, 191) : mb_substr($url, 0, 191), 'url' => $url];
        }

        return $buttons;
    }

    /** Remove any `link` blocks that came from the legacy link columns (socials +
     *  prior auto-migrated customs), then create the custom-link buttons. Returns
     *  the number of buttons created. Idempotent: the delete covers the URLs we
     *  are about to (re)create.
     *
     * @param  list<array{label: string, url: string}>  $buttons
     */
    private function rebuildButtons(Store $store, array $buttons): int
    {
        $legacyUrls = [];
        foreach (range(1, 10) as $i) {
            $u = trim((string) ($store->{"link{$i}"} ?? ''));
            if ($u !== '' && $u !== '#') {
                $legacyUrls[] = $u;
            }
        }
        if ($legacyUrls !== []) {
            BioBlock::where('store_id', $store->id)->where('type', 'link')->whereIn('url', $legacyUrls)->delete();
        }
        if ($buttons === []) {
            return 0;
        }
        $pos = (int) BioBlock::where('store_id', $store->id)->max('position');
        $made = 0;
        foreach ($buttons as $b) {
            BioBlock::create([
                'store_id' => $store->id,
                'branch_id' => null,
                'type' => 'link',
                'label' => $b['label'],
                'url' => $b['url'],
                'config' => [],
                'position' => ++$pos,
                'active' => true,
            ]);
            $made++;
        }

        return $made;
    }

    /** Strip HTML tags AND decode entities; collapse whitespace. */
    private function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** Perceived luminance (0-255) of a #rrggbb hex. */
    private function luma(string $hex): float
    {
        return 0.299 * hexdec(substr($hex, 1, 2))
            + 0.587 * hexdec(substr($hex, 3, 2))
            + 0.114 * hexdec(substr($hex, 5, 2));
    }

    /** Prefer the legacy design color if it contrasts its background; otherwise a
     *  guaranteed-legible near-black/near-white derived from the background. */
    private function pickText(string $legacyRaw, string $bgHex): string
    {
        $legacy = $this->normalizeHex($legacyRaw);
        if ($legacy !== null && abs($this->luma($legacy) - $this->luma($bgHex)) >= 80) {
            return $legacy;
        }

        return $this->luma($bgHex) > 150 ? '#1b1b1b' : '#f7f7f7';
    }

    /** Normalize a legacy color string to lowercase #rrggbb, or null if invalid. */
    private function normalizeHex(string $v): ?string
    {
        $v = strtolower(trim($v));
        if (preg_match('/^#([0-9a-f]{6})$/', $v)) {
            return $v;
        }
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $v, $m)) {
            return '#'.$m[1].$m[1].$m[2].$m[2].$m[3].$m[3];
        }

        return null;
    }

    /** Best-effort local copy of a legacy image into the media-library dir. True if
     *  the destination exists afterwards, false if the source is missing / copy
     *  failed (caller then prunes the key). Never throws. */
    private function copyMedia(string $srcDir, string $srcName, string $dstName): bool
    {
        try {
            $src = public_path($srcDir.$srcName);
            $dstDir = public_path('storage/menu/');
            $dst = $dstDir.$dstName;
            if (File::exists($dst)) {
                return true;
            }
            if (! File::exists($src)) {
                return false;
            }
            if (! File::isDirectory($dstDir)) {
                File::makeDirectory($dstDir, 0755, true);
            }
            File::copy($src, $dst);

            return File::exists($dst);
        } catch (\Throwable $e) {
            $this->warn("    image copy skipped ({$srcName}): ".$e->getMessage());

            return false;
        }
    }
}

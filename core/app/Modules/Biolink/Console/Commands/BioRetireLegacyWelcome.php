<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Biolink\Console\Commands;

use App\Services\StorefrontThemeService;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Settings\StoreSettings;

/**
 * Legacy welcome-page retirement (Phase 3): DROP the legacy "Welcome page" columns
 * and PURGE its option rows, after the content has been migrated to the new biolink.
 *
 * IRREVERSIBLE. Deliberately a guarded, owner-run COMMAND (never a migration), so it
 * cannot fire on `artisan migrate` before the content migration + soak.
 *
 * HARD GUARD (cannot be bypassed): refuse if ANY store still resolves to a legacy
 * `*-theme` storefront, because those blades read these columns to render the
 * welcome splash — dropping the columns would blank/break their pages. On a platform
 * where the legacy themes are retired (every store resolves to a modern template)
 * the columns feed nothing and the drop is safe. Run `--dry-run` first; the real
 * drop also needs `--confirm`.
 */
class BioRetireLegacyWelcome extends Command
{
    protected $signature = 'bio:retire-legacy-welcome {--dry-run : Report what would drop/purge, change nothing} {--confirm : Required to actually drop (with --dry-run absent)}';

    protected $description = 'DROP the legacy welcome-page columns + purge its option rows (guarded: refuses while any store renders a legacy *-theme splash).';

    /** The 15 legacy storefront folders whose blades render the welcome splash from these columns. */
    private const SPLASH_THEMES = [
        'classic-theme', 'modern-theme', 'orange-theme', 'star-theme', 'arrow-theme',
        'old-theme', 'lala-theme', 'yellow-theme', 'Ice-cream-theme', 'show-theme',
        'dark-theme', 'old-2-theme', 'white-theme', 'night-theme', 'red-blue-theme',
    ];

    /** Legacy welcome-page columns on `posts` to drop. The branch and branch_address
     *  columns were never added to the current schema, so they are not listed.
     *
     * @return list<string>
     */
    private function targetColumns(): array
    {
        return array_merge(
            ['bio_title', 'bio_description', 'never_show_title', 'bio_main_image', 'bio_cover_image'],
            array_map(fn ($i) => "bio_color_{$i}", range(1, 12)),
            array_map(fn ($i) => "link{$i}", range(1, 10)),
            array_map(fn ($i) => "link_label{$i}", range(1, 10)),
        );
    }

    /** Welcome-page-ONLY post_option keys to purge. Excludes allow_auto_user_info
     *  (order-form auto-fill) and show_open_close_store (menu-header toggle), which
     *  are not part of the welcome splash. */
    private const OPTION_KEYS = [
        'show_lang_switch_on_bio_page', 'show_background_img_on_bio_page', 'show_logo_img_on_bio_page',
        'show_title_on_bio_page', 'show_discription_on_bio_page', 'show_timing_on_bio_page',
        'show_social_on_bio_page', 'show_branches_on_bio_page', 'show_links_on_bio_page',
        'show_continue_btn_on_bio_page', 'show_never_show_on_bio_page', 'show_open_bio_close_store',
        'allow_slider_popup', 'allow_auto_popup',
    ];

    public function handle(StorefrontThemeService $templates): int
    {
        $dry = (bool) $this->option('dry-run');

        // HARD GUARD: no store may resolve to a legacy splash theme.
        $rendering = [];
        $byTemplate = array_count_values(StoreSettings::valuesFor('store_template'));
        foreach ($byTemplate as $template => $count) {
            $resolved = $templates->resolve((string) $template);
            if (in_array($resolved, self::SPLASH_THEMES, true)) {
                $rendering[$resolved] = ($rendering[$resolved] ?? 0) + (int) $count;
            }
        }
        if ($rendering !== []) {
            $total = array_sum($rendering);
            $this->error("ABORT: {$total} store(s) still resolve to a legacy splash theme — dropping the columns would break their welcome page.");
            foreach ($rendering as $theme => $n) {
                $this->line("  {$theme}: {$n}");
            }
            $this->line('Retire the legacy *-theme templates (move those stores to a modern template) before running this.');

            return self::FAILURE;
        }

        // SOFT report: legacy content not yet ported to a biolink (informational).
        $unmigrated = DB::table('stores')
            ->where(function ($q) {
                $q->where(fn ($x) => $x->whereNotNull('bio_title')->where('bio_title', '!=', ''))
                    ->orWhere(fn ($x) => $x->whereNotNull('bio_description')->where('bio_description', '!=', ''));
                foreach (range(1, 10) as $i) {
                    $q->orWhere(fn ($x) => $x->whereNotNull("link{$i}")->where("link{$i}", '!=', ''));
                }
            })
            ->whereNotIn('id', StoreSettings::storeIdsWhere('bio_migrated', fn ($v) => $v === '1'))
            ->count();
        if ($unmigrated > 0) {
            $this->warn("Note: {$unmigrated} store(s) with legacy content are NOT bio_migrated — their welcome content will be lost on drop. Run bio:migrate-welcome first if you want it ported.");
        }

        $cols = array_values(array_filter($this->targetColumns(), fn ($c) => Schema::hasColumn('stores', $c)));
        $optCount = 0;
        foreach (self::OPTION_KEYS as $optionName) {
            $optCount += count(StoreSettings::valuesFor($optionName));
        }

        $this->info(($dry ? '[dry-run] would drop' : 'dropping').' '.count($cols).' posts column(s) and purge '.$optCount.' option row(s).');
        $this->line('  columns: '.implode(', ', $cols));

        if ($dry) {
            return self::SUCCESS;
        }
        if (! $this->option('confirm')) {
            $this->error('Refusing to run without --confirm (this is irreversible). Re-run with --confirm.');

            return self::FAILURE;
        }

        if ($cols !== []) {
            Schema::table('stores', function (Blueprint $table) use ($cols) {
                $table->dropColumn($cols);
            });
        }
        $purged = 0;
        foreach (self::OPTION_KEYS as $optionName) {
            foreach (array_keys(StoreSettings::valuesFor($optionName)) as $storeId) {
                StoreSettings::forget($storeId, $optionName);
                $purged++;
            }
        }

        $this->info('Done: dropped '.count($cols).' column(s), purged '.$purged.' option row(s). Legacy welcome page retired.');

        return self::SUCCESS;
    }
}

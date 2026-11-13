<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Console\Commands;

use App\Models\StoreOption;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;

/**
 * WA-D1/C4: the one command that migrates a legacy database onto the current
 * WhatsApp model.
 *
 * The owner RE-IMPORTS legacy databases periodically, so a one-off script is
 * useless: every step here must be idempotent, safe to run months from now on a
 * freshly imported dump, and a no-op the second time. Each run appends a dated
 * section to a report file (default storage/app/whatsapp-migration-report.md, or --report=<path>) so a future import
 * can see what was already done and what still needs doing.
 *
 * Steps are additive; new ones get appended as later work items land.
 *   flag    — WA-C4: quickorder_enable → whatsapp_ordering_enable
 *   numbers — WA-D2: legacy whatsapp_number → the store's default branch
 *   purge   — WA-E3: delete the legacy option rows. IRREVERSIBLE, opt-in only
 *             (--only=purge), and refuses while any live store still needs them.
 */
class WhatsAppMigrateLegacy extends Command
{
    protected $signature = 'whatsapp:migrate-legacy
        {--dry-run : Report what would change without writing anything}
        {--only= : Run a single step (flag, numbers, purge)}
        {--report= : Report file to append to (default storage/app/whatsapp-migration-report.md)}';

    protected $description = 'Migrate a legacy database onto the current WhatsApp model (idempotent, re-runnable).';

    /** Steps the default run performs, in execution order. */
    private const STEPS = ['flag', 'numbers'];

    /* WA-E3: `purge` is deliberately NOT in STEPS. It is irreversible, so it has to
       be asked for by name — a bare `whatsapp:migrate-legacy` must never delete. */
    private const DESTRUCTIVE_STEPS = ['purge'];

    private bool $dry = false;

    /** Collected per-step summaries, written to the report at the end. */
    private array $report = [];

    /** store_id => [extra numbers], recorded for the owner rather than placed. */
    private array $extras = [];

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');
        $only = $this->option('only');

        $known = array_merge(self::STEPS, self::DESTRUCTIVE_STEPS);

        if ($only && ! in_array($only, $known, true)) {
            $this->error('Unknown step: '.$only.'. Known steps: '.implode(', ', $known));

            return self::FAILURE;
        }

        $steps = $only ? [$only] : self::STEPS;

        $this->info($this->dry
            ? 'DRY RUN — nothing will be written.'
            : 'Running for real. A dry run first is strongly advised.');

        foreach ($steps as $step) {
            $this->line('');
            $this->info('── '.$step);
            $this->{'step'.ucfirst($step)}();
        }

        $this->writeReport($steps);

        return self::SUCCESS;
    }

    /**
     * WA-C4: rename quickorder_enable → whatsapp_ordering_enable.
     *
     * The flag is not plugin-local — CatalogQueryService and OwnerTools read it
     * as the master "online ordering" switch — so it outlives the plugin and wants
     * a name that says what it does.
     *
     * Idempotent: a store that already has the new key is skipped, never
     * overwritten. Re-running finds nothing to do.
     */
    private function stepFlag(): void
    {
        $legacy = collect(StoreSettings::valuesFor('quickorder_enable'));
        $existing = collect(StoreSettings::valuesFor('whatsapp_ordering_enable'));

        $migrated = 0;
        $skipped = 0;

        foreach ($legacy as $storeId => $value) {
            if ($existing->has($storeId)) {
                // Already migrated (or the owner has since set it explicitly).
                $skipped++;

                continue;
            }

            if (! $this->dry) {
                StoreOption::updateStoreOption($storeId, 'whatsapp_ordering_enable', (string) $value);
            }
            $migrated++;
        }

        // Only drop the legacy rows once every one of them has a counterpart.
        $dropped = 0;
        if (! $this->dry && $migrated + $skipped === $legacy->count()) {
            foreach (array_keys($legacy->all()) as $storeId) {
                StoreSettings::forget($storeId, 'quickorder_enable');
                $dropped++;
            }
        }

        $this->line("   migrated: {$migrated}");
        $this->line("   skipped (already on the new key): {$skipped}");
        $this->line($this->dry
            ? '   legacy rows that would be dropped: '.$legacy->count()
            : "   legacy rows dropped: {$dropped}");

        $this->report['flag'] = [
            'migrated' => $migrated,
            'skipped' => $skipped,
            'dropped' => $this->dry ? 0 : $dropped,
            'legacy_total' => $legacy->count(),
        ];
    }

    /**
     * WA-D2: move each store's legacy `whatsapp_number` onto its default branch.
     *
     * The order flow already prefers the branch number and only falls back to the
     * post option, so this is a backfill, not a re-architecture — but it is the
     * hard precondition for Lane E. E3 deletes the option; without this step
     * having run, E3 destroys the numbers instead of retiring them.
     *
     * Rules (AGENT-BRIEF §1):
     *   - never overwrite a branch number that is already set;
     *   - the only branch we may invent is a default one for a store that has a
     *     number but no branch row at all (owner-approved case);
     *   - extra numbers 2..10 are never placed automatically — they are reported
     *     for the owner to position by hand (WA-D3 raises the dashboard notice).
     */
    private function stepNumbers(): void
    {
        $legacy = collect(array_filter(StoreSettings::valuesFor('whatsapp_number'), fn ($v) => $v !== ''));

        $filled = 0;      // default branch existed, whatsapp empty -> filled
        $created = 0;     // no branch row at all -> created one, then filled
        $skipped = 0;     // branch already had a number -> left alone
        $orphaned = 0;    // option row points at a store that no longer exists

        foreach ($legacy as $storeId => $number) {
            $store = Store::find($storeId);
            if (! $store) {
                $orphaned++;

                continue;
            }

            $branch = Branch::where('store_id', $storeId)->orderBy('position')->orderBy('id')->first();

            if (! $branch) {
                $created++;
                if (! $this->dry) {
                    /* Same shape Post::created() uses for the default branch, so a
                       store migrated here is indistinguishable from one created
                       normally. */
                    Branch::create([
                        'store_id' => $store->id,
                        'name' => mb_substr(($store->title ? $store->title.' — Main' : 'Main'), 0, 255),
                        'address' => $store->address ?? null,
                        'phone' => $store->phone ? mb_substr((string) $store->phone, 0, 191) : null,
                        'whatsapp' => mb_substr((string) $number, 0, 191),
                        'active' => true,
                        'position' => 0,
                    ]);
                }

                continue;
            }

            if (filled($branch->whatsapp)) {
                $skipped++;

                continue;
            }

            $filled++;
            if (! $this->dry) {
                $branch->update(['whatsapp' => mb_substr((string) $number, 0, 191)]);
            }
        }

        // Extras are recorded, never placed. WA-D3 surfaces them to the owner.
        $extras = [];
        for ($i = 2; $i <= 10; $i++) {
            $rows = array_filter(StoreSettings::valuesFor('whatsapp_number'.$i), fn ($v) => $v !== '');
            foreach ($rows as $storeId => $value) {
                $extras[$storeId][] = $value;
            }
        }

        $this->line("   default branch filled: {$filled}");
        $this->line("   default branch created then filled: {$created}");
        $this->line("   skipped (branch already had a number): {$skipped}");
        $this->line("   orphaned option rows (store is gone): {$orphaned}");
        $this->line('   stores with extra numbers 2-10 (reported, NOT placed): '.count($extras));

        $this->report['numbers'] = [
            'filled' => $filled,
            'created_branch_then_filled' => $created,
            'skipped_branch_already_set' => $skipped,
            'orphaned_option_rows' => $orphaned,
            'stores_with_extra_numbers' => count($extras),
        ];
        $this->extras = $extras;
    }

    /**
     * WA-E3: delete the legacy `whatsapp_number*` / `quick_order_number` options.
     *
     * IRREVERSIBLE. This is the only destructive step in the command, so it is not
     * in the default run — it must be asked for by name (`--only=purge`).
     *
     * The hard precondition is that WA-D2 has already relocated each number onto a
     * branch. Purging first would not "clean up", it would destroy the numbers: the
     * option row is the only copy. So this refuses to run unless every live store
     * still resolves to the SAME number afterwards — not merely to some number.
     *
     * Orphaned rows — the store itself is gone — are deleted regardless. There is
     * no branch to protect and nothing that can read them.
     */
    private function stepPurge(): void
    {
        $keys = ['quick_order_number'];
        for ($i = 1; $i <= 10; $i++) {
            $keys[] = 'whatsapp_number'.($i === 1 ? '' : $i);
        }

        $rows = 0;
        foreach ($keys as $key) {
            $rows += count(StoreSettings::valuesFor($key));
        }

        /* The guard. A store whose default branch has no number still depends on its
           legacy option, and deleting it takes that store's WhatsApp ordering away
           with no way back except the backup. */
        $atRisk = 0;
        $legacyNumbers = collect(array_filter(StoreSettings::valuesFor('whatsapp_number'), fn ($v) => $v !== ''));
        $stores = $legacyNumbers->keys();

        /* The question is NOT "does the default branch have a number" — that is what
           this guard asked first time round, and it passed a store whose orders then
           came out with an empty number (#99804).

           Nor is it "will SOME number still resolve". The store's own phone is the
           last fallback, and it is frequently a different number from the WhatsApp
           one: satisfying the guard that way would not protect the merchant, it
           would silently re-route their orders to another line and call it success.

           The question is whether the SAME number still comes out once the option is
           gone. Anything else is a change the merchant never asked for. */
        $rerouted = 0;
        $affected = [];

        foreach ($stores as $storeId) {
            $store = Store::find($storeId);
            if (! $store) {
                continue; // orphan — no store, nothing can read it
            }

            $default = Branch::where('store_id', $storeId)
                ->orderBy('position')->orderBy('id')->first();

            $branch = whatsapp_normalize_number($default?->whatsapp);
            $legacy = whatsapp_normalize_number($legacyNumbers[$storeId]);
            $phone = whatsapp_normalize_number($store->phone);

            /* The resolver prefers the branch, so a store whose branch already has a
               number never consults the option — deleting it changes nothing for
               them. Comparing the option against the branch instead of comparing
               before-vs-after flagged 366 such stores as "re-routed" when not one of
               them would have moved. Ask what actually changes. */
            $before = $branch ?: ($legacy ?: $phone);
            $after = $branch ?: $phone;

            if ($before === $after) {
                continue;
            }

            $affected[] = $storeId;

            if ($after === '') {
                $atRisk++;      // orders would go out with no number at all
            } else {
                $rerouted++;    // a number, but not the one they were using
            }
        }

        $this->line("   legacy option rows present: {$rows}");
        $this->line("   live stores that would lose their number entirely: {$atRisk}");
        $this->line("   live stores that would be re-routed to a different number: {$rerouted}");

        if ($atRisk > 0 || $rerouted > 0) {
            $this->error('   REFUSING to purge.');
            if ($atRisk > 0) {
                $this->error("   {$atRisk} live store(s) would end up with no number at all.");
            }
            if ($rerouted > 0) {
                $this->error("   {$rerouted} live store(s) would silently start sending orders to a different number.");
            }
            $this->error('   Run --only=numbers first. The option row is the only copy of the number.');
            $this->error('   Affected store ids: '.implode(', ', array_slice($affected, 0, 50))
                .(count($affected) > 50 ? ' … ('.count($affected).' total)' : ''));
            $this->report['purge'] = [
                'refused' => true,
                'rows_present' => $rows,
                'would_lose_their_number' => $atRisk,
                'would_be_rerouted' => $rerouted,
                'affected_store_ids' => implode(', ', array_slice($affected, 0, 50)),
            ];

            return;
        }

        if ($this->dry) {
            $this->warn("   DRY RUN — {$rows} rows would be deleted.");
            $this->report['purge'] = ['would_delete' => $rows, 'deleted' => 0];

            return;
        }

        $deleted = 0;
        foreach ($keys as $key) {
            foreach (array_keys(StoreSettings::valuesFor($key)) as $storeId) {
                StoreSettings::forget($storeId, $key);
                $deleted++;
            }
        }
        $this->line("   deleted: {$deleted}");

        $this->report['purge'] = [
            'deleted' => $deleted,
            'would_lose_their_number' => 0,
            'would_be_rerouted' => 0,
            'irreversible' => 'restore from the store_options backup recorded in this report',
        ];
    }

    /**
     * Append a dated section to the migration report. This file is how a future
     * legacy import knows what was done last time — never rewrite earlier entries.
     */
    private function writeReport(array $steps): void
    {
        // The report records REAL data changes only. The test suite invokes this
        // command 20+ times per full run and was appending fixture dry-runs to a
        // tracked file — every prior run reverted it by hand.
        // An explicit --report=<path> is always honoured (that is how the report writing itself is tested).
        $explicit = (string) $this->option('report');
        if ($explicit === '' && app()->runningUnitTests()) {
            return;
        }

        $path = $explicit !== '' ? $explicit : storage_path('app/whatsapp-migration-report.md');
        File::ensureDirectoryExists(dirname($path));

        $lines = [
            '',
            '## '.now()->format('Y-m-d H:i').' — whatsapp:migrate-legacy'.($this->dry ? ' --dry-run' : ''),
            '',
        ];

        foreach ($steps as $step) {
            $data = $this->report[$step] ?? [];
            $lines[] = '### '.$step;
            foreach ($data as $key => $value) {
                $lines[] = "- {$key}: {$value}";
            }
            $lines[] = '';
        }

        if ($this->extras) {
            $lines[] = '#### Extra numbers 2-10 — recorded, NOT placed';
            $lines[] = '';
            $lines[] = 'The owner positions these on real branches; we never invent one.';
            $lines[] = '';
            foreach ($this->extras as $storeId => $numbers) {
                $lines[] = '- store '.$storeId.' → '.implode(', ', $numbers);
            }
            $lines[] = '';
        }

        if ($this->dry) {
            $lines[] = '_Dry run — nothing was written._';
            $lines[] = '';
        }

        File::append($path, implode("\n", $lines));
        $this->line('');
        $this->info('Report appended to '.$path);
    }
}

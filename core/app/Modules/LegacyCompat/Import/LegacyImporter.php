<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Http\Hosts\HostMap;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\LegacyCompat\Support\LegacyHosts;
use Juwri\Modules\LegacyCompat\Support\LegacySlugConflicts;
use Juwri\Modules\Payments\Contracts\StoreGatewayKeys;
use Throwable;

/**
 * J52b importer core (plan §1, §6): copies the old platform's rows into this install with their ids.
 *
 *  1. Pre-flight on the source (identity duplicates that would break the target's unique indexes) — blocking.
 *  2. Platform rows, chunked, one transaction per chunk: users (+ the derived owner accounts), allergies.
 *     Users whose platform wallet ledger does not sum to their balance are BLOCKED (not imported, nor their stores).
 *     Then the platform history: wallet ledger, billing transactions and receipts (read-only history, M5).
 *  3. Stores, ONE transaction per store: the store row, its settings and gateways (secrets re-encrypted), then its
 *     branches, tables, categories, items, customers, orders, order lines and extras (priced: recorded price or a
 *     marked snapshot), loyalty, gift cards and store domains; then order totals, a loyalty ledger re-check and the
 *     store's totals ({@see HistoryImporter}). A store whose loyalty ledger does not sum to its balances is BLOCKED
 *     (checked before anything is written, and again before commit). A store either lands completely or not at all;
 *     its `stores` side-table record is the resume marker, so a re-run skips finished stores and retries the rest.
 *     Store domains land unverified (plan §3; they re-verify here) and the source's reseller hosts are listed and kept
 *     off every store domain.
 *  4. Report: counts, conflicts, blocked rows, unknown/missing columns, unmapped source tables and settings, rows never
 *     reached, per-store totals for the cut-over checksum step, domains to re-verify, reseller hosts, and imported
 *     slugs this install's routes shadow (stored on real runs in legacy_slug_conflicts, which the route table reads;
 *     plan §5.4).
 *  5. Real runs only: target sequences of every imported table (this run's or an earlier one's) moved to max(id) + gap.
 *
 * Dry run: the same work inside one outer transaction that is rolled back (the target is left unchanged), so the
 * report reflects real inserts, constraints included. Writes go through the query builder: no model events, no
 * outbox, no mail, no webhooks (plan §4). Every query runs with the tenant scope bypassed (console platform work).
 */
final class LegacyImporter
{
    private readonly ImportReport $report;

    private readonly ImportContext $ctx;

    private readonly RowLoader $loader;

    private readonly AccountDerivation $accounts;

    private readonly StoreSettingsImporter $settings;

    private readonly SourceSecrets $secrets;

    private readonly string $runId;

    /** @var array<string, true> target tables found missing (reported once) */
    private array $missingTargets = [];

    /**
     * @param  array{dry_run?: bool, stores?: list<int>|null, chunk?: int, gap?: int, source_timezone: string, source_app_key?: string, source_cipher?: string, enabled_gateways?: list<string>}  $options
     */
    public function __construct(Connection $source, private readonly Connection $target, private readonly array $options)
    {
        $this->runId = (string) Str::uuid7();
        $this->report = new ImportReport($this->runId, (bool) ($options['dry_run'] ?? false));
        $reader = new SourceReader($source, (int) ($options['chunk'] ?? 500));
        // TIMESTAMP columns read as UTC instants; DATETIME columns are wall times of the source zone (plan Q4, §4.5).
        $this->report->sourceInfo('table_prefix', $source->getTablePrefix());
        foreach ($reader->serverZones() as $key => $zone) {
            $this->report->sourceInfo('server_'.$key, $zone);
        }
        $this->report->sourceInfo('session_time_zone', $reader->utcSession());
        $this->report->sourceInfo('datetime_zone', $options['source_timezone']);
        $this->ctx = new ImportContext($reader, $target, $this->report, $options['source_timezone'], $this->runId);
        $this->loader = new RowLoader($this->ctx, $this->runId);
        $this->accounts = new AccountDerivation($this->ctx, $this->runId);
        $this->secrets = new SourceSecrets((string) ($options['source_app_key'] ?? ''), (string) ($options['source_cipher'] ?? 'AES-256-CBC'));
        $this->settings = new StoreSettingsImporter($this->ctx, $this->secrets, $options['enabled_gateways'] ?? ['stripe', 'paypal'], app(StoreGatewayKeys::class));
    }

    /**
     * @throws ImportFailed with the failing phase and the error class / SQL state only (a database error message can
     *                      carry row values: emails, password hashes)
     */
    public function run(): ImportReport
    {
        TenantContext::bypass(function (): void {
            if (! $this->report->dryRun) {
                try {
                    $this->work();
                } catch (Throwable $e) {
                    // Rows already committed (earlier chunks / stores) must not leave the sequences behind.
                    try {
                        $this->bump();
                    } catch (Throwable) {
                        // the original failure is the one to report
                    }
                    throw ImportFailed::from($e, 'import');
                }
                $this->bump();

                return;
            }
            $this->target->beginTransaction();
            try {
                $this->work();
            } catch (Throwable $e) {
                throw ImportFailed::from($e, 'dry run');
            } finally {
                if ($this->target->transactionLevel() > 0) {
                    $this->target->rollBack();
                }
                StoreSettings::flush();
            }
        });

        return $this->report;
    }

    /** Real runs only, after success AND failure: every written table past max(target id, source id) + gap. */
    private function bump(): void
    {
        $floors = $this->sourceFloors();
        SequenceBumper::bump($this->target, $this->sequenceTables($floors), (int) ($this->options['gap'] ?? 1000), $this->report, $floors);
    }

    /**
     * Highest source id per target table (ids are kept): a partial run (--store) or a store that failed still leaves
     * the sequence past every id a later run will import.
     *
     * @return array<string, int>
     */
    private function sourceFloors(): array
    {
        $floors = [];
        foreach (LegacyTableMaps::all() as $map) {
            if ($this->ctx->source->has($map->source) && in_array('id', $this->ctx->source->columns($map->source), true)) {
                $max = (int) $this->ctx->source->db->table($map->source)->max('id');
                $floors[$map->target] = max($floors[$map->target] ?? 0, $max);
            }
        }

        return $floors;
    }

    private function work(): void
    {
        if (! (new SourceClock($this->ctx))->check() || ! $this->preflight()) {
            return;
        }
        $this->platformCurrency();
        // New ids (rows with no source id of their own) start above every id a map keeps.
        $this->ctx->idFloors = $this->sourceFloors();
        $this->accounts->reload();
        $this->ctx->blockedUsers = (new LedgerChecks($this->ctx))->walletMismatches();
        $this->ctx->blockedRows = BillingDuplicates::scan($this->ctx->source);
        $this->importUsers();
        $this->importPlatform(LegacyTableMaps::allergies());
        foreach ([...LegacyContentMaps::platform(), ...PlatformContentMaps::platform(), ...ModifierMaps::platform()] as $map) {
            $this->importPlatform($map);
        }
        (new OpeningBalances($this->ctx))->write();
        foreach (LegacyTableMaps::platformHistory() as $map) {
            if ($map->target === 'wallet_transactions' && $this->ctx->platformCurrency === null && $this->ctx->source->has($map->source)) {
                $this->report->conflict('platform currency unknown: wallet ledger not imported', $map->source);

                continue;
            }
            $this->importPlatform($map);
        }
        $this->importShoppers();
        foreach (MarketplaceMaps::platform() as $map) {
            $this->importPlatform($map);
        }
        // J59c: each owner's plan and paid-until date (after billing history: a subscription names its payment).
        $this->phase('plan memberships', fn () => (new PlanMemberships($this->ctx))->import());
        $this->resellerHosts();
        $this->domainOwners();
        $this->importStores();
        $this->reportCoverage();
        $this->decisions();
        $this->slugConflicts();
    }

    /**
     * The source's reseller hosts (plan §3, DDL diff §5 #12): resellers themselves are the Reseller edition's import, so
     * this run only lists each host with its state and keeps every store domain off it. A reseller has up to three:
     * its custom domain, a requested (not yet approved) custom domain, and its subdomain label under the old platform
     * host (protected under every configured legacy host; with none configured it is listed only).
     */
    private function resellerHosts(): void
    {
        $source = $this->ctx->source;
        if (! $source->has(LegacyTableMaps::RESELLERS)) {
            return;
        }
        $columns = $source->columns(LegacyTableMaps::RESELLERS);
        $kinds = array_values(array_intersect(['custom_domain', 'requested_domain', 'subdomain'], $columns));
        if ($kinds === []) {
            return;
        }
        $hasActive = in_array('active', $columns, true);
        $source->chunk(LegacyTableMaps::RESELLERS, function (array $rows) use ($hasActive, $kinds): void {
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                $active = ! $hasActive || filter_var($row['active'] ?? false, FILTER_VALIDATE_BOOLEAN);
                foreach ($kinds as $kind) {
                    $value = LegacyHosts::normalize((string) ($row[$kind] ?? ''));
                    if ($value === '') {
                        continue;
                    }
                    if ($kind !== 'subdomain') {
                        $this->resellerHost($id, $value, $active, $kind);

                        continue;
                    }
                    if (preg_match(LegacyHosts::LABEL_PATTERN, $value) !== 1 || $value === 'www') {
                        $this->report->resellerHost($id, $value, $active, 'refused: not a single subdomain label', $kind);

                        continue;
                    }
                    $legacy = LegacyHosts::platformHosts();
                    if ($legacy === []) {
                        $this->report->resellerHost($id, $value, $active, 'listed: no legacy host configured (protected once one is)', $kind);
                    }
                    foreach ($legacy as $host) {
                        $this->resellerHost($id, $value.'.'.$host, $active, $kind);
                    }
                }
            }
        });
    }

    /** Check, protect and list one reseller host. */
    private function resellerHost(int $id, string $host, bool $active, string $kind): void
    {
        $bare = StoreDomainRules::bare($host);
        $status = $kind === 'requested_domain'
            ? 'listed: requested (not approved at the source); import with the reseller, re-verify on this install'
            : 'listed: import with the reseller (not by this importer); re-verify on this install';
        if (preg_match(LegacyHosts::HOST_PATTERN, $host) !== 1) {
            $status = 'refused: not a valid host name';
        } elseif (($this->ctx->resellerHosts[$bare] ?? $this->ctx->resellerRequestedHosts[$bare] ?? $id) !== $id) {
            $status = 'refused: also the host of reseller#'.($this->ctx->resellerHosts[$bare] ?? $this->ctx->resellerRequestedHosts[$bare]);
        } elseif ($kind !== 'subdomain' && (LegacyHosts::isPlatformHost($host) || in_array($bare, HostMap::platformHosts(), true))) {
            $status = 'refused: a platform host';
        } elseif (in_array($bare, HostMap::platformHosts(), true)) {
            $status = 'refused: a platform host';
        } elseif ($this->target->table('store_domains')->whereIn('host', array_values(array_unique([$host, $bare, 'www.'.$bare])))->exists()
            || app(ResellerContext::class)->isActiveCustomDomain($bare)) {
            $status = 'conflict: the host is already on this install';
        } elseif ($kind === 'requested_domain') {
            // Only a request: a store domain the source verified on this host keeps it (StoreDomainRules).
            $this->ctx->resellerRequestedHosts[$bare] = $id;
        } else {
            $this->ctx->resellerHosts[$bare] = $id;
        }
        $this->report->resellerHost($id, $host, $active, $status, $kind);
    }

    /**
     * Pre-scan of the source store domains (plan §3): a host (or its www./bare twin) claimed by several DIFFERENT
     * stores goes only to the one store the source verified it for; with no single such store nobody gets it.
     */
    private function domainOwners(): void
    {
        $source = $this->ctx->source;
        $table = LegacyTableMaps::DOMAINS;
        $hostCol = $source->has($table) ? $source->pick($table, ['domain', 'host']) : null;
        $storeCol = $hostCol === null ? null : $source->pick($table, LegacyTableMaps::STORE_KEYS);
        if ($hostCol === null || $storeCol === null) {
            return;
        }
        /** @var array<string, array<int, bool>> $byHost bare host => store id => the store has a source-verified row */
        $byHost = [];
        $source->chunk($table, function (array $rows) use (&$byHost, $hostCol, $storeCol): void {
            foreach ($rows as $row) {
                $host = LegacyHosts::normalize((string) ($row[$hostCol] ?? ''));
                if ($host === '') {
                    continue;
                }
                $store = (int) ($row[$storeCol] ?? 0);
                $bare = StoreDomainRules::bare($host);
                $byHost[$bare][$store] = ($byHost[$bare][$store] ?? false) || StoreDomainRules::sourceVerified($row);
            }
        });
        foreach ($byHost as $bare => $stores) {
            if (count($stores) < 2) {
                continue; // one store (apex + www. twin included): the same-store duplicate rule applies
            }
            $verified = array_keys(array_filter($stores));
            $this->ctx->domainWinners[$bare] = count($verified) === 1 ? (int) $verified[0] : 0;
        }
    }

    /**
     * A map whose target table this install does not have (the Enterprise marketplace tables on a Community install):
     * nothing is imported into another table; reported once per source table that has rows.
     */
    private function targetMissing(TableMap $map): bool
    {
        if ($this->target->getSchemaBuilder()->hasTable($map->target)) {
            return false;
        }
        if (! isset($this->missingTargets[$map->target]) && $this->ctx->source->db->table($map->source)->exists()) {
            $this->report->conflict('target table missing on this install (Enterprise marketplace not installed): source table not imported', $map->source.' > '.$map->target);
        }
        $this->missingTargets[$map->target] = true;

        return true;
    }

    /**
     * Shoppers with their wallet ledgers (J58d), one shopper per transaction ({@see ShopperWallets}): a shopper whose
     * ledger does not add up is blocked (source check first, written rows re-checked before commit), reported, and
     * retried by a later run. Resume: a shopper an earlier run wrote is skipped with its ledger.
     */
    private function importShoppers(): void
    {
        $source = $this->ctx->source;
        $map = MarketplaceMaps::shoppers();
        if (! $source->has($map->source) || $this->targetMissing($map)) {
            return;
        }
        $wallets = new ShopperWallets($this->ctx, $this->loader);
        $this->ctx->blockedShoppers = $wallets->sourceMismatches();
        $source->chunk($map->source, function (array $rows) use ($map, $wallets): void {
            $fresh = $this->notYetImported($map, $rows);
            // Resume: an imported shopper whose source ledger has gained or lost rows since is reported (blocked).
            $done = array_values(array_diff(array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $rows), array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $fresh)));
            foreach ($wallets->changedSinceImport($done) as $id => [$sourceRows, $written]) {
                $this->report->blocked('shoppers', 'shopper wallet ledger changed since its import (source rows '.$sourceRows.', imported '.$written.')', 'shopper#'.$id);
            }
            foreach ($fresh as $row) {
                $id = (int) ($row['id'] ?? 0);
                $reason = $this->ctx->blockedShoppers[$id] ?? null;
                if ($reason !== null) {
                    $this->report->source('shoppers');
                    $this->report->blocked('shoppers', $reason, 'shopper#'.$id);

                    continue;
                }
                $checkpoint = $this->report->checkpoint();
                try {
                    $this->target->transaction(fn () => $wallets->importOne($row));
                } catch (StoreBlocked $e) {
                    // Rolled back with its ledger (and so are its counts): reported as blocked, retried by a later run.
                    $this->report->restore($checkpoint);
                    $this->ctx->blockedShoppers[$id] = ShopperWallets::REASON;
                    $this->ctx->forget('shoppers', $id);
                    $this->report->source('shoppers');
                    $this->report->blocked('shoppers', $e->getMessage(), 'shopper#'.$id);
                } catch (Throwable $e) {
                    // Rolled back with its ledger; the run goes on (error class / SQL state only: no row values).
                    $this->report->restore($checkpoint);
                    $this->ctx->forget('shoppers', $id);
                    $this->report->source('shoppers');
                    $this->report->skipped('shoppers', 'shopper failed and was rolled back ('.ImportFailed::describe($e).')', 'shopper#'.$id);
                    if ($this->report->dryRun && $this->target->transactionLevel() === 0) {
                        throw ImportFailed::from($e, 'shopper '.$id);
                    }
                }
            }
        });
    }

    /**
     * The report's owner-decision sections (J59): what this run did about each decided migration question, from its
     * counters and settings (no row values).
     */
    private function decisions(): void
    {
        $c = $this->report->counters();
        $n = static fn (string $key): int => (int) ($c[$key] ?? 0);
        $tables = $this->report->toArray()['tables'];
        $w = static fn (string $table): int => (int) ($tables[$table]['written'] ?? 0);
        $this->report->decision('J59c_plans', [
            'plan_map_entries' => count(PlanMemberships::map()),
            'plans_kept' => $n('plans_kept'), 'trials_kept' => $n('plans_trial_kept'), 'unmapped_to_free' => $n('plans_unmapped'),
            'mapped_to_free' => $n('plans_mapped_to_free'), 'gateway_subscriptions_dropped' => $n('plans_gateway_subscription_dropped'),
            'addons_kept' => $w('account_addons'), 'addon_gateway_subscriptions_dropped' => $n('addons_gateway_subscription_dropped'),
            'renewal' => 'at the paid-until date this install\'s renewal flow asks the owner to subscribe on its billing',
        ]);
        $this->report->decision('J59e_gateways', [
            'enabled_gateways' => $this->options['enabled_gateways'] ?? ['stripe', 'paypal'],
            'stores_with_gateways_switched_off' => $this->report->gatewaysOff(),
            'owner_notice' => 'shown once on the owner dashboard (switch to Stripe or PayPal, or wait)',
        ]);
        $this->report->decision('J59g_scope', [
            'rate_plans' => $w('rate_plans'), 'room_rates' => $w('room_rates'), 'hotel_stays_open' => $w('hotel_stays'),
            'hotel_folios_opened' => $n('hotel_stays_folio_opened'), 'hotel_bookings_future' => $w('hotel_bookings'),
            'hotel_booking_nights' => $w('hotel_booking_nights'), 'reservations' => $w('reservations'),
            'reservation_deposits_held' => $n('reservations_deposits_held'), 'catering_requests' => $w('catering_requests'),
            'catering_deposits_held' => $n('catering_requests_deposits_held'), 'blog_posts' => $w('blog_posts'), 'pages' => $w('pages'),
            'faq_entries' => $w('faq_entries'), 'push_subscriptions' => $w('push_subscriptions') + $w('customer_push_subscriptions'),
            'storefront_keys' => $w('storefront_tokens'), 'storefront_keys_hashed_at_import' => $n('storefront_tokens_hashed_at_import'),
            'not_migrated' => 'support tickets, disputes (skipped by decision)',
        ]);
        $this->report->decision('J59h_views', [
            'store_page_views' => $w('store_page_views'), 'ips_hashed' => $n('store_page_views_ip_hashed'),
            'daily_rollups' => $w('store_page_view_daily'), 'hash' => 'keyed sha256 (install secret); raw IPs never stored',
        ]);
        $this->report->decision('J59k_google_wallet', [
            'issuer_configured' => trim((string) config('legacy_compat.google_wallet.issuer_id', '')) !== '',
            'note' => 'stays off until the owner confirms the issuer before cut-over',
        ]);
    }

    /** Imported slugs this install's routes would shadow (plan §5.4): reported; stored on real runs only. */
    private function slugConflicts(): void
    {
        $conflicts = LegacySlugConflicts::scan($this->target);
        $this->report->slugConflicts($conflicts);
        if (! $this->report->dryRun && $this->target->getSchemaBuilder()->hasTable(LegacySlugConflicts::TABLE)) {
            LegacySlugConflicts::persist($conflicts, $this->target);
        }
    }

    /** Identity duplicates the target's case-insensitive unique indexes would reject: reported, and nothing runs. */
    private function preflight(): bool
    {
        $source = $this->ctx->source;
        if (! $source->has(LegacyTableMaps::USERS)) {
            $this->report->conflict('blocked: the source has no users table', LegacyTableMaps::USERS);

            return false;
        }
        $ok = true;
        foreach (['email', 'username'] as $column) {
            if (! in_array($column, $source->columns(LegacyTableMaps::USERS), true)) {
                continue;
            }
            $dupes = $source->db->table(LegacyTableMaps::USERS)
                ->selectRaw('lower('.$source->db->getQueryGrammar()->wrap($column).') as v, count(*) as n')
                ->whereNotNull($column)->where($column, '!=', '')
                ->groupByRaw('lower('.$source->db->getQueryGrammar()->wrap($column).')')
                ->havingRaw('count(*) > 1')->get();
            foreach ($dupes as $dupe) {
                $ids = $source->db->table(LegacyTableMaps::USERS)
                    ->whereRaw('lower('.$source->db->getQueryGrammar()->wrap($column).') = ?', [$dupe->v])->orderBy('id')->pluck('id')->all();
                $this->report->conflict("blocked: users.{$column} differs only by case (target index is case-insensitive)", 'user#'.implode(',', $ids));
                $ok = false;
            }
        }

        return $ok;
    }

    private function platformCurrency(): void
    {
        $code = null;
        if ($this->ctx->source->has(LegacyTableMaps::PLATFORM_OPTIONS)) {
            $row = $this->ctx->source->where(LegacyTableMaps::PLATFORM_OPTIONS, 'option_name', LegacyTableMaps::PLATFORM_CURRENCY_OPTION)[0] ?? null;
            $code = $row['option_value'] ?? null;
        }
        $exponent = $this->ctx->exponentOf(is_string($code) ? $code : null);
        if ($exponent === null) {
            $this->report->conflict('platform currency unknown: user balances not imported', LegacyTableMaps::PLATFORM_OPTIONS);

            return;
        }
        $this->ctx->platformCurrency = strtoupper((string) $code);
        $this->ctx->platformExponent = $exponent;
    }

    private function importUsers(): void
    {
        $map = LegacyTableMaps::users();
        if ($this->ctx->platformCurrency === null) {
            $map = new TableMap(
                source: $map->source, target: $map->target, scope: $map->scope, columns: $map->columns, dates: $map->dates,
                bools: $map->bools, ignore: [...$map->ignore, 'balance'], uses: $map->uses, transform: $map->transform,
            );
        }
        $this->ctx->source->chunk($map->source, function (array $rows) use ($map): void {
            $rows = $this->notYetImported($map, $rows);
            $rows = array_values(array_filter($rows, function (array $r): bool {
                $reason = $this->ctx->blockedUsers[(int) ($r['id'] ?? 0)] ?? null;
                if ($reason === null) {
                    return true;
                }
                $this->report->source('users');
                $this->report->blocked('users', $reason, 'user#'.(int) $r['id']);

                return false;
            }));
            if ($rows === []) {
                return;
            }
            $this->phase('users', fn () => $this->target->transaction(function () use ($map, $rows): void {
                $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
                $owners = $this->accounts->owners($ids, LegacyTableMaps::STORES, LegacyTableMaps::STORE_OWNER);
                $ownerRows = array_values(array_filter($rows, fn (array $r): bool => in_array((int) $r['id'], $owners, true) && ! isset($this->ctx->accounts[(int) $r['id']])));
                $this->accounts->create($ownerRows, $map->source);
                $this->loader->load($map, $rows);
            }));
        });
    }

    private function importPlatform(TableMap $map): void
    {
        if (! $this->ctx->source->has($map->source) || $this->targetMissing($map)) {
            return;
        }
        $this->ctx->source->chunk($map->source, function (array $rows) use ($map): void {
            if ($map->requiredRefs === ['shopper_id' => 'shoppers'] && $this->ctx->blockedShoppers !== []) {
                // Rows of a blocked shopper stay with it (reported once, as the shopper).
                $rows = array_values(array_filter($rows, fn (array $r): bool => ! isset($this->ctx->blockedShoppers[(int) ($r['shopper_id'] ?? 0)])));
            }
            if (in_array($map->source, ['transaction', 'billing_receipts'], true)) {
                // Order payments are the stores' history: loaded with their store (see loadOrderPayments()).
                $rows = array_values(array_filter($rows, static fn (array $r): bool => ! LegacyTableMaps::isOrderPayment($r)));
            }
            $storeColumn = $map->storeColumns === [] ? null : $this->ctx->source->pick($map->source, $map->storeColumns);
            if ($storeColumn !== null) {
                // A row naming a store belongs to that store (J29): imported with it, not as a platform row.
                $rows = array_values(array_filter($rows, static fn (array $r): bool => (int) ($r[$storeColumn] ?? 0) <= 0));
            }
            $rows = $this->notYetImported($map, $rows);
            $rows = $this->withoutBlockedRows($map, $rows);
            if ($rows !== []) {
                $this->phase($map->target, fn () => $this->target->transaction(fn () => $this->loader->load($map, $rows)));
            }
        });
    }

    /** Run one platform phase; a failure leaves as ImportFailed (no row values in the message). */
    private function phase(string $name, callable $work): void
    {
        try {
            $work();
        } catch (ImportFailed $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ImportFailed::from($e, $name);
        }
    }

    private function importStores(): void
    {
        $source = $this->ctx->source;
        if (! $source->has(LegacyTableMaps::STORES)) {
            $this->report->conflict('the source has no stores table', LegacyTableMaps::STORES);

            return;
        }
        $only = $this->options['stores'] ?? null;
        $done = array_flip(array_map('intval', $this->target->table(RowLoader::SIDE_TABLE)->where('target_table', 'stores')->pluck('target_id')->all()));
        foreach ($source->ids(LegacyTableMaps::STORES, $only === null || $only === [] ? null : $only) as $storeId) {
            if (isset($done[$storeId])) {
                $this->report->count('stores_already_imported');

                continue;
            }
            $checkpoint = $this->report->checkpoint();
            try {
                $totals = $this->target->transaction(fn () => $this->importStore($storeId));
                if ($totals !== null) {
                    $this->report->storeTotals($storeId, $totals);
                }
            } catch (StoreBlocked $e) {
                // Rolled back, so are its counts; reported as blocked (ids and the check only), retried by a later run.
                $this->report->restore($checkpoint);
                $this->report->source('stores');
                $this->report->blocked('stores', $e->getMessage(), 'store#'.$storeId);
                if ($this->report->dryRun && $this->target->transactionLevel() === 0) {
                    throw ImportFailed::from($e, 'store '.$storeId);
                }
            } catch (Throwable $e) {
                // The store's transaction rolled back (and so are its counts); the run goes on. Only the error class /
                // SQL state is reported (a SQL error message can carry row values).
                $this->report->restore($checkpoint);
                $this->report->source('stores');
                $this->report->skipped('stores', 'store failed and was rolled back ('.ImportFailed::describe($e).')', 'store#'.$storeId);
                if ($this->report->dryRun && $this->target->transactionLevel() === 0) {
                    // The outer dry-run transaction is gone; nothing more can be checked safely.
                    throw ImportFailed::from($e, 'store '.$storeId);
                }
            } finally {
                $this->ctx->leaveStore();
            }
        }
    }

    /** @return array<string, int|string>|null the store's totals, or null when the store was not imported */
    private function importStore(int $storeId): ?array
    {
        $source = $this->ctx->source;
        $row = $source->find(LegacyTableMaps::STORES, $storeId);
        if ($row === null) {
            return null;
        }
        $owner = isset($row[LegacyTableMaps::STORE_OWNER]) ? (int) $row[LegacyTableMaps::STORE_OWNER] : 0;
        if (isset($this->ctx->blockedUsers[$owner])) {
            $this->report->source('stores');
            $this->report->blocked('stores', 'owner blocked ('.$this->ctx->blockedUsers[$owner].')', 'store#'.$storeId);

            return null;
        }
        $accountId = $this->ctx->accounts[$owner] ?? null;
        if ($accountId === null) {
            $this->report->source('stores');
            $this->report->skipped('stores', 'owner missing or not imported: account cannot be derived', 'store#'.$storeId);

            return null;
        }
        $loyalty = (new LedgerChecks($this->ctx))->loyaltyMismatches($storeId);
        if ($loyalty !== []) {
            $this->report->source('stores');
            $this->report->blocked('stores', LedgerChecks::LOYALTY_REASON, 'store#'.$storeId.' loyalty_account#'.implode(',', array_slice($loyalty, 0, 20)));

            return null;
        }

        $options = [];
        $storeKey = $source->pick(LegacyTableMaps::STORE_OPTIONS, LegacyTableMaps::STORE_KEYS);
        if ($storeKey !== null) {
            foreach ($source->where(LegacyTableMaps::STORE_OPTIONS, $storeKey, $storeId) as $option) {
                $options[(string) ($option['option_name'] ?? '')] = $option['option_value'] ?? null;
            }
        }
        $currency = trim((string) ($options[LegacyTableMaps::STORE_CURRENCY_OPTION] ?? ''));
        $currency = $currency !== '' ? strtoupper($currency) : $this->ctx->platformCurrency;
        $exponent = $this->ctx->exponentOf($currency);
        if ($currency === null || $exponent === null) {
            $this->report->source('stores');
            $this->report->skipped('stores', 'store currency unknown (no store or platform currency this install knows)', 'store#'.$storeId);

            return null;
        }

        $cards = (new GiftCardBalances($this->ctx))->sourceMismatches($storeId, $currency);
        if ($cards !== []) {
            $this->report->source('stores');
            $this->report->blocked('stores', GiftCardBalances::REASON, 'store#'.$storeId.' gift_card#'.implode(',', array_slice($cards, 0, 20)));

            return null;
        }

        $deposits = (new DepositChecks($this->ctx))->sourceMismatches($storeId, $owner);
        if ($deposits !== []) {
            $this->report->source('stores');
            $this->report->blocked('stores', DepositChecks::REASON, 'store#'.$storeId.' '.implode(',', array_slice($deposits, 0, 20)));

            return null;
        }

        $this->ctx->enterStore($storeId, $accountId, $currency, $exponent);
        $this->ctx->storeOptions = $options;
        if ($this->loader->load(LegacyTableMaps::stores(), [$row]) === []) {
            return null;
        }

        [$vault, $unreadable] = $this->storeVault($storeId);
        $this->settings->import($storeId, $accountId, $options, $vault, $unreadable);

        foreach (LegacyTableMaps::perStore() as $map) {
            $this->loadStoreTable($map, $storeId);
        }
        $this->loadOrderPayments($storeId);

        return (new HistoryImporter($this->ctx))->finishStore($storeId);
    }

    /**
     * The source's encrypted per-store secrets row (gateway_secrets), decrypted with the source key. Other secret
     * features of the store are counted, not imported.
     *
     * @return array{0: array<string, mixed>|null, 1: bool}
     */
    private function storeVault(int $storeId): array
    {
        $source = $this->ctx->source;
        $key = $source->pick(LegacyTableMaps::SECRETS, LegacyTableMaps::STORE_KEYS);
        if ($key === null) {
            return [null, false];
        }
        $vault = null;
        $unreadable = false;
        foreach ($source->where(LegacyTableMaps::SECRETS, $key, $storeId) as $row) {
            if (($row['feature'] ?? null) !== StoreSettingsImporter::SECRET_FEATURE) {
                $this->report->count('store_credentials_not_imported:'.(string) ($row['feature'] ?? '?'));

                continue;
            }
            $decoded = $this->secrets->revealArray($row['secrets'] ?? null);
            if ($decoded === null) {
                $unreadable = true;

                continue;
            }
            $vault = array_merge($vault ?? [], $decoded);
        }

        return [$vault, $unreadable];
    }

    private function loadStoreTable(TableMap $map, int $storeId): void
    {
        $source = $this->ctx->source;
        if (! $source->has($map->source) || $this->targetMissing($map)) {
            return;
        }
        if ($map->scope === TableMap::CHILD) {
            $parents = $this->ctx->storeIdsOf((string) $map->parentTarget);
            if ($parents !== []) {
                $source->chunk($map->source, function (array $rows) use ($map): void {
                    $this->loader->load($map, $rows);
                }, (string) $map->parentColumn, $parents);
            }

            return;
        }
        $column = $source->pick($map->source, $map->storeColumns);
        if ($column === null && $map->rowFilter !== null) {
            return; // a partial map (e.g. library rows by their store column): a sibling map takes the table's rows
        }
        if ($column === null) {
            $this->loader->reportColumns($map);
            $this->report->conflict($map->source.': no store column ('.implode('|', $map->storeColumns).'), table not imported', 'store#'.$storeId);

            return;
        }
        if ($map->selfParent !== null) {
            // Self references resolve only within one load: take the store's rows in one go.
            $all = [];
            $source->chunk($map->source, function (array $rows) use (&$all): void {
                array_push($all, ...$rows);
            }, $column, [$storeId]);
            $this->loader->load($map, $all);

            return;
        }
        $source->chunk($map->source, function (array $rows) use ($map): void {
            $this->loader->load($map, $rows);
        }, $column, [$storeId]);
    }

    /**
     * The store's order payments and their receipts: source billing rows of method `order` whose `user_id` is this
     * store's id (the source's convention). Payments first, so a receipt's transaction resolves within the store.
     */
    private function loadOrderPayments(int $storeId): void
    {
        $source = $this->ctx->source;
        foreach ([LegacyTableMaps::orderPayments(), LegacyTableMaps::orderReceipts()] as $map) {
            $columns = $source->columns($map->source);
            if (! in_array('transaction_method', $columns, true) || ! in_array('user_id', $columns, true)) {
                continue;
            }
            $source->chunk($map->source, function (array $rows) use ($map): void {
                $rows = array_values(array_filter($rows, static fn (array $r): bool => LegacyTableMaps::isOrderPayment($r)));
                $rows = $this->withoutBlockedRows($map, $rows);
                if ($rows !== []) {
                    $this->loader->load($map, $rows);
                }
            }, 'user_id', [$storeId]);
        }
    }

    /**
     * Drop source billing rows blocked by the duplicate pre-scan ({@see BillingDuplicates}), each reported once as
     * blocked. The rest of the run goes on.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withoutBlockedRows(TableMap $map, array $rows): array
    {
        $blocked = $this->ctx->blockedRows[$map->source] ?? [];
        if ($blocked === []) {
            return $rows;
        }

        return array_values(array_filter($rows, function (array $r) use ($map, $blocked): bool {
            $reason = $blocked[(int) ($r['id'] ?? 0)] ?? null;
            if ($reason === null) {
                return true;
            }
            $this->report->source($map->target);
            $this->report->blocked($map->target, $reason, $map->source.'#'.(int) $r['id']);

            return false;
        }));
    }

    /**
     * Platform-scope resume: drop rows an earlier run already wrote.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function notYetImported(TableMap $map, array $rows): array
    {
        $ids = array_map(static fn (array $r): string => (string) ($r['id'] ?? ''), $rows);
        $seen = array_flip($this->target->table(RowLoader::SIDE_TABLE)
            ->where('source_table', $map->source)->where('target_table', $map->target)
            ->whereIn('source_id', $ids)->pluck('source_id')->all());
        if ($seen === []) {
            return $rows;
        }
        // Rows an earlier run wrote are rows of this install: later store rows may name them.
        foreach (array_keys($seen) as $id) {
            $this->ctx->remember($map->target, (string) $id);
        }
        $this->report->count($map->target.'_already_imported', count($seen));

        return array_values(array_filter($rows, static fn (array $r): bool => ! isset($seen[(string) ($r['id'] ?? '')])));
    }

    /** Source tables with no loader, and (full runs) source rows the run never reached (orphans of any kind). */
    private function reportCoverage(): void
    {
        $source = $this->ctx->source;
        $covered = LegacyTableMaps::covered();
        foreach ($source->tables() as $table) {
            if (in_array($table, $covered, true) || LegacyTableMaps::never($table)) {
                continue;
            }
            $decision = LegacyTableMaps::skippedByDecision($table);
            if ($decision !== null) {
                $this->report->skippedByDecision($table, $decision);
            } else {
                $this->report->unmappedTable($table);
            }
        }
        $only = $this->options['stores'] ?? null;
        if ($only !== null && $only !== []) {
            return;
        }
        $tables = $this->report->toArray()['tables'];
        $checked = [];
        foreach ([LegacyTableMaps::stores(), ...LegacyTableMaps::perStore()] as $map) {
            // Sibling maps of one source table (item rows / library rows) are one coverage check.
            if (! $source->has($map->source) || isset($checked[$map->source.'>'.$map->target]) || ! $this->target->getSchemaBuilder()->hasTable($map->target)) {
                continue;
            }
            $checked[$map->source.'>'.$map->target] = true;
            $total = $source->db->table($map->source)->count();
            $seen = (int) ($tables[$map->target]['source'] ?? 0);
            $before = $this->target->table(RowLoader::SIDE_TABLE)->where('source_table', $map->source)
                ->where('target_table', $map->target)->where('run_id', '!=', $this->runId)->count();
            if ($total > $seen + $before) {
                $this->report->conflict($map->target.': source rows never reached (store not imported, or parent row missing)', $map->source.' x'.($total - $seen - $before));
            }
        }
    }

    /**
     * Every table the importer writes that holds rows — including rows an EARLIER (crashed or partial) run wrote: on
     * PostgreSQL explicit ids never advance the sequence, so a resumed run that writes nothing new to a table must
     * still move it past the imported ids.
     *
     * A table still empty here but with source rows (a later partial run imports them) is moved too.
     *
     * @param  array<string, int>  $floors
     * @return list<string>
     */
    private function sequenceTables(array $floors = []): array
    {
        $tables = [];
        foreach ([...ImportGuard::RECORDED_TABLES, ...ImportGuard::OPTIONAL_RECORDED_TABLES, ...ImportGuard::STORE_ROW_TABLES, ...ImportGuard::SEQUENCE_ONLY_TABLES] as $table) {
            if ($this->target->getSchemaBuilder()->hasColumn($table, 'id') && (($floors[$table] ?? 0) > 0 || $this->target->table($table)->exists())) {
                $tables[] = $table;
            }
        }

        return $tables;
    }
}

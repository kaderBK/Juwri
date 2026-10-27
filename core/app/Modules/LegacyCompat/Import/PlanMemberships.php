<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

use Carbon\CarbonImmutable;

/**
 * J59c: each owner keeps the plan they had (mapped to a plan of this install) and its paid-until date; at that date the
 * normal renewal flow of this install asks the owner to subscribe on its own billing. Nothing of a gateway subscription
 * moves (a remote subscription cannot change merchant accounts): the recurring reference is dropped and the membership
 * is a one-time paid period here.
 *
 * The plan map is configuration (legacy_compat.import.plan_map, `source id:target id|free`): a source plan that is not
 * in it, or that names a plan this install does not have, is never guessed — the owner lands on the free plan and the
 * user is reported. Prices are never read from the source.
 *
 * The current state of an owner is read from the source's subscription rows when it has them (latest live or
 * cancelled-but-paid row), else from its legacy membership rows (latest one), else from a numeric plan group with no
 * membership row (an admin grant, no end). Expired states import nothing (free).
 *
 * Written per owner, one transaction each: the legacy membership row (`upgrades`, Core) and, when this install has the
 * subscription tables (Billing), one subscription row + its `created` event. The owner's plan group is set to match.
 * Live add-ons move too (gateway-recurring ones become one-time, wallet auto-renew stays). Resume: an owner whose
 * membership an earlier run wrote is skipped.
 */
final class PlanMemberships
{
    public const SUBSCRIPTIONS = 'subscriptions';

    public const UPGRADES = 'upgrades';

    public const ADDONS = 'user_addons';

    /** Side-table source name of a derived membership (one per owner). */
    public const SOURCE_KEY = 'plan_membership';

    /** Mark on users: the plan the owner had at the source (ids and dates only). */
    public const MARK = 'legacy_plan';

    /** Mark on users: a gateway subscription was dropped; the owner subscribes on this install's billing at renewal. */
    public const GATEWAY_DROPPED = 'legacy_gateway_sub_dropped';

    /** Source statuses that still hold a paid (or trial) period. */
    private const KEPT_STATUSES = ['trialing', 'active', 'past_due', 'paused', 'canceled'];

    /** An expiry beyond this (unix seconds) is a lifetime membership (the legacy convention). */
    private const LIFETIME_AFTER = 4000000000;

    /** The legacy row's expiry for a lifetime membership. */
    private const LIFETIME_EXPIRES = 4294967295;

    public function __construct(private readonly ImportContext $ctx) {}

    /**
     * The configured map: source plan id => target plan id, or null for the free plan.
     *
     * @return array<string, int|null>
     */
    public static function map(): array
    {
        $map = [];
        foreach ((array) config('legacy_compat.import.plan_map', []) as $source => $target) {
            $source = trim((string) $source);
            $target = strtolower(trim((string) $target));
            if ($source === '' || ($target !== 'free' && ! ctype_digit($target))) {
                continue;
            }
            $map[$source] = $target === 'free' ? null : (int) $target;
        }

        return $map;
    }

    /**
     * The plan group an imported user starts with (users transform): 'trial' stays (a trial with no membership row ends
     * by its account age, as at the source), anything else is the free plan until {@see import()} writes the owner's
     * membership. Fail-closed: a paid plan group never lands without its membership row.
     */
    public static function startGroup(mixed $source): string
    {
        return trim((string) $source) === 'trial' ? 'trial' : 'free';
    }

    /**
     * Target plan id of a source plan, or null (free; reported unless the map says free).
     */
    public static function target(string $sourcePlan, string $sample, ImportContext $ctx): ?int
    {
        $map = self::map();
        if (! ctype_digit($sourcePlan)) {
            $ctx->report->conflict('plans: source plan group unknown, free plan (J59c)', $sample.' plan '.$sourcePlan);
            $ctx->report->count('plans_unmapped');

            return null;
        }
        if (! array_key_exists($sourcePlan, $map)) {
            $ctx->report->conflict('plans: source plan not in the plan map (LEGACY_IMPORT_PLAN_MAP), free plan (J59c)', $sample.' plan#'.$sourcePlan);
            $ctx->report->count('plans_unmapped');

            return null;
        }
        $target = $map[$sourcePlan];
        if ($target === null) {
            $ctx->report->count('plans_mapped_to_free');

            return null;
        }
        if (! self::planExists($target, $ctx)) {
            $ctx->report->conflict('plans: the plan map names a plan this install does not have, free plan (J59c)', $sample.' plan#'.$sourcePlan.' > '.$target);
            $ctx->report->count('plans_unmapped');

            return null;
        }

        return $target;
    }

    private static function planExists(int $id, ImportContext $ctx): bool
    {
        $key = 'plan:'.$id;
        if (! array_key_exists($key, $ctx->lookups)) {
            $ctx->lookups[$key] = $ctx->target->getSchemaBuilder()->hasTable('plans') && $ctx->target->table('plans')->where('id', $id)->exists() ? $id : null;
        }

        return $ctx->lookups[$key] !== null;
    }

    /**
     * Write the memberships of every imported owner (after users and billing history), one owner per transaction.
     */
    public function import(): void
    {
        $source = $this->ctx->source;
        $users = array_map('intval', array_keys($this->ctx->platformIds['users'] ?? []));
        sort($users);
        if ($users === []) {
            return;
        }
        $done = array_flip($this->ctx->target->table(RowLoader::SIDE_TABLE)->where('source_table', self::SOURCE_KEY)
            ->where('target_table', self::UPGRADES)->pluck('source_id')->all());
        foreach (array_chunk($users, 200) as $chunk) {
            $rows = [];
            foreach ($source->db->table(LegacyTableMaps::USERS)->whereIn('id', $chunk)->get() as $row) {
                $rows[(int) $row->id] = (array) $row;
            }
            foreach ($chunk as $userId) {
                if (isset($done[(string) $userId])) {
                    $this->ctx->report->count('plan_memberships_already_imported');

                    continue;
                }
                $row = $rows[$userId] ?? null;
                if ($row === null || ($row['user_type'] ?? null) === 'admin') {
                    continue;
                }
                $this->ctx->target->transaction(fn () => $this->owner($userId, $row));
            }
        }
    }

    /** @param array<string, mixed> $user */
    private function owner(int $userId, array $user): void
    {
        $state = $this->state($userId, $user);
        $this->addons($userId);
        if ($state === null) {
            return; // free (or a trial that ends by its account age): the start group stands
        }
        $report = $this->ctx->report;
        $target = $state['plan'] === null ? null : self::target($state['plan'], 'user#'.$userId, $this->ctx);
        if ($state['plan'] !== null && $target === null) {
            // Unmapped / unknown plan: the owner is on the free plan (reported by target()).
            $this->ctx->target->table('users')->where('id', $userId)->update(['group_id' => 'free']);

            return;
        }
        $now = now()->utc()->format('Y-m-d H:i:s');
        $accountId = $this->ctx->accounts[$userId] ?? null;
        $interval = $state['interval'];
        $endsAt = $state['ends_at'];
        $group = $target === null ? 'trial' : (string) $target;

        // The legacy membership row (Core): a one-time paid period up to the source's paid-until date.
        $upgradeId = (int) $this->ctx->target->table('upgrades')->insertGetId([
            'account_id' => $accountId,
            'sub_id' => $group,
            'user_id' => $userId,
            'pay_mode' => 'one_time',
            'interval' => match ($interval) {
                'monthly' => 'MONTHLY',
                'yearly' => 'YEARLY',
                'lifetime' => 'LIFETIME',
                default => null,
            },
            'upgrade_lasttime' => $state['starts_at']?->getTimestamp() ?? 0,
            'upgrade_expires' => $endsAt === null ? self::LIFETIME_EXPIRES : $endsAt->getTimestamp(),
            'trial_ends_at' => $target === null && $endsAt !== null ? $endsAt->format('Y-m-d H:i:s') : null,
            'trial_used' => $target === null,
            'unique_id' => null,
            'status' => $state['status'] === 'past_due' ? 'past_due' : 'Active',
            'grace_until' => $state['grace_until']?->format('Y-m-d H:i:s'),
        ], 'upgrade_id');
        $this->record(self::UPGRADES, $userId, $upgradeId, $state);

        if ($this->ctx->target->getSchemaBuilder()->hasTable('subscriptions')) {
            $meta = ['source' => 'legacy_import', 'legacy_plan_id' => $state['plan']];
            $snapshot = $target === null ? null : $this->ctx->target->table('plans')->where('id', $target)->value('settings');
            if (is_string($snapshot) && json_validate($snapshot)) {
                $meta['entitlements'] = json_decode($snapshot, true); // grandfathering snapshot, as a checkout writes it
            }
            $transaction = $state['transaction_id'];
            $id = $this->ctx->nextId('subscriptions');
            $this->ctx->target->table('subscriptions')->insert([
                'id' => $id,
                'account_id' => $accountId,
                'user_id' => $userId,
                'plan_id' => $target,
                'status' => $state['status'],
                'interval_' => $interval,
                'interval_count' => 1,
                'starts_at' => $state['starts_at']?->format('Y-m-d H:i:s') ?? $now,
                'ends_at' => $endsAt?->format('Y-m-d H:i:s'),
                'anchor_day' => $state['anchor_day'],
                'canceled_at' => $state['canceled_at']?->format('Y-m-d H:i:s'),
                'grace_until' => $state['grace_until']?->format('Y-m-d H:i:s'),
                'pending_plan_id' => $state['pending_plan'] === null ? null : self::target($state['pending_plan'], 'user#'.$userId.' pending', $this->ctx),
                'pending_interval' => $state['pending_plan'] === null ? null : $state['pending_interval'],
                'transaction_id' => $transaction !== null && $this->ctx->resolvesPlatform('transaction', $transaction) ? $transaction : null,
                'meta' => json_encode($meta, JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->record(self::SUBSCRIPTIONS, $userId, $id, $state);
            if ($this->ctx->target->getSchemaBuilder()->hasTable('subscription_events')) {
                $this->ctx->target->table('subscription_events')->insert([
                    'account_id' => $accountId, 'subscription_id' => $id, 'user_id' => $userId, 'event' => 'created',
                    'from_status' => null, 'to_status' => $state['status'], 'actor_type' => 'system', 'actor_id' => null,
                    'meta' => json_encode(['source' => 'legacy_import'], JSON_UNESCAPED_SLASHES), 'created_at' => $now,
                ]);
            }
            $report->written('subscriptions');
        }
        $this->ctx->target->table('users')->where('id', $userId)->update(['group_id' => $group]);
        $this->ctx->mark('users', $userId, self::MARK, [
            'source_plan' => $state['plan'], 'plan_id' => $target, 'status' => $state['status'],
            'paid_until' => $endsAt?->format('Y-m-d H:i:s'),
        ]);
        if ($state['gateway']) {
            $this->ctx->mark('users', $userId, self::GATEWAY_DROPPED);
            $report->count('plans_gateway_subscription_dropped');
        }
        $this->ctx->flushMarks();
        $report->written('upgrades');
        $report->count($target === null ? 'plans_trial_kept' : 'plans_kept');
    }

    /**
     * @param  array{plan: ?string, status: string, interval: string, starts_at: ?CarbonImmutable, ends_at: ?CarbonImmutable, anchor_day: ?int, canceled_at: ?CarbonImmutable, grace_until: ?CarbonImmutable, pending_plan: ?string, pending_interval: ?string, transaction_id: ?int, gateway: bool}  $state
     */
    private function record(string $table, int $userId, int $id, array $state): void
    {
        $this->ctx->target->table(RowLoader::SIDE_TABLE)->insert([
            'source_table' => self::SOURCE_KEY,
            'source_id' => (string) $userId,
            'target_table' => $table,
            'target_id' => (string) $id,
            'source_checksum' => RowLoader::checksum(array_map(static fn (mixed $v): mixed => $v instanceof CarbonImmutable ? $v->format('c') : $v, $state)),
            'run_id' => $this->ctx->runId,
            'created_at' => now()->utc()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * The owner's current source state, or null (free / expired).
     *
     * @param  array<string, mixed>  $user
     * @return array{plan: ?string, status: string, interval: string, starts_at: ?CarbonImmutable, ends_at: ?CarbonImmutable, anchor_day: ?int, canceled_at: ?CarbonImmutable, grace_until: ?CarbonImmutable, pending_plan: ?string, pending_interval: ?string, transaction_id: ?int, gateway: bool}|null
     */
    private function state(int $userId, array $user): ?array
    {
        $source = $this->ctx->source;
        $now = CarbonImmutable::now('UTC');
        $gateway = false;
        $upgrade = null;
        if ($source->has(self::UPGRADES)) {
            $key = $source->key(self::UPGRADES) ?? 'upgrade_id';
            $row = $source->db->table(self::UPGRADES)->where('user_id', $userId)->orderByDesc($key)->first();
            $upgrade = $row === null ? null : (array) $row;
            $gateway = $upgrade !== null && (trim((string) ($upgrade['unique_id'] ?? '')) !== '' || ($upgrade['pay_mode'] ?? null) === 'recurring');
        }
        if ($source->has(self::SUBSCRIPTIONS)) {
            $row = $source->db->table(self::SUBSCRIPTIONS)->where('user_id', $userId)->whereIn('status', self::KEPT_STATUSES)
                ->orderByDesc('id')->first();
            if ($row === null) {
                return null;
            }
            $row = (array) $row;
            $endsAt = $this->date(self::SUBSCRIPTIONS, 'ends_at', $row['ends_at'] ?? null);
            if ($row['status'] === 'canceled' && ($endsAt === null || $endsAt->lessThanOrEqualTo($now))) {
                return null; // cancelled and over: free
            }
            $interval = strtolower((string) ($row['interval_'] ?? 'monthly'));
            $trial = $row['status'] === 'trialing' || $interval === 'trial';
            $plan = $row['plan_id'] ?? null;
            if (! $trial && ($plan === null || $plan === '')) {
                return null;
            }
            $meta = json_decode((string) ($row['meta'] ?? ''), true);
            $gateway = $gateway || (is_array($meta) && (isset($meta['gateway']) || isset($meta['gateway_subscription_id']) || isset($meta['unique_id'])));

            return [
                'plan' => $trial ? null : (string) $plan,
                'status' => $trial ? 'trialing' : (string) $row['status'],
                'interval' => $trial ? 'trial' : (in_array($interval, ['monthly', 'yearly', 'lifetime'], true) ? $interval : 'monthly'),
                'starts_at' => $this->date(self::SUBSCRIPTIONS, 'starts_at', $row['starts_at'] ?? null),
                'ends_at' => $endsAt,
                'anchor_day' => isset($row['anchor_day']) && is_numeric($row['anchor_day']) ? (int) $row['anchor_day'] : null,
                'canceled_at' => $this->date(self::SUBSCRIPTIONS, 'canceled_at', $row['canceled_at'] ?? null),
                'grace_until' => $this->date(self::SUBSCRIPTIONS, 'grace_until', $row['grace_until'] ?? null),
                'pending_plan' => isset($row['pending_plan_id']) && $row['pending_plan_id'] !== '' ? (string) $row['pending_plan_id'] : null,
                'pending_interval' => isset($row['pending_interval']) ? (string) $row['pending_interval'] : null,
                'transaction_id' => isset($row['transaction_id']) && (int) $row['transaction_id'] > 0 ? (int) $row['transaction_id'] : null,
                'gateway' => $gateway,
            ];
        }
        if ($upgrade !== null) {
            $expires = (int) ($upgrade['upgrade_expires'] ?? 0);
            $interval = strtolower((string) ($upgrade['interval'] ?? ''));
            $trial = ! ctype_digit((string) ($upgrade['sub_id'] ?? ''));
            $lifetime = ! $trial && ($interval === 'lifetime' || $expires > self::LIFETIME_AFTER);
            $endsAt = $lifetime ? null : CarbonImmutable::createFromTimestamp($expires, 'UTC');
            if ($endsAt !== null && $endsAt->lessThanOrEqualTo($now)) {
                return null; // the paid (or trial) period is over: free
            }

            return [
                'plan' => $trial ? null : (string) $upgrade['sub_id'],
                'status' => $trial ? 'trialing' : (($upgrade['status'] ?? null) === 'past_due' ? 'past_due' : 'active'),
                'interval' => $trial ? 'trial' : ($lifetime ? 'lifetime' : (in_array($interval, ['monthly', 'yearly'], true) ? $interval : 'monthly')),
                'starts_at' => (int) ($upgrade['upgrade_lasttime'] ?? 0) > 0 ? CarbonImmutable::createFromTimestamp((int) $upgrade['upgrade_lasttime'], 'UTC') : null,
                'ends_at' => $endsAt,
                'anchor_day' => null,
                'canceled_at' => null,
                'grace_until' => $this->date(self::UPGRADES, 'grace_until', $upgrade['grace_until'] ?? null),
                'pending_plan' => null,
                'pending_interval' => null,
                'transaction_id' => null,
                'gateway' => $gateway,
            ];
        }
        $group = trim((string) ($user['group_id'] ?? ''));
        if (ctype_digit($group)) {
            // A plan group with no membership row: an admin grant with no end.
            return [
                'plan' => $group, 'status' => 'active', 'interval' => 'lifetime',
                'starts_at' => $this->date(LegacyTableMaps::USERS, 'created_at', $user['created_at'] ?? null), 'ends_at' => null,
                'anchor_day' => null, 'canceled_at' => null, 'grace_until' => null, 'pending_plan' => null,
                'pending_interval' => null, 'transaction_id' => null, 'gateway' => false,
            ];
        }

        return null;
    }

    /**
     * Live add-ons of the owner (Billing's account add-ons, when this install has them): kept with their paid-through
     * date. A gateway-recurring add-on becomes one-time (no remote subscription moves); a wallet auto-renew stays.
     */
    private function addons(int $userId): void
    {
        $source = $this->ctx->source;
        if (! $source->has(self::ADDONS) || ! $this->ctx->target->getSchemaBuilder()->hasTable('account_addons')) {
            return;
        }
        $now = CarbonImmutable::now('UTC');
        foreach ($source->db->table(self::ADDONS)->where('user_id', $userId)->orderBy('id')->get() as $row) {
            $row = (array) $row;
            $id = (int) $row['id'];
            if ($this->ctx->target->table(RowLoader::SIDE_TABLE)->where('source_table', self::ADDONS)->where('source_id', (string) $id)->exists()) {
                $this->ctx->report->count('account_addons_already_imported');

                continue;
            }
            $this->ctx->report->source('account_addons');
            $expires = $this->date(self::ADDONS, 'expires_at', $row['expires_at'] ?? null);
            if (in_array(strtolower((string) ($row['status'] ?? '')), ['cancelled', 'canceled', 'lapsed', 'expired', 'refunded'], true)
                || ($expires !== null && $expires->lessThanOrEqualTo($now))) {
                $this->ctx->report->skipped('account_addons', 'add-on lapsed at the source (J59c: live add-ons only)', self::ADDONS.'#'.$id);

                continue;
            }
            $currency = $this->ctx->platformCurrency;
            $price = $row['quoted_price'] ?? null;
            $minor = $price === null || $price === '' ? null : $this->ctx->minor($price, $this->ctx->platformExponent);
            if ($price !== null && $price !== '' && ($minor === null || $currency === null)) {
                $this->ctx->report->skipped('account_addons', 'add-on price not numeric or platform currency unknown', self::ADDONS.'#'.$id);

                continue;
            }
            $payMode = (string) ($row['pay_mode'] ?? 'one_time');
            $autoRenew = filter_var($row['auto_renew'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (trim((string) ($row['unique_id'] ?? '')) !== '' || $payMode === 'recurring') {
                $payMode = 'one_time';
                $autoRenew = false;
                $this->ctx->report->count('addons_gateway_subscription_dropped');
            }
            $newId = $this->ctx->nextId('account_addons');
            $this->ctx->target->table('account_addons')->insert([
                'id' => $newId,
                'account_id' => $this->ctx->accounts[$userId] ?? null,
                'user_id' => $userId,
                'type' => (string) $row['type'],
                'kind' => (string) ($row['kind'] ?? 'count'),
                'feature_key' => $row['feature_key'] ?? null,
                'quantity' => max(1, (int) ($row['quantity'] ?? 1)),
                'quoted_price_minor' => $minor,
                'currency_code' => $minor === null ? null : $currency,
                'pay_mode' => $payMode,
                'expires_at' => $expires?->format('Y-m-d H:i:s'),
                'trial_ends_at' => $this->date(self::ADDONS, 'trial_ends_at', $row['trial_ends_at'] ?? null)?->format('Y-m-d H:i:s'),
                'transaction_ref' => $row['transaction_ref'] ?? null,
                'unique_id' => null,
                'auto_renew' => $autoRenew,
                'status' => $row['status'] ?? null,
                'created_at' => $this->date(self::ADDONS, 'created_at', $row['created_at'] ?? null)?->format('Y-m-d H:i:s'),
                'updated_at' => $this->date(self::ADDONS, 'updated_at', $row['updated_at'] ?? null)?->format('Y-m-d H:i:s'),
            ]);
            $this->ctx->target->table(RowLoader::SIDE_TABLE)->insert([
                'source_table' => self::ADDONS, 'source_id' => (string) $id, 'target_table' => 'account_addons',
                'target_id' => (string) $newId, 'source_checksum' => RowLoader::checksum($row), 'run_id' => $this->ctx->runId,
                'created_at' => now()->utc()->format('Y-m-d H:i:s'),
            ]);
            $this->ctx->report->written('account_addons');
        }
    }

    private function date(string $table, string $column, mixed $value): ?CarbonImmutable
    {
        if (! $this->ctx->source->has($table) || ! in_array($column, $this->ctx->source->columns($table), true)) {
            $utc = $this->ctx->utc($value);
        } else {
            $utc = $this->ctx->utc($value, $this->ctx->source->isInstant($table, $column));
        }

        return $utc === null ? null : CarbonImmutable::parse($utc, 'UTC');
    }
}

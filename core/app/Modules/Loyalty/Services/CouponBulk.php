<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Services;

use App\Models\Coupon;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Juwri\Modules\Loyalty\Models\CouponBatch;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Coupons C1: the shared bulk engine for the platform, reseller and store-owner coupon systems. Callers validate
 * input with their own rules and pass a scope; this service only whitelists known columns, generates codes unique
 * within the issuer, and runs scoped filters / bulk actions. No controllers here (C2a–C4b).
 *
 * Scope array (the issuer — the same dimensions as {@see Coupon::issuerKey()}):
 *   ['scope' => 'platform']                     user_id NULL, reseller_id NULL
 *   ['scope' => 'reseller', 'reseller_id' => 12] user_id NULL, reseller_id = 12
 *   ['scope' => 'store',    'user_id' => 34]     user_id = 34 (the store owner)
 *
 * Every query starts from the scope (one nested group no filter can widen) and keeps the tenant global scope
 * (AccountScope) of the Coupon model. Coupons are written through the model, so the value routing, the per-issuer
 * unique key, the issuer currency and the tenant account fill all apply as for a hand-made coupon.
 *
 * Settings array: coupon columns (percentage, limit, expiry_at, coupon_type, applies_interval, first_period_only,
 * min_order_amount, per_customer_limit, branch_id, buy_*, get_*). Scope columns are always forced by the scope.
 */
class CouponBulk
{
    public const MAX_COUNT = 1000;

    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const STATUSES = ['never_used', 'partly_used', 'used_up', 'expired', 'active'];

    /** Coupon columns a caller may set (scope columns are forced by the scope). */
    private const COLUMNS = [
        'percentage', 'limit', 'expiry_at', 'coupon_type', 'applies_interval', 'first_period_only',
        'min_order_amount', 'per_customer_limit', 'branch_id',
        'buy_scope', 'buy_id', 'buy_qty', 'get_id', 'get_qty', 'get_discount_percent',
    ];

    private const CSV_COLUMNS = ['code', 'batch', 'type', 'amount', 'used', 'limit', 'remaining', 'expiry_at', 'status'];

    /** @var Closure(int): string */
    private Closure $random;

    /** @param  (Closure(int): string)|null  $random  test seam: returns a random string of the given length */
    public function __construct(?Closure $random = null)
    {
        $this->random = $random ?? function (int $length): string {
            $max = strlen(self::ALPHABET) - 1;
            $s = '';
            for ($i = 0; $i < $length; $i++) {
                $s .= self::ALPHABET[random_int(0, $max)];
            }

            return $s;
        };
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $settings
     */
    public function generate(array $scope, array $settings, int $count, ?string $prefix = null, int $length = 8, ?string $name = null): CouponBatch
    {
        $scopeRow = $this->scopeColumns($scope);
        if ($count < 1 || $count > self::MAX_COUNT) {
            throw new InvalidArgumentException('Count must be between 1 and '.self::MAX_COUNT.'.');
        }
        if ($length < 6 || $length > 12) {
            throw new InvalidArgumentException('Code length must be between 6 and 12.');
        }
        $prefix = $prefix === null ? '' : strtoupper(trim($prefix));
        if ($prefix !== '' && ! preg_match('/^[A-Z0-9]{1,12}$/', $prefix)) {
            throw new InvalidArgumentException('Prefix must be A-Z / 0-9, at most 12 characters.');
        }

        $values = $this->couponRow($settings);
        $issuerKey = Coupon::issuerKey($scopeRow['user_id'], $scopeRow['reseller_id']);

        // The tenant connection of the models written (BelongsToAccount), not blindly the default one.
        return (new Coupon)->getConnection()->transaction(function () use ($scopeRow, $settings, $count, $prefix, $length, $name, $values, $issuerKey) {
            $batch = CouponBatch::create([
                'name' => $name !== null ? mb_substr($name, 0, 120) : null,
                'issuer_scope' => $scopeRow['issuer_scope'],
                'reseller_id' => $scopeRow['reseller_id'],
                'user_id' => $scopeRow['user_id'],
                'prefix' => $prefix !== '' ? $prefix : null,
                'count' => $count,
                'settings' => $this->snapshot($settings),
                'created_by' => auth()->id(),
            ]);

            foreach ($this->uniqueCodes($count, $prefix, $length, $issuerKey) as $code) {
                $coupon = new Coupon;
                $coupon->fill($values + $scopeRow + ['code' => $code, 'used' => 0]);
                $coupon->setAttribute('batch_id', $batch->id);
                $coupon->save();
            }

            return $batch;
        });
    }

    /**
     * The issuer's batches (never another issuer's).
     *
     * @param  array<string, mixed>  $scope
     * @return Builder<CouponBatch>
     */
    public function batches(array $scope): Builder
    {
        $row = $this->scopeColumns($scope);

        return CouponBatch::query()->where(function (Builder $w) use ($row) {
            $this->whereIssuer($w, $row);
        });
    }

    /**
     * Scoped coupon query. The scope is applied first, inside one nested group, so no filter can widen it.
     *
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $filters
     * @return Builder<Coupon>
     */
    public function query(array $scope, array $filters = []): Builder
    {
        $row = $this->scopeColumns($scope);
        $q = Coupon::query()->where(function (Builder $w) use ($row) {
            $this->whereIssuer($w, $row, 'coupons.');
        });

        if (! empty($filters['status'])) {
            $this->applyStatus($q, (string) $filters['status']);
        }
        if (! empty($filters['batch_id'])) {
            $q->where('coupons.batch_id', (int) $filters['batch_id']);
        }
        if (! empty($filters['type'])) {
            $q->where('coupons.coupon_type', (string) $filters['type']);
        }
        if (! empty($filters['applies_interval'])) {
            $q->where('coupons.applies_interval', (string) $filters['applies_interval']);
        }
        if (! empty($filters['created_from'])) {
            $q->where('coupons.created_at', '>=', Carbon::parse($filters['created_from'])->startOfDay());
        }
        if (! empty($filters['created_to'])) {
            $q->where('coupons.created_at', '<=', Carbon::parse($filters['created_to'])->endOfDay());
        }
        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            // code_key is LOWER(TRIM(code)): a case-insensitive prefix match on every driver; wildcards are literal.
            $term = Coupon::codeKey((string) $filters['search']);
            $like = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $q->whereRaw("coupons.code_key LIKE ? ESCAPE '!'", [$like]);
        }

        return $q;
    }

    /** @param  Builder<Coupon>  $q */
    private function applyStatus(Builder $q, string $status): void
    {
        $notExpired = function (Builder $w): void {
            $w->whereNull('coupons.expiry_at')->orWhere('coupons.expiry_at', '>', now());
        };
        $usedUp = function (Builder $w): void {
            $w->where('coupons.limit', '>', 0)->whereColumn('coupons.used', '>=', 'coupons.limit');
        };

        match ($status) {
            'never_used' => $q->where('coupons.used', 0),
            'partly_used' => $q->where('coupons.used', '>', 0)->whereNot($usedUp),
            'used_up' => $q->where($usedUp),
            'expired' => $q->where(fn (Builder $w) => $w->whereNotNull('coupons.expiry_at')->where('coupons.expiry_at', '<=', now())),
            'active' => $q->where($notExpired)->whereNot($usedUp),
            default => throw new InvalidArgumentException('Unknown status filter: '.$status),
        };
    }

    /**
     * Delete never-used coupons. Used ones are kept.
     *
     * @param  Builder<Coupon>  $q  a query from {@see query()}
     * @param  array<string, mixed>  $context
     * @return array{0: int, 1: int} [deleted, kept]
     */
    public function deleteUnused(Builder $q, array $context = []): array
    {
        $total = (clone $q)->count();
        $deleted = 0;
        (clone $q)->reorder()->where('coupons.used', 0)->select('coupons.id')->chunkById(500, function ($rows) use (&$deleted) {
            $deleted += (int) Coupon::query()->whereIn('id', $rows->pluck('id'))->where('used', 0)->delete();
        }, 'coupons.id', 'id');

        $this->audit('delete_unused', $context, $deleted);

        return [$deleted, max(0, $total - $deleted)];
    }

    /**
     * Expire now: coupons that are not yet expired get an expiry in the past.
     *
     * @param  Builder<Coupon>  $q
     * @param  array<string, mixed>  $context
     */
    public function expireNow(Builder $q, array $context = []): int
    {
        $target = (clone $q)->where(fn (Builder $w) => $w->whereNull('coupons.expiry_at')->orWhere('coupons.expiry_at', '>', now()));
        $n = $this->updateChunks($target, ['expiry_at' => now()->subSecond()]);
        $this->audit('expire_now', $context, $n);

        return $n;
    }

    /**
     * Extend expiry: sets the new date on coupons that already have an expiry earlier than it (coupons that never
     * expire are left alone).
     *
     * @param  Builder<Coupon>  $q
     * @param  array<string, mixed>  $context
     */
    public function extendExpiry(Builder $q, Carbon $to, array $context = []): int
    {
        if ($to->isPast()) {
            throw new InvalidArgumentException('The new expiry must be in the future.');
        }
        $target = (clone $q)->whereNotNull('coupons.expiry_at')->where('coupons.expiry_at', '<', $to);
        $n = $this->updateChunks($target, ['expiry_at' => $to]);
        $this->audit('extend_expiry', $context + ['to' => $to->toDateTimeString()], $n);

        return $n;
    }

    /**
     * Change the use limit (0 = unlimited). Coupons already used more often than the new limit are skipped.
     *
     * @param  Builder<Coupon>  $q
     * @param  array<string, mixed>  $context
     * @return array{0: int, 1: int} [updated, skipped]
     */
    public function setLimit(Builder $q, int $limit, array $context = []): array
    {
        if ($limit < 0) {
            throw new InvalidArgumentException('The limit cannot be negative.');
        }
        $total = (clone $q)->count();
        $target = $limit > 0 ? (clone $q)->where('coupons.used', '<=', $limit) : clone $q;
        $n = $this->updateChunks($target, ['limit' => $limit]);
        $this->audit('set_limit', $context + ['limit' => $limit], $n);

        return [$n, max(0, $total - $n)];
    }

    /**
     * @param  Builder<Coupon>  $q
     * @param  array<string, mixed>  $context
     */
    public function exportCsv(Builder $q, string $filename = 'coupons.csv', array $context = []): StreamedResponse
    {
        $this->audit('export_csv', $context, (clone $q)->count());

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            $this->writeCsv($out, $q);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  resource  $handle
     * @param  Builder<Coupon>  $q
     */
    public function writeCsv($handle, Builder $q): void
    {
        fputcsv($handle, self::CSV_COLUMNS, ',', '"', '');
        // chunkById pages by id: a caller's ORDER BY (e.g. by code) would skip or repeat rows, so drop it.
        (clone $q)->reorder()->with('batch:id,name')->chunkById(500, function ($rows) use ($handle) {
            foreach ($rows as $c) {
                $limit = (int) $c->limit;
                $batch = $c->getRelationValue('batch');
                fputcsv($handle, [
                    $this->csvSafe((string) $c->code),
                    $this->csvSafe((string) ($batch->name ?? ($c->batch_id ? '#'.$c->batch_id : ''))),
                    $c->coupon_type,
                    $c->percentage,
                    (int) $c->used,
                    $limit,
                    $limit > 0 ? max(0, $limit - (int) $c->used) : 'unlimited',
                    $c->expiry_at?->toDateTimeString(),
                    $this->statusOf($c),
                ], ',', '"', '');
            }
        }, 'coupons.id', 'id');
    }

    public function statusOf(Coupon $c): string
    {
        if ($c->expiry_at !== null && $c->expiry_at->lte(now())) {
            return 'expired';
        }
        if ((int) $c->limit > 0 && (int) $c->used >= (int) $c->limit) {
            return 'used_up';
        }

        return (int) $c->used > 0 ? 'partly_used' : 'never_used';
    }

    // ---------------------------------------------------------------- internals

    /**
     * @param  array<string, mixed>  $scope
     * @return array{issuer_scope: string, reseller_id: int|null, user_id: int|null, applies_to: string}
     */
    private function scopeColumns(array $scope): array
    {
        return match ($scope['scope'] ?? null) {
            'platform' => ['issuer_scope' => 'platform', 'reseller_id' => null, 'user_id' => null, 'applies_to' => 'plan_checkout'],
            'reseller' => ['issuer_scope' => 'reseller', 'reseller_id' => $this->requireId($scope, 'reseller_id'), 'user_id' => null, 'applies_to' => 'plan_checkout'],
            'store' => ['issuer_scope' => 'owner', 'reseller_id' => null, 'user_id' => $this->requireId($scope, 'user_id'), 'applies_to' => 'food_order'],
            default => throw new InvalidArgumentException('Unknown coupon scope.'),
        };
    }

    /**
     * The issuer filter — mirrors {@see Coupon::issuerKey()}: an owner row is the owner's whatever else it holds; a
     * reseller row has no owner; a platform row has neither.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $w
     * @param  array{issuer_scope: string, reseller_id: int|null, user_id: int|null, applies_to: string}  $row
     */
    private function whereIssuer(Builder $w, array $row, string $prefix = ''): void
    {
        if ($row['user_id'] !== null) {
            $w->where($prefix.'user_id', $row['user_id']);

            return;
        }
        $w->whereNull($prefix.'user_id');
        $row['reseller_id'] === null
            ? $w->whereNull($prefix.'reseller_id')
            : $w->where($prefix.'reseller_id', $row['reseller_id']);
    }

    /** @param  array<string, mixed>  $scope */
    private function requireId(array $scope, string $key): int
    {
        $id = (int) ($scope[$key] ?? 0);
        if ($id < 1) {
            throw new InvalidArgumentException("Scope requires {$key}.");
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function couponRow(array $settings): array
    {
        $row = ['coupon_type' => 'percentage'];
        foreach (self::COLUMNS as $col) {
            if (array_key_exists($col, $settings)) {
                $row[$col] = $settings[$col];
            }
        }
        if (isset($row['expiry_at'])) {
            $row['expiry_at'] = Carbon::parse($row['expiry_at']);
        }
        if (array_key_exists('first_period_only', $row)) {
            $row['first_period_only'] = (int) (bool) $row['first_period_only'];
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function snapshot(array $settings): array
    {
        $snap = array_intersect_key($settings, array_flip(self::COLUMNS));

        return json_decode((string) json_encode($snap), true) ?: [];
    }

    /**
     * Codes unique within the issuer (the P6-215 unique key: issuer_key + code_key). Read without the tenant scope:
     * uniqueness is database-wide for the issuer, and the result is only a yes/no per candidate.
     *
     * @return list<string>
     */
    private function uniqueCodes(int $count, string $prefix, int $length, string $issuerKey): array
    {
        $codes = [];
        $attempts = 0;
        while (count($codes) < $count) {
            if (++$attempts > 30) {
                throw new RuntimeException('Could not generate enough unique coupon codes; use a longer code.');
            }
            $candidates = [];
            $need = $count - count($codes);
            for ($i = 0; $i < $need; $i++) {
                $c = ($prefix !== '' ? $prefix.'-' : '').strtoupper(($this->random)($length));
                if (! isset($codes[$c])) {
                    $candidates[Coupon::codeKey($c)] = $c;
                }
            }
            foreach (array_chunk(array_map('strval', array_keys($candidates)), 500) as $chunk) {
                $taken = Coupon::query()->withoutGlobalScopes()
                    ->where('issuer_key', $issuerKey)->whereIn('code_key', $chunk)->pluck('code_key');
                foreach ($taken as $key) {
                    unset($candidates[(string) $key]);
                }
            }
            foreach ($candidates as $c) {
                $codes[$c] = true;
            }
        }

        return array_map('strval', array_keys($codes));
    }

    /**
     * @param  Builder<Coupon>  $q
     * @param  array<string, mixed>  $values
     */
    private function updateChunks(Builder $q, array $values): int
    {
        $n = 0;
        (clone $q)->reorder()->select('coupons.id')->chunkById(500, function ($rows) use ($values, &$n) {
            $n += Coupon::query()->whereIn('id', $rows->pluck('id'))->update($values + ['updated_at' => now()]);
        }, 'coupons.id', 'id');

        return $n;
    }

    private function csvSafe(string $s): string
    {
        return $s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$s : $s;
    }

    /** @param  array<string, mixed>  $context */
    private function audit(string $action, array $context, int $count): void
    {
        Log::info('coupon.bulk', [
            'actor_id' => auth()->id(),
            'action' => $action,
            'count' => $count,
        ] + $context);
    }
}

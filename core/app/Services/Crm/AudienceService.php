<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Crm;

use App\Models\Audience;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Support\SearchTerm;
use Illuminate\Database\Eloquent\Builder;
use Juwri\Core\Money\Money;

/**
 * SYSTEMS-RUN C-P2: compile an audience's rules into a Customer query. Rules:
 * {match:'all'|'any', conditions:[{field,op,value}]}. Supported fields:
 * total_spent, orders_count, last_order_days, tag, consent, birthday_month,
 * loyalty_tier. Ops: gte,lte,eq,has,is.
 */
class AudienceService
{
    /** Base query for a restaurant with the audience's rules applied. */
    public function query(int $storeId, ?array $rules): Builder
    {
        $q = Customer::where('store_id', $storeId);
        $rules = $rules ?: [];
        $conditions = $rules['conditions'] ?? [];
        $match = ($rules['match'] ?? 'all') === 'any' ? 'or' : 'and';

        if (empty($conditions)) {
            return $q;
        }

        $q->where(function (Builder $group) use ($conditions, $match, $storeId) {
            foreach ($conditions as $c) {
                $method = $match === 'or' ? 'orWhere' : 'where';
                $group->{$method}(function (Builder $inner) use ($c, $storeId) {
                    $this->applyCondition($inner, $c, $storeId);
                });
            }
        });

        return $q;
    }

    public function count(int $storeId, ?array $rules): int
    {
        return $this->query($storeId, $rules)->count();
    }

    public function forAudience(Audience $audience): Builder
    {
        return $this->query((int) $audience->store_id, $audience->rules);
    }

    private function applyCondition(Builder $q, array $c, ?int $storeId = null): void
    {
        $field = $c['field'] ?? '';
        $op = $c['op'] ?? 'gte';
        $value = $c['value'] ?? null;

        switch ($field) {
            case 'segment':
                // K6: an RFM segment resolves to the exact customer ids in that
                // segment (so an audience built from a segment matches its count).
                $ids = [0];
                if ($storeId) {
                    $rfm = new RfmService()->compute($storeId);
                    $ids = collect($rfm['scores'])
                        ->filter(fn ($s) => (string) $s['segment'] === (string) $value)
                        ->keys()->all() ?: [0];
                }
                $q->whereIn('id', $ids);
                break;
            case 'total_spent':
                // P1-05: the threshold is a major amount in the store's currency → minor units of it.
                $q->where('total_spent_minor', $this->sqlOp($op), Money::minorFromMajor((float) $value, $storeId ? store_currency($storeId) : null));
                break;
            case 'orders_count':
                $q->where('orders_count', $this->sqlOp($op), (int) $value);
                break;
            case 'last_order_days':
                // customers whose last order is older than N days (lapsed) or within (recent)
                $cutoff = now()->subDays((int) $value);
                if ($op === 'lte') {
                    $q->where('last_order_at', '>=', $cutoff);
                } else {
                    $q->where(function ($sub) use ($cutoff) {
                        $sub->where('last_order_at', '<', $cutoff)->orWhereNull('last_order_at');
                    });
                }
                break;
            case 'tag':
                $q->where(function ($sub) use ($value) {
                    SearchTerm::applyOrNone($sub, ['tags', 'system_tags'], (string) $value);
                });
                break;
            case 'consent':
                $q->where('marketing_consent', (bool) $value);
                break;
            case 'birthday_month':
                $q->whereMonth('birthday', (int) $value);
                break;
            case 'loyalty_tier':
                $ids = LoyaltyAccount::where('balance', '>=', (int) $value)->pluck('customer_id');
                $q->whereIn('id', $ids);
                break;
        }
    }

    private function sqlOp(string $op): string
    {
        return match ($op) {
            'lte' => '<=',
            'eq' => '=',
            default => '>=',
        };
    }
}

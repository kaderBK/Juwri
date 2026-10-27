<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Http\Controllers\User;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Customers\Services\CustomerMergeService;

/**
 * HPMS E9: owner UI for merging duplicate customers. The engine
 * (CustomerMergeService) already exists and REFUSES to merge away a passworded
 * (real-account) row — this only exposes it to the owner. Candidate pairs are
 * same-store customers sharing a normalized phone or email; the merge is
 * activity-logged. The passworded-row refusal is surfaced verbatim, never
 * weakened.
 */
class CustomerMergeController extends Controller
{
    public function index(Request $request): View
    {
        $storeIds = owner_store_ids();
        $storeId = (int) ($request->query('store_id')
            ?: (dashboard_current_store()?->id ?: ($storeIds[0] ?? 0)));

        if ($storeId && ! in_array($storeId, $storeIds, true)) {
            abort(403);
        }

        $pairs = $storeId ? $this->candidatePairs($storeId) : [];

        return view('owner.customers.merge', [
            'pairs' => $pairs,
            'storeId' => $storeId,
        ]);
    }

    /**
     * Same-store customers that share a normalized phone or email, presented as
     * {primary, duplicate, reason, preview}. The primary is the best keeper
     * (passworded real account first, then most orders, then oldest).
     *
     * @return array<int,array<string,mixed>>
     */
    private function candidatePairs(int $storeId): array
    {
        $customers = Customer::where('store_id', $storeId)
            ->orderBy('id')->get();

        $groups = []; // normalized-key => list of customers
        foreach ($customers as $c) {
            foreach ($this->keysFor($c) as $key) {
                $groups[$key][] = $c;
            }
        }

        $pairs = [];
        $seen = [];
        foreach ($groups as $key => $members) {
            if (count($members) < 2) {
                continue;
            }
            // Keeper priority: passworded first, then higher orders_count, then lower id.
            usort($members, function ($a, $b) {
                $ka = [! empty($a->password) ? 1 : 0, (int) $a->orders_count, -$a->id];
                $kb = [! empty($b->password) ? 1 : 0, (int) $b->orders_count, -$b->id];

                return $kb <=> $ka; // descending — the keeper sorts first
            });
            $primary = $members[0];
            foreach (array_slice($members, 1) as $dup) {
                $pairKey = $primary->id.'-'.$dup->id;
                if (isset($seen[$pairKey])) {
                    continue;
                }
                $seen[$pairKey] = true;
                $pairs[] = [
                    'primary' => $this->preview($primary, $storeId),
                    'duplicate' => $this->preview($dup, $storeId),
                    'reason' => str_starts_with((string) $key, 'p:') ? ___('Same phone') : ___('Same email'),
                ];
            }
        }

        return $pairs;
    }

    /** @return array<int,string> normalized match keys for a customer */
    private function keysFor(Customer $c): array
    {
        $keys = [];
        $phone = preg_replace('/\D+/', '', (string) $c->phone);
        if ($phone !== '' && strlen($phone) >= 6) {
            $keys[] = 'p:'.$phone;
        }
        $email = strtolower(trim((string) $c->email));
        if ($email !== '') {
            $keys[] = 'e:'.$email;
        }

        return $keys;
    }

    /** @return array<string,mixed> */
    private function preview(Customer $c, int $storeId): array
    {
        $points = (int) (LoyaltyAccount::where('customer_id', $c->id)
            ->where('store_id', $storeId)->value('balance') ?? 0);

        return [
            'id' => $c->id,
            'name' => $c->name,
            'email' => $c->email,
            'phone' => $c->phone,
            'orders' => (int) $c->orders_count,
            'points' => $points,
            'last_seen' => $c->last_order_at,
            'has_password' => ! empty($c->password),
        ];
    }

    /** §5 fields: primary_id, duplicate_id, confirm ('1'). */
    public function merge(Request $request): RedirectResponse
    {
        $storeIds = owner_store_ids();

        $data = $request->validate([
            'primary_id' => ['required', 'integer'],
            'duplicate_id' => ['required', 'integer', 'different:primary_id'],
            'confirm' => ['required', 'in:1'],
        ]);

        $primary = Customer::whereIn('store_id', $storeIds)->findOrFail($data['primary_id']);
        $duplicate = Customer::whereIn('store_id', $storeIds)->findOrFail($data['duplicate_id']);

        try {
            // Argument order: the shadow/duplicate is folded INTO the canonical/primary.
            $moved = CustomerMergeService::merge($duplicate, $primary);
        } catch (\InvalidArgumentException $e) {
            // Surface the engine's guard (passworded row / cross-store) verbatim.
            return back()->with('error', $e->getMessage());
        }

        if (function_exists('log_activity')) {
            log_activity('customer.merged', $primary, [
                'primary_id' => $primary->id,
                'duplicate_id' => $duplicate->id,
                'moved' => $moved,
            ], (int) $primary->store_id);
        }

        return back()->with('success', ___('Customers merged.'));
    }
}

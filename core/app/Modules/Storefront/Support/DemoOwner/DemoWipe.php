<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Support\DemoOwner;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Tenancy\StoreDataEraser;
use Juwri\Core\Services\Tenancy\StoreDataScope;
use Throwable;

/**
 * J62-1: the `--fresh` wipe — the demo-owned rows and nothing else.
 *
 * A demo store is a store whose slug the plan fixes ({@see DemoOwnerPlan::STORES}) or that carries
 * the demo tag ({@see DemoOwnerPlan::TAG_OPTION}). Its rows go through the store-data eraser —
 * children first, the store row last — so the orders, order items, customers, tables and menu
 * rows under it go with it. (The owner's self-service deletion, AccountDeletionService, is the
 * wrong tool here: it keeps the sales records and anonymises them; a demo wipe deletes.)
 *
 * The demo logins ({@see DemoOwnerPlan::userEmails()}) go next — but only when they own no store
 * any more: a user row cascades to its stores, so a demo login that still owns a store of its own
 * keeps it. A store the owner made himself is never touched.
 */
final class DemoWipe
{
    public function __construct(
        private readonly DemoStores $demoStores,
        private readonly StoreDataEraser $eraser,
    ) {}

    /**
     * Remove the demo stores and the demo logins.
     *
     * @return array{stores: list<int>, users: list<int>, kept: list<string>}
     */
    public function run(): array
    {
        $wiped = [];
        $kept = [];

        foreach ($this->demoStores->existing() as $store) {
            if ($this->erase($store)) {
                $wiped[] = (int) $store->id;
            } else {
                $kept[] = 'store '.$store->slug.' (could not be erased)';
            }
        }

        return ['stores' => $wiped, 'users' => $this->wipeUsers($kept), 'kept' => $kept];
    }

    /** Erase one store and everything under it. False when the eraser refused (nothing deleted). */
    private function erase(Store $store): bool
    {
        try {
            $this->eraser->delete(DB::connection(), StoreDataScope::store((int) $store->id));

            return true;
        } catch (Throwable $e) {
            Log::warning('[demo-seed] could not erase the demo store '.$store->slug, ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * The demo logins. A login that still owns a store keeps it (and stays): the user row would
     * cascade into that store.
     *
     * @param  list<string>  $kept
     * @return list<int>
     */
    private function wipeUsers(array &$kept): array
    {
        $deleted = [];

        foreach (DemoOwnerPlan::userEmails() as $email) {
            $user = User::query()->where('email', $email)->first();
            if (! $user instanceof User) {
                continue;
            }
            if ($this->ownsStores($user)) {
                $kept[] = 'login '.$email.' (still owns a store)';

                continue;
            }
            $id = (int) $user->id;
            $user->delete();
            $deleted[] = $id;
        }

        return $deleted;
    }

    /** Does the user still own a store? */
    private function ownsStores(User $user): bool
    {
        return DB::table('stores')->where('user_id', $user->id)->exists();
    }
}

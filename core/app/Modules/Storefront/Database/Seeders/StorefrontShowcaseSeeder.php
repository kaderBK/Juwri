<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Database\Seeders;

use App\Models\GiftCard;
use App\Models\Reservation;
use App\Models\StoreOption;
use App\Services\GiftCardService;
use Illuminate\Database\Seeder;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Combo;
use Juwri\Modules\Menu\Models\ComboSlot;
use Juwri\Modules\Menu\Models\ComboSlotOption;
use Juwri\Modules\Menu\Models\Menu;

/**
 * Provisions the diner-facing storefront features that were entitled but
 * un-provisioned on the 17 kept-theme demo stores (showcase account #10008), so
 * each is demoable: combo meals, gift cards, curbside pickup, waitlist and
 * sample reservations.
 *
 * NOT covered: meal plans — `MealPlan` is an API/mobile-app subscription
 * (Juwri\Modules\Ordering\Http\Controllers\Api\V1\MealPlanController) with no web storefront or
 * account surface, so there is nothing to show on the web demos.
 *
 * ADDITIVE + IDEMPOTENT: keys on natural keys via updateOrCreate/firstOrCreate;
 * combos only build their slots once; a demo gift card is issued only if the
 * store has none. Safe to run repeatedly.
 *
 * Run:  php artisan db:seed --class=StorefrontShowcaseSeeder
 */
class StorefrontShowcaseSeeder extends Seeder
{
    private const SLUGS = [
        'crave-demo', 'matcha-demo', 'glow-demo', 'aurora-demo', 'vivid-demo',
        'feast-demo', 'velvet-demo', 'dough-demo', 'plate-demo', 'lime-demo',
        'picnic-demo', 'orbit-demo', 'velora-demo', 'crumb-demo', 'soiree-demo',
        'scoop-demo', 'ember-demo',
    ];

    public function run(): void
    {
        foreach (self::SLUGS as $slug) {
            $store = Store::where('slug', $slug)->first();
            if (! $store) {
                $this->command?->warn("StorefrontShowcaseSeeder: {$slug} not found — skipped.");

                continue;
            }
            $rid = $store->id;

            // 1. Feature flags: curbside pickup, waitlist, gift-card purchase.
            foreach ([
                'curbside_enabled' => '1',
                'enable_waitlist' => '1',
                'gift_cards_enabled' => '1',
                'gift_card_presets' => '25,50,100',
            ] as $name => $value) {
                StoreOption::updateStoreOption($rid, $name, $value);
            }

            $this->seedCombo($rid);
            $this->seedGiftCard($rid);
            $this->seedReservations($rid);

            $this->command?->info("StorefrontShowcaseSeeder: {$slug} (post {$rid}) — curbside+waitlist+gift-cards on, combo + gift card + 2 reservations.");
        }
    }

    /** A "Family Meal" bundle: pick a main + pick a drink/side, from the store's own menu. */
    private function seedCombo(int $rid): void
    {
        $combo = Combo::firstOrCreate(
            ['store_id' => $rid, 'name' => 'Family Meal'],
            [
                'description' => 'Pick a main and a drink — one bundle price.',
                'price' => 49,
                'active' => true,
                'position' => 0,
            ]
        );

        // Give the meal-deal a hero photo so the storefront rail shows an image,
        // not the icon placeholder. Reuse the store's own first menu photo — combo
        // images resolve through storage/menu/ exactly like menu images. Runs
        // before the slots early-return and only when empty, so it also backfills
        // an image-less combo seeded before this step existed.
        if (empty($combo->image)) {
            $img = Menu::where('store_id', $rid)->where('active', '1')
                ->whereNotNull('image')->where('image', '!=', '')
                ->orderBy('position')->orderBy('id')->value('image');
            if ($img) {
                $combo->image = $img;
                $combo->save();
            }
        }

        // Build slots only once (idempotent).
        if ($combo->slots()->exists()) {
            return;
        }

        $menuIds = Menu::where('store_id', $rid)->where('active', '1')
            ->orderBy('position')->orderBy('id')->limit(4)->pluck('id')->all();
        if (count($menuIds) < 2) {
            return; // not enough menu to build a meaningful combo
        }
        $mains = array_slice($menuIds, 0, 2);
        $drinks = array_slice($menuIds, 2, 2) ?: $mains; // fall back if the menu is tiny

        foreach ([
            ['title' => 'Choose a main',  'opts' => $mains],
            ['title' => 'Choose a drink', 'opts' => $drinks],
        ] as $pos => $slotDef) {
            $slot = ComboSlot::create([
                'combo_id' => $combo->id,
                'store_id' => $rid,
                'title' => $slotDef['title'],
                'min_select' => 1,
                'max_select' => 1,
                'position' => $pos,
            ]);
            foreach ($slotDef['opts'] as $i => $menuId) {
                ComboSlotOption::create([
                    'combo_slot_id' => $slot->id,
                    'combo_id' => $combo->id,
                    'store_id' => $rid,
                    'menu_id' => $menuId,
                    'upcharge' => 0,
                    'active' => true,
                    'position' => $i,
                ]);
            }
        }
    }

    /** One funded, redeemable demo gift card (only if the store has none). */
    private function seedGiftCard(int $rid): void
    {
        if (GiftCard::where('store_id', $rid)->exists()) {
            return;
        }
        GiftCardService::issueManual($rid, 50, ['note' => 'demo-showcase']);
    }

    /** Two sample bookings so the dashboard reservations list is populated. */
    private function seedReservations(int $rid): void
    {
        foreach ([
            ['token' => 'sf-showcase-res1-'.$rid, 'name' => 'Layla Hassan', 'party' => 2, 'status' => 'confirmed', 'in' => 2],
            ['token' => 'sf-showcase-res2-'.$rid, 'name' => 'Omar Khaled',  'party' => 4, 'status' => 'pending',   'in' => 5],
        ] as $r) {
            Reservation::firstOrCreate(
                ['token' => $r['token']],
                [
                    'store_id' => $rid,
                    'name' => $r['name'],
                    'phone' => '966500000500',
                    'party_size' => $r['party'],
                    'reserved_at' => now()->addDays($r['in'])->setTime(19, 30),
                    'status' => $r['status'],
                    'notes' => 'Demo reservation.',
                ]
            );
        }
    }
}

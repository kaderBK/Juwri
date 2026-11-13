<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use App\Models\StoreTable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Modules\Menu\Models\Menu;

/**
 * D4-7 follow-up: order history for the docs-screenshot pipeline.
 *
 * The docs screenshots capture the seeded ThemePackDemoSeeder store's dashboard,
 * but that store ships with a single order — so every KPI card reads $0.00 / 0
 * and the screenshots look dead. This tops the store up with two weeks of
 * delivered, PAID orders (Order::scopeRevenueCounted -> is_paid=1 counts toward
 * revenueSum's grand_total sum), so Sales / Orders / AOV and the charts show a
 * living store.
 *
 * Idempotent: every order keys on a stable track_token (docsshot-NN) and is
 * refreshed in place; re-running never duplicates. Depends on
 * ThemePackDemoSeeder having run (no-ops politely otherwise). Demo data only —
 * the store is the seeder demo store, never a live tenant.
 */
class DocsScreenshotSeeder extends Seeder
{
    private const STORE_SLUG = 'themepack-demo';

    public function run(): void
    {
        // The docs journey shoots every guide per locale (en + ar), but a fresh
        // database's languages table ships only en/fr (create_languages_table
        // migration) while include_language_code defaults to '1' (URL-prefix
        // mode) — so the /{locale} registry is DB-backed and /ar/... 404s,
        // failing the run's gated:false /ar/dashboard shot. Make ar real.
        DB::table('languages')->updateOrInsert(
            ['code' => 'ar'],
            ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1, 'position' => 2]
        );

        // Directory module master switch. FeatureRegistry::isEnabled('directory')
        // reads config('settings.admin_allow_directory'), which ships DEFAULT OFF,
        // so DirGate::enabled() is false and the owner directory tab 404s — the
        // docs 'directory' guide hit that wall and skipped. Turn the module on
        // (a GLOBAL option, boot-loaded into config('settings.*') by
        // SettingsServiceProvider); dir_enabled defaults true when absent.
        PlatformSettings::set('admin_allow_directory', '1');

        $store = Store::where('slug', self::STORE_SLUG)->first();
        if (! $store) {
            $this->command?->warn('DocsScreenshotSeeder: run ThemePackDemoSeeder first (themepack-demo missing) — skipping.');

            return;
        }

        $items = Menu::where('store_id', $store->id)->orderBy('id')->get();
        if ($items->isEmpty()) {
            $this->command?->warn('DocsScreenshotSeeder: themepack-demo has no menu items — skipping.');

            return;
        }

        // Two weeks of history: [days-ago, hour, type, qty] — deterministic so the
        // dashboard renders the same shape every run.
        $plan = [
            [13, 12, 'delivery', 2], [12, 19, 'takeaway', 1], [11, 13, 'delivery', 3],
            [9, 20, 'on-table', 2], [8, 12, 'takeaway', 2], [7, 19, 'delivery', 1],
            [6, 13, 'delivery', 2], [4, 20, 'takeaway', 3], [3, 12, 'on-table', 1],
            [2, 19, 'delivery', 2], [1, 13, 'takeaway', 2], [0, 11, 'delivery', 1],
        ];

        foreach ($plan as $i => [$daysAgo, $hour, $type, $qty]) {
            $item = $items[$i % $items->count()];
            $when = now()->subDays($daysAgo)->setTime($hour, 15);
            $subtotal = round((float) $item->price * $qty, 2);

            $order = Order::updateOrCreate(
                ['store_id' => $store->id, 'track_token' => sprintf('docsshot-%02d', $i + 1)],
                [
                    'customer_name' => 'Demo Diner',
                    'phone_number' => '9665000000'.str_pad((string) ($i + 2), 2, '0', STR_PAD_LEFT),
                    'type' => $type,
                    'status' => 'delivered',
                    'delivery_status' => 'delivered',
                    'is_paid' => 1,
                    'subtotal' => $subtotal,
                    'grand_total' => $subtotal,
                    'delivered_at' => $when->copy()->addMinutes(40),
                    'completed_at' => $when->copy()->addMinutes(40),
                    'created_at' => $when,
                    'source' => 'seeder',
                ]
            );

            OrderItem::updateOrCreate(
                ['order_id' => $order->id, 'item_id' => $item->id],
                ['quantity' => $qty, 'status' => 'served', 'unit_price' => (float) $item->price]
            );
        }

        $this->command?->info('DocsScreenshotSeeder: 12 paid demo orders refreshed on themepack-demo.');

        // Grant the plan-gated features whose docs screens the bare demo plan
        // walls off, so the pipeline can shoot the real feature UI instead of an
        // upsell page. Each maps to a users/ guide:
        //   support_desk         -> /customer-feedback      (reviews-feedback)
        //   marketing_automation -> /automations            (marketing-automation)
        //   payroll_suite        -> /payroll-runs/…         (payroll)
        //   digital_signage      -> /signage                (digital-signage)
        //   team_comms           -> /team                   (team-chat)
        //   fiscalization        -> /fiscal                 (fiscalization)
        //   kiosk                -> /kiosk-settings          (kiosk)
        //   hrm_suite            -> /leave                   (hr-employees)
        //   staff_rota           -> /rota                    (staff-scheduling)
        // An EntitlementOverride is the admin/promo grant lane Entitlements::has()
        // already reads (most-generous-wins; the kill-switch still vetoes). Demo
        // data only — the store is the seeder demo store, never a live tenant.
        $grantKeys = [
            'support_desk', 'marketing_automation', 'payroll_suite', 'digital_signage',
            'team_comms', 'fiscalization', 'kiosk', 'hrm_suite', 'staff_rota', 'hotel',
            // directory_pro/_featured so the owner directory tab renders its full
            // capabilities instead of an inline "Upgrade to unlock" banner — which
            // the docs journey's own wall-detector (GATED_TEXT) would read as an
            // upsell wall and skip the shot. The module master switch is enabled
            // separately below (a GLOBAL option, not a per-store entitlement).
            'directory_pro', 'directory_featured',
        ];
        foreach ($grantKeys as $key) {
            EntitlementOverride::updateOrCreate(
                ['user_id' => $store->user_id, 'feature_key' => $key],
                ['value' => '1', 'expires_at' => null, 'reason' => 'docs-screenshot demo store', 'source_type' => 'demo'],
            );
        }

        $this->command?->info('DocsScreenshotSeeder: granted '.count($grantKeys).' demo features on themepack-demo.');

        // Hotel is a two-gate feature: the `hotel` entitlement above AND the
        // owner's per-store master toggle. Flip the toggle and seed a small
        // rooms/stays fixture so the hotel-folios guide shoots a working stays &
        // folios board (active guests + available rooms) instead of an empty gate.
        StoreOption::updateStoreOption($store->id, 'hotel_mode', '1');
        $branchId = Branch::where('store_id', $store->id)->orderBy('id')->value('id');

        $rooms = [['101', 'Ground floor'], ['102', 'Ground floor'], ['201', 'First floor'], ['202', 'First floor']];
        $roomId = [];
        foreach ($rooms as [$no, $floor]) {
            $roomId[$no] = StoreTable::updateOrCreate(
                ['store_id' => $store->id, 'table_no' => $no, 'area_type' => 'room'],
                ['branch_id' => $branchId, 'label' => 'Room '.$no, 'floor_label' => $floor, 'seats' => 2, 'status' => 'available'],
            )->id;
        }

        // Two checked-in guests (idempotent via a stable token); the other two
        // rooms stay available for a new check-in.
        $guests = [['101', 'Sarah Al-Amri', '966500000101', 2], ['201', 'James Carter', '447700900201', 1]];
        foreach ($guests as [$no, $name, $phone, $daysAgo]) {
            app(RoomCharge::class)->upsertStay(
                ['store_id' => $store->id, 'token' => 'docsstay-'.$no],
                [
                    'branch_id' => $branchId,
                    'table_id' => $roomId[$no],
                    'guest_name' => $name,
                    'guest_phone' => $phone,
                    'status' => 'checked_in',
                    'checked_in_at' => now()->subDays($daysAgo),
                ],
            );
        }

        $this->command?->info('DocsScreenshotSeeder: hotel_mode on + '.count($rooms).' rooms / '.count($guests).' stays on themepack-demo.');
    }
}

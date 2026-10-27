<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat\Import;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacyProdShapeFixture as Shape;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacySourceFixture as Source;
use Tests\TestCase;

/**
 * J52b M4b step 4: the must-move later tables (SYNTHETIC rows, J19): old slugs, consent and opt-outs, pending account
 * deletions, store content, coupons of every issuer and their redemptions (never across tenants), customer
 * addresses and follows, reviews with their media rows, branch overrides, combos and price schedules.
 */
class LegacyContentImportTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'legacy_content_source';

    private Source $src;

    private Shape $shape;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.'.self::SOURCE => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'legacy_compat.enabled' => true,
            'legacy_compat.import.enabled' => true,
            'legacy_compat.cutover.owner_signoff' => true,
            'legacy_compat.import.target_host' => 'localhost',
            'legacy_compat.import.source_timezone' => 'UTC',
            'legacy_compat.import.source_app_key' => '',
        ]);
        DB::purge(self::SOURCE);
        $this->src = (new Source(DB::connection(self::SOURCE)))->createSchema();
        $this->shape = (new Shape(DB::connection(self::SOURCE)))->laterColumns()->laterContent();
        $s = $this->src;
        $s->platformOption('currency_code', 'USD');
        $s->user(1);
        $s->user(2);
        $s->store(10, 1);
        $s->storeOption(10, 'currency_code', 'SAR');
        $s->store(20, 2);
        $s->storeOption(20, 'currency_code', 'KWD');
        $s->branch(500, 10);
        $s->branch(600, 20);
        $s->category(100, 10);
        $s->category(200, 20);
        $s->item(1000, 10, 100, 9.0);
        $s->item(2000, 20, 200, 1.5);
        $s->customer(300, 10);
        $s->customer(400, 20);
    }

    protected function tearDown(): void
    {
        DB::purge(self::SOURCE);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function import(): array
    {
        $exit = Artisan::call('legacy:import', ['--source' => self::SOURCE]);
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $report = json_decode($output, true);
        $this->assertIsArray($report, $output);

        return $report;
    }

    /** @param list<string> $tables */
    private function assertTenancy(array $tables): void
    {
        foreach ($tables as $table) {
            foreach (DB::table($table)->whereNotNull('store_id')->get(['account_id', 'store_id']) as $row) {
                $this->assertSame((int) DB::table('stores')->where('id', $row->store_id)->value('account_id'), (int) $row->account_id, $table);
            }
            $this->assertSame(DB::table($table)->count(), DB::table('legacy_import_rows')->where('target_table', $table)->count(), $table);
        }
    }

    public function test_old_slugs_consent_deletions_and_store_content_move_with_their_store(): void
    {
        $sh = $this->shape;
        $sh->row('slug_redirects', ['id' => 1, 'old_slug' => 'Old-Name', 'post_id' => 10]);
        $sh->row('sms_optouts', ['id' => 1, 'phone' => '+966500000001', 'restaurant_id' => null, 'source' => 'reply']);
        $sh->row('sms_optouts', ['id' => 2, 'phone' => '+966500000002', 'restaurant_id' => 10, 'source' => 'reply']);
        $sh->row('sms_optouts', ['id' => 3, 'phone' => '+966500000003', 'restaurant_id' => 99, 'source' => 'reply']);
        $sh->row('whatsapp_optouts', ['id' => 1, 'phone' => '+966500000004', 'restaurant_id' => 20, 'source' => 'reply']);
        $sh->row('whatsapp_consent', ['id' => 1, 'restaurant_id' => 10, 'phone' => '+966500000005', 'opted_in_at' => '2025-01-01 10:00:00']);
        $sh->row('account_deletions', ['id' => 1, 'user_id' => 2, 'requested_at' => '2025-06-01 00:00:00', 'purge_after' => '2025-07-01 00:00:00']);
        $sh->row('account_deletions', ['id' => 2, 'user_id' => 77, 'requested_at' => '2025-06-01 00:00:00', 'purge_after' => '2025-07-01 00:00:00']);
        $sh->row('stories', ['id' => 1, 'restaurant_id' => 10, 'media_url' => 'stories/a.jpg', 'caption' => 'Synthetic']);
        $sh->row('theme_settings', ['id' => 1, 'post_id' => 10, 'template' => 'classic', 'settings' => '{"accent":"#123456"}', 'theme_version_id' => 9]);
        $sh->row('store_custom_blocks', ['id' => 1, 'post_id' => 10, 'name' => 'Banner', 'html' => '<b>x</b>', 'updated_by' => 2]);
        $sh->row('store_custom_blocks', ['id' => 2, 'post_id' => 10, 'name' => 'Footer', 'updated_by' => 1]);
        $sh->row('bio_blocks', ['id' => 1, 'post_id' => 10, 'branch_id' => 600, 'type' => 'link', 'url' => 'https://example.test']);
        $sh->row('qr_designs', ['id' => 1, 'restaurant_id' => 10, 'name' => 'Brand', 'fg_color' => '#111111']);
        $sh->row('image_menus', ['id' => 1, 'restaurant_id' => 10, 'name' => 'Page 1', 'image' => 'p1.jpg']);

        $report = $this->import();

        $this->assertSame([10, 'Old-Name'], [(int) DB::table('slug_redirects')->value('store_id'), DB::table('slug_redirects')->value('old_slug')]);
        $optouts = DB::table('sms_optouts')->orderBy('id')->get()->keyBy('id');
        $this->assertNull($optouts[1]->store_id); // platform-wide opt-out
        $this->assertSame(10, (int) $optouts[2]->store_id);
        $this->assertFalse($optouts->has(3));
        $this->assertSame(20, (int) DB::table('whatsapp_optouts')->where('id', 1)->value('store_id'));
        $this->assertStringStartsWith('2025-01-01 10:00:00', (string) DB::table('whatsapp_consents')->where('id', 1)->value('opted_in_at'));
        $this->assertSame(2, (int) DB::table('account_deletions')->where('id', 1)->value('user_id'));
        $this->assertFalse(DB::table('account_deletions')->where('id', 2)->exists());
        $this->assertSame(0, (int) DB::table('users')->where('id', 2)->value('status')); // inside the purge window: off
        $this->assertSame(1, (int) DB::table('users')->where('id', 1)->value('status'));
        $this->assertSame(1, $report['conflicts']['account_deletions: user_id does not name a users row of this store']['count']);
        $this->assertSame('Synthetic', DB::table('stories')->where('id', 1)->value('caption'));
        $this->assertNull(DB::table('theme_settings')->where('id', 1)->value('theme_version_id'));
        $this->assertSame(['accent' => '#123456'], json_decode((string) DB::table('theme_settings')->where('id', 1)->value('settings'), true));
        $this->assertNull(DB::table('store_custom_blocks')->where('id', 1)->value('updated_by')); // not the store owner
        $this->assertSame(1, (int) DB::table('store_custom_blocks')->where('id', 2)->value('updated_by'));
        $this->assertNull(DB::table('bio_blocks')->where('id', 1)->value('branch_id')); // branch of another store
        $this->assertSame('#111111', DB::table('qr_designs')->where('id', 1)->value('fg_color'));
        $this->assertSame('p1.jpg', DB::table('image_menus')->where('id', 1)->value('image'));
        $this->assertTenancy(['slug_redirects', 'sms_optouts', 'whatsapp_optouts', 'whatsapp_consents', 'stories', 'theme_settings', 'store_custom_blocks', 'bio_blocks', 'qr_designs', 'image_menus']);
    }

    public function test_coupons_keep_their_issuer_and_are_never_used_across_tenants(): void
    {
        $sh = $this->shape;
        $sh->row('coupons', ['id' => 1, 'code' => 'PLATFORM5', 'issuer_scope' => 'platform', 'coupon_type' => 'fixed', 'percentage' => 5, 'min_order_amount' => 20]);
        $sh->row('coupons', ['id' => 2, 'code' => 'OWNER10', 'user_id' => 1, 'coupon_type' => 'percentage', 'percentage' => 10, 'branch_id' => 500, 'used' => 1]);
        $sh->row('coupons', ['id' => 3, 'code' => 'BADBRANCH', 'user_id' => 1, 'branch_id' => 600]);
        $sh->row('coupons', ['id' => 4, 'code' => 'RESELLER', 'issuer_scope' => 'reseller', 'reseller_id' => 8]);
        $sh->row('coupons', ['id' => 5, 'code' => 'A-CODE-LONGER-THAN-TWENTY', 'user_id' => 2]);
        $sh->row('coupons', ['id' => 6, 'code' => 'OWNER2FIX', 'user_id' => 2, 'coupon_type' => 'fixed', 'percentage' => 2]);
        $sh->row('coupons', ['id' => 7, 'code' => 'BOGO', 'user_id' => 1, 'coupon_type' => 'bogo', 'buy_scope' => 'item', 'buy_id' => 1000, 'get_id' => 2000, 'buy_qty' => 1, 'get_qty' => 1, 'get_discount_percent' => 100]);
        $this->src->order(5000, 10, ['coupon_id' => 2]);
        $this->src->order(6000, 20, ['coupon_id' => 2]); // owner 1's coupon on owner 2's store
        $this->src->order(6001, 20, ['coupon_id' => 1]);
        $sh->row('coupon_redemptions', ['id' => 1, 'coupon_id' => 2, 'customer_id' => 300, 'seq' => 1, 'order_id' => 5000]);
        $sh->row('coupon_redemptions', ['id' => 2, 'coupon_id' => 2, 'customer_id' => 400, 'seq' => 1]);

        $report = $this->import();

        $coupons = DB::table('coupons')->orderBy('id')->get()->keyBy('id');
        $this->assertSame([1, 2, 6, 7], $coupons->keys()->map(fn ($v) => (int) $v)->all());
        $this->assertSame([1000, null], [(int) $coupons[7]->buy_id, $coupons[7]->get_id]); // get item of another owner dropped
        $this->assertSame(['platform', null, null, 500, 'USD'], [$coupons[1]->issuer_scope, $coupons[1]->account_id, $coupons[1]->percentage_rate, (int) $coupons[1]->amount_minor, $coupons[1]->currency_code]);
        $this->assertSame(2000, (int) $coupons[1]->min_order_amount_minor);
        $this->assertSame(['owner', 1, 1, 500, 'SAR'], [$coupons[2]->issuer_scope, (int) $coupons[2]->user_id, (int) $coupons[2]->account_id, (int) $coupons[2]->branch_id, $coupons[2]->currency_code]);
        $this->assertEqualsWithDelta(10.0, (float) $coupons[2]->percentage_rate, 0.0001);
        $this->assertNull($coupons[2]->amount_minor);
        $this->assertSame(['u:1', 'owner10'], [$coupons[2]->issuer_key, $coupons[2]->code_key]);
        $this->assertSame(['KWD', 2000], [$coupons[6]->currency_code, (int) $coupons[6]->amount_minor]);
        $this->assertSame(1, $report['conflicts']["coupons: coupon branch is not a branch of the owner's stores"]['count']);
        $this->assertSame(1, $report['conflicts']['coupons: reseller coupon: reseller not on this install (import it with the reseller)']['count']);
        $this->assertSame(1, $report['conflicts']['coupons: coupon code empty or longer than 20 characters']['count']);

        $this->assertSame(2, (int) DB::table('orders')->where('id', 5000)->value('coupon_id'));
        $this->assertNull(DB::table('orders')->where('id', 6000)->value('coupon_id'));
        $this->assertSame(1, (int) DB::table('orders')->where('id', 6001)->value('coupon_id'));
        $this->assertSame([1], DB::table('coupon_redemptions')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([10, 1, 5000], [(int) DB::table('coupon_redemptions')->value('store_id'), (int) DB::table('coupon_redemptions')->value('account_id'), (int) DB::table('coupon_redemptions')->value('order_id')]);
        $this->assertSame(1, $report['conflicts']['coupon_redemptions: coupon of another owner or reseller']['count']);
    }

    public function test_customer_addresses_follows_reviews_and_media_rows_stay_in_their_store(): void
    {
        $sh = $this->shape;
        $sh->row('customer_addresses', ['id' => 1, 'customer_id' => 300, 'line' => '1 Synthetic Street', 'is_default' => true]);
        $sh->row('restaurant_follows', ['id' => 1, 'customer_id' => 300, 'restaurant_id' => 10]);
        $sh->row('restaurant_follows', ['id' => 2, 'customer_id' => 400, 'restaurant_id' => 10]); // another store's customer
        $sh->row('media_assets', ['id' => 900, 'restaurant_id' => 10, 'filename' => 'r1.jpg']);
        $sh->row('media_assets', ['id' => 901, 'restaurant_id' => 20, 'filename' => 'r2.jpg']);
        $this->src->order(5000, 10, ['customer_id' => 300]);
        $sh->row('menu_reviews', ['id' => 1, 'menu_id' => 1000, 'restaurant_id' => 10, 'order_id' => 5000, 'customer_id' => 300, 'stars' => 4, 'status' => 'approved']);
        $sh->row('menu_reviews', ['id' => 2, 'menu_id' => 2000, 'restaurant_id' => 10, 'stars' => 1]); // another store's item
        $sh->row('menu_review_media', ['id' => 1, 'review_id' => 1, 'media_asset_id' => 900]);
        $sh->row('store_reviews', ['id' => 1, 'restaurant_id' => 10, 'customer_id' => 400, 'stars' => 5, 'criteria' => '{"food":5}']);
        $sh->row('store_review_media', ['id' => 1, 'review_id' => 1, 'media_asset_id' => 901]); // another store's media

        $report = $this->import();

        $this->assertSame([10, 1], [(int) DB::table('customer_addresses')->value('store_id'), (int) DB::table('customer_addresses')->value('account_id')]);
        $this->assertSame([1], DB::table('store_follows')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([1], DB::table('menu_reviews')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(900, (int) DB::table('menu_review_media')->value('media_asset_id'));
        $this->assertNull(DB::table('store_reviews')->where('id', 1)->value('customer_id'));
        $this->assertSame(0, DB::table('store_review_media')->count());
        $this->assertSame(1, $report['conflicts']['store_review_media: media_asset_id does not name a media_assets row of this store']['count']);
        $this->assertTenancy(['customer_addresses', 'store_follows', 'media_assets', 'menu_reviews', 'menu_review_media', 'store_reviews']);
    }

    public function test_branch_overrides_combos_and_price_schedules_are_kept_and_order_lines_name_them(): void
    {
        $sh = $this->shape;
        $sh->row('branch_menu', ['id' => 1, 'branch_id' => 500, 'menu_id' => 1000, 'available' => false, 'price' => 8.5]);
        $sh->row('branch_menu', ['id' => 2, 'branch_id' => 500, 'menu_id' => 2000, 'price' => 1]); // another store's item
        $sh->row('combos', ['id' => 30, 'restaurant_id' => 10, 'branch_id' => 500, 'name' => 'Meal', 'price' => 12.25]);
        $sh->row('combo_slots', ['id' => 31, 'combo_id' => 30, 'restaurant_id' => 10, 'title' => 'Main']);
        $sh->row('combo_slot_options', ['id' => 32, 'combo_slot_id' => 31, 'combo_id' => 30, 'restaurant_id' => 10, 'menu_id' => 1000, 'variant_id' => 77, 'upcharge' => 1.5]);
        $sh->row('price_schedules', ['id' => 40, 'restaurant_id' => 10, 'name' => 'Happy hour', 'adjustment_type' => 'percent', 'adjustment_value' => 12.5]);
        $sh->row('price_schedules', ['id' => 41, 'restaurant_id' => 10, 'name' => 'Lunch', 'adjustment_type' => 'amount', 'adjustment_value' => 2.5]);
        $sh->row('price_schedule_targets', ['id' => 1, 'schedule_id' => 40, 'target_type' => 'item', 'target_id' => 1000]);
        $sh->row('price_schedule_targets', ['id' => 2, 'schedule_id' => 40, 'target_type' => 'category', 'target_id' => 200]); // another store's
        $this->src->order(5000, 10);
        $this->src->orderItem(9000, 5000, 1000, 1, ['combo_id' => 30, 'unit_price' => 12.25]);

        $report = $this->import();

        $override = DB::table('branch_menu')->where('id', 1)->first();
        $this->assertSame([850, 'SAR', false], [(int) $override->price_minor, $override->currency_code, (bool) $override->available]);
        $this->assertFalse(DB::table('branch_menu')->where('id', 2)->exists());
        $this->assertSame(1225, (int) DB::table('combos')->where('id', 30)->value('price_minor'));
        $this->assertSame(10, (int) DB::table('combo_slots')->where('id', 31)->value('store_id'));
        $option = DB::table('combo_slot_options')->where('id', 32)->first();
        $this->assertSame([150, 1000, null], [(int) $option->upcharge_minor, (int) $option->menu_id, $option->variant_id]);
        $this->assertEqualsWithDelta(12.5, (float) DB::table('price_schedules')->where('id', 40)->value('adjustment_rate'), 0.0001);
        $this->assertNull(DB::table('price_schedules')->where('id', 40)->value('adjustment_minor'));
        $this->assertSame(250, (int) DB::table('price_schedules')->where('id', 41)->value('adjustment_minor'));
        $this->assertSame([1], DB::table('price_schedule_targets')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(30, (int) DB::table('order_items')->where('id', 9000)->value('combo_id'));
        $this->assertSame(1, $report['conflicts']['price_schedule_targets: schedule target is not an item or category of this store']['count']);
        $this->assertTenancy(['branch_menu', 'combos', 'combo_slots', 'combo_slot_options', 'price_schedules']);
    }
}

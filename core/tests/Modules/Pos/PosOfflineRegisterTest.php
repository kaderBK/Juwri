<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Modules\Pos\Services\Offline\OfflineSnapshot;
use Tests\TestCase;

/**
 * Offline register (OP-9): what the register page hands the browser (scripts, routes, the cashier's id, strings), the
 * snapshot document the register reads, the golden snapshot the JS integrity check is tested against, and that a
 * sale / tender / void recorded EXACTLY as the register writes them (assets/pos/pos-offline.js) is accepted by the replay.
 */
class PosOfflineRegisterTest extends TestCase
{
    use OfflineTillFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private const KEYS = [
        'offline_sales_are_saved_on_this_till_and_sent_when_you_are_back_online', 'offline_this_till_has_no_saved_menu_yet_connect_once_to_sell_offline',
        'offline_time_is_up_connect_to_the_internet_to_keep_selling', 'count_offline_sales_need_a_manager_to_review_them_offline_exceptions',
        'action_is_not_available_offline_connect_to_use_it', 'offline_sale_saved_it_will_be_sent_when_you_are_back_online', 'the_cash_given_is_less_than_the_total',
        'sign_in_with_a_staff_pin_to_sell_offline', 'you_are_not_allowed_to_do_this_offline', 'the_saved_menu_does_not_have_an_item_in_this_order_connect_to_update_it',
        'reason_for_voiding_this_sale', 'sale_voided_the_void_will_be_sent_when_you_are_back_online', 'this_sale_is_already_voided',
        'the_menu_download_failed_its_check_so_the_saved_menu_was_not_changed', 'offline_receipt', 'not_sent_to_the_server_yet', 'offline_sale',
    ];

    public function test_the_register_page_loads_the_offline_scripts_routes_and_the_cashier_id(): void
    {
        $t = $this->make();
        $html = $this->asStaff($t['cashier'])->get(route('pos.index').'?store='.$t['store']->id)->assertOk()->getContent();

        $this->assertStringContainsString('assets/pos/till-calculator.js', $html);
        $this->assertStringContainsString('assets/pos/pos-offline.js', $html);
        $this->assertLessThan(strpos($html, 'assets/pos/pos-core.js'), strpos($html, 'assets/pos/pos-offline.js'), 'the module registers before the register factory runs');
        $this->assertLessThan(strpos($html, 'assets/pos/pos-offline.js'), strpos($html, 'assets/pos/till-calculator.js'), 'the calculator is loaded before the module');
        $this->assertStringContainsString('offlineSnapshot: '.json_encode(route('pos.offline.snapshot')), str_replace("'".route('pos.offline.snapshot')."'", json_encode(route('pos.offline.snapshot')), $html));
        $this->assertStringContainsString(route('pos.offline.replay'), $html);
        $this->assertStringContainsString('staffId: '.$t['cashier']->id.',', $html);
        foreach (['offlineReady', 'offlineNoMenu', 'offlineExpired', 'offlineReview', 'offlineOnly', 'offlineSaved', 'offlineLabels'] as $name) {
            $this->assertStringContainsString($name.':', $html, $name); // the strings (in the page language) the register shows
        }
        $this->assertStringContainsString('pos-offline-receipt-modal', $html);
    }

    public function test_the_snapshot_has_what_the_offline_register_reads(): void
    {
        $t = $this->make(10.5, '15');
        $doc = $this->asStaff($t['cashier'])->getJson(route('pos.offline.snapshot', ['branch' => $t['branch']->id]))->assertOk()->json();

        $this->assertSame(2, $doc['parts']['store']['exponent']);
        $this->assertSame('SAR', $doc['parts']['store']['currency']);
        $item = collect($doc['parts']['catalogue']['items'])->firstWhere('id', $t['item']->id);
        $this->assertSame(1050, $item['price_minor']);
        $this->assertSame(['qty' => 5], $item['stock']);
        $this->assertEquals(['mode' => 'percentage', 'rate' => '15', 'base' => 'subtotal_before_discount'], $doc['parts']['taxes']);
        $cashier = collect($doc['parts']['staff'])->firstWhere('id', $t['cashier']->id);
        $this->assertContains('pos', $cashier['permissions']);
        $this->assertSame(hash('sha256', OfflineSnapshot::canonical($doc['parts'])), $doc['hash'], 'the hash the register verifies');
    }

    public function test_the_golden_snapshot_hash_is_what_the_server_computes(): void
    {
        $doc = json_decode((string) file_get_contents(__DIR__.'/../../js/pos/fixtures/snapshot-golden.json'), true);
        $this->assertSame(hash('sha256', OfflineSnapshot::canonical($doc['parts'])), $doc['hash'], 'regenerate the fixture if the canonical form ever changes');
    }

    public function test_a_sale_tender_and_void_written_as_the_register_writes_them_are_accepted(): void
    {
        $t = $this->make(14.5, '15');
        // exactly the fields pos-offline.js saleRecords() / voidRecord() produce (14.50 + 15 % = 16.68; cash 20.00)
        $line = ['item_id' => $t['item']->id, 'variant_id' => null, 'qty' => 1, 'unit_minor' => 1450, 'extras' => [], 'options' => [], 'note' => null];
        $totals = ['subtotal_minor' => 1450, 'discount_minor' => 0, 'tax_minor' => 218, 'service_minor' => 0, 'tip_minor' => 0, 'total_minor' => 1668];
        $sale = $this->rec($t, 'sale', ['order_type' => 'takeaway', 'lines' => [$line], 'discount' => ['mode' => 'none'], 'service_minor' => 0, 'tip_minor' => 0,
            'totals' => $totals, 'note' => null]);
        $tender = $this->rec($t, 'tender', ['sale_key' => $sale['key'], 'method' => 'cash', 'amount_minor' => 1668, 'tendered_minor' => 2000, 'change_minor' => 332]);
        $void = $this->rec($t, 'void', ['sale_key' => $sale['key'], 'line' => null, 'reason' => 'wrong order'], $t['manager']->id);

        $res = $this->replay($t, [$sale, $tender, $void])->assertOk();
        $this->assertSame(['accepted', 'accepted', 'accepted'], array_column($res->json('results'), 'status'), json_encode($res->json('results')));
        $order = Order::query()->where('client_uuid', $sale['key'])->firstOrFail();
        $this->assertSame(1668, $res->json('results.0.result.totals.total_minor'), 'the register\'s 2.18 tax is the server\'s 2.18 tax');
        $this->assertSame('cash', $order->payment_method);
    }

    public function test_every_new_string_is_translated_in_all_eleven_locales(): void
    {
        $en = require base_path('lang/en/lang.php');
        foreach (['ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh', 'en'] as $locale) {
            $lines = require base_path("lang/{$locale}/lang.php");
            foreach (self::KEYS as $key) {
                $this->assertArrayHasKey($key, $lines, "{$locale}: {$key}");
                $this->assertNotSame('', trim($lines[$key]));
                foreach ([':count', ':action'] as $placeholder) {
                    $this->assertSame(str_contains($en[$key], $placeholder), str_contains($lines[$key], $placeholder), "{$locale}: {$key} keeps {$placeholder}");
                }
                if ($locale !== 'en') {
                    $this->assertNotSame($en[$key], $lines[$key], "{$locale}: {$key} is still English");
                }
            }
        }
    }
}

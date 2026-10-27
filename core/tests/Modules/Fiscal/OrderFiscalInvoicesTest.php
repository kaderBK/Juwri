<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Fiscal\Contracts\FiscalInvoices;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\FiscalChain;
use Juwri\Modules\Fiscal\Services\Tlv;
use Juwri\Modules\Fiscal\Services\Zatca\Contracts\ZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\FakeZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaCrypto;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaOnboarding;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Ordering\Services\OrderFiscalContext;
use Juwri\Modules\Ordering\Services\OrderFiscalInvoices;
use Juwri\Modules\Payments\Contracts\Outbox;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P4-01 (found by the fiscal journey): nothing issued an e-invoice. An onboarded ZATCA store's paid orders were never
 * reported, the receipt kept a self-generated QR, and a refund left no credit note. Now a paid order issues its
 * simplified invoice once (reported, 9-tag QR on the receipt), and each refund / void issues a credit note that
 * references it (381 / 0200000 + the reason), with the refunded tax pro rata.
 */
class OrderFiscalInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private FakeZatcaClient $fake;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_fiscalization' => true, 'settings.admin_allow_fiscal_sa' => true]);
        $this->fake = new FakeZatcaClient;
        $this->app->instance(ZatcaClient::class, $this->fake);
    }

    private function store(string $country = 'SA', bool $onboard = true): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999])]);
        $u = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(['user_id' => $u->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Diner', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['fiscal_country' => $country, 'fiscal_vat_number' => '300000000000003', 'fiscal_legal_name' => 'Diner Co', 'restaurant_taxx_charge' => 15] as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }
        if ($onboard) {
            $svc = new ZatcaOnboarding($this->fake, new ZatcaCrypto);
            $device = $svc->ensureDevice($store->id, 'EGS-1');
            $svc->requestComplianceCsid($device, '123456', ['vat' => '300000000000003', 'country' => 'SA', 'common_name' => 'EGS-1']);
            $svc->runComplianceChecks($device->fresh(), array_fill(0, 6, ['xml' => '<Invoice/>']));
            $svc->requestProductionCsid($device->fresh());
        }

        return $store;
    }

    /** 2 × 10.00 + 15 % = 23.00 (tax 3.00). */
    private function order(Store $store): Order
    {
        $order = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new']);
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Tea', 'price' => 10, 'active' => '1']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 2, 'unit_price' => 10]);

        return $order->fresh();
    }

    private function refund(Order $order, float $amount, string $reason = 'Wrong item'): OrderAdjustment
    {
        return OrderAdjustment::create(['order_id' => $order->id, 'type' => 'refund', 'amount' => $amount, 'method' => 'cash', 'reason' => $reason]);
    }

    private function docs(Store $store): Collection
    {
        return FiscalDocument::where('store_id', $store->id)->orderBy('id')->get();
    }

    public function test_a_paid_order_issues_its_simplified_invoice_once_and_the_receipt_shows_its_qr(): void
    {
        $store = $this->store();
        $order = $this->order($store);
        $this->assertCount(0, $this->docs($store), 'nothing before payment');

        $order->update(['is_paid' => 1]);

        $docs = $this->docs($store);
        $this->assertCount(1, $docs);
        $inv = $docs->first();
        $this->assertSame('simplified', $inv->type);
        $this->assertSame('order:'.$order->id, $inv->source_ref);
        $this->assertSame((int) $order->id, (int) $inv->order_id);
        $this->assertSame(2300, $inv->total_minor);
        $this->assertSame(300, $inv->tax_total_minor);
        $this->assertSame('accepted', $inv->status, 'reported to the authority');
        $this->assertContains('reportInvoice', $this->fake->calls);
        $this->assertStringContainsString('name="0200000">388', $inv->payload);
        $this->assertSame(9, $inv->authority_response['tags']);

        // un-paid and paid again (a late payment replay), and a direct second call: still one invoice
        $order->update(['is_paid' => 0]);
        $order->update(['is_paid' => 1]);
        $again = app(FiscalInvoices::class)->invoice($store, OrderFiscalContext::for($store, $order), 'order:'.$order->id);
        $this->assertSame($inv->id, $again->id);
        $this->assertCount(1, $this->docs($store));

        // the receipt carries the reported invoice's 9-tag QR
        $ctx = OrderFiscalContext::for($store, $order->fresh());
        $this->assertSame($inv->authority_response['tlv'], $ctx->extra('tlv'));
        $this->assertCount(9, Tlv::decode($ctx->extra('tlv')));
        $html = View::make('fiscal.receipt-block', ['store' => $store, 'order' => $order->fresh()])->render();
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString(': '.$inv->number.'</div>', $html, 'the e-invoice number is printed');
    }

    public function test_a_refund_issues_a_credit_note_referencing_the_invoice_and_the_receipt_keeps_the_sale_qr(): void
    {
        $store = $this->store();
        $order = $this->order($store);
        $order->update(['is_paid' => 1]);
        $inv = $this->docs($store)->first();

        $adj = $this->refund($order, 11.50, 'Wrong item');

        $note = FiscalDocument::where('store_id', $store->id)->where('type', 'credit_note')->sole();
        $this->assertSame('refund:'.$adj->id, $note->source_ref);
        $this->assertSame(1150, $note->total_minor);
        $this->assertSame(150, $note->tax_total_minor, 'tax pro rata: 11.50 × 3.00 / 23.00');
        $this->assertStringContainsString('name="0200000">381', $note->payload, 'credit note of a simplified invoice is simplified');
        $this->assertStringContainsString('<cac:BillingReference>', $note->payload);
        $this->assertStringContainsString('<cbc:ID>'.$inv->number.'</cbc:ID>', $note->payload);
        $this->assertStringContainsString('<cbc:InstructionNote>Wrong item</cbc:InstructionNote>', $note->payload);
        $this->assertSame('accepted', $note->status);
        $this->assertSame((int) $inv->counter + 1, (int) $note->counter, 'same device chain');
        $this->assertSame($inv->hash, $note->previous_hash);

        // before: the receipt took the LATEST document of the order — the credit note's QR
        $this->assertSame($inv->authority_response['tlv'], OrderFiscalContext::for($store, $order->fresh())->extra('tlv'));

        // the rest refunded: a second credit note; the full set never exceeds the sale
        $this->refund($order, 11.50, 'Customer left');
        $this->assertSame(2, FiscalDocument::where('store_id', $store->id)->where('type', 'credit_note')->count());
    }

    public function test_a_full_refund_credits_exactly_the_invoiced_tax(): void
    {
        $store = $this->store();
        $order = $this->order($store);
        $order->update(['is_paid' => 1]);
        $this->refund($order, 23.00, '');

        $note = FiscalDocument::where('store_id', $store->id)->where('type', 'credit_note')->sole();
        $this->assertSame(2300, $note->total_minor);
        $this->assertSame(300, $note->tax_total_minor);
        $this->assertStringContainsString('<cbc:InstructionNote>Refund</cbc:InstructionNote>', $note->payload);
    }

    public function test_nothing_is_issued_without_an_onboarded_device_or_outside_sa_or_with_the_switch_off(): void
    {
        $noDevice = $this->store('SA', onboard: false);
        $o1 = $this->order($noDevice);
        $o1->update(['is_paid' => 1]);
        $this->refund($o1, 5);
        $this->assertCount(0, $this->docs($noDevice));

        $before = DB::table('outbox_messages')->where('type', 'like', 'fiscal.%')->count();
        $notSa = $this->store('QA', onboard: false); // a country without an e-invoicing authority
        $o2 = $this->order($notSa);
        $o2->update(['is_paid' => 1]);
        $this->assertCount(0, $this->docs($notSa));
        $this->assertSame(0, DB::table('outbox_messages')->where('type', 'like', 'fiscal.%')->count() - $before, 'no outbox message for a store that does not e-invoice');

        $sa = $this->store();
        config(['settings.admin_allow_fiscal_sa' => false]);
        $o3 = $this->order($sa);
        $o3->update(['is_paid' => 1]);
        $this->assertCount(0, $this->docs($sa));
    }

    public function test_a_refund_never_credits_another_stores_invoice(): void
    {
        $a = $this->store();
        $b = $this->store();
        $orderA = $this->order($a);
        $orderA->update(['is_paid' => 1]);
        $orderB = $this->order($b);
        $orderB->update(['is_paid' => 1]);

        $this->refund($orderB, 5);

        $this->assertSame(0, FiscalDocument::where('store_id', $a->id)->where('type', 'credit_note')->count());
        $invB = FiscalDocument::where('store_id', $b->id)->where('type', 'simplified')->sole();
        $noteB = FiscalDocument::where('store_id', $b->id)->where('type', 'credit_note')->sole();
        $this->assertStringContainsString('<cbc:ID>'.$invB->number.'</cbc:ID>', $noteB->payload);
    }

    public function test_a_refund_made_while_the_invoice_is_still_owed_gets_the_invoice_first_then_its_credit_note(): void
    {
        // P4-01 follow-up (coordinator): the invoice's outbox message failed (authority down, worker crash) and is
        // still pending when the refund lands. Before: the credit note found no invoice and was silently skipped.
        $store = $this->store();
        $outbox = app(Outbox::class);
        $outbox->handle(OrderFiscalInvoices::INVOICE, function (): void {
            throw new \RuntimeException('authority unreachable');
        });
        $order = $this->order($store);
        $order->update(['is_paid' => 1]);
        $this->assertCount(0, $this->docs($store), 'the invoice is still owed');
        $pending = (int) DB::table('outbox_messages')->where('type', OrderFiscalInvoices::INVOICE)->value('id');
        $this->assertNotSame('done', $outbox->status($pending));

        $adj = $this->refund($order, 11.50);

        $docs = $this->docs($store);
        $this->assertSame(['simplified', 'credit_note'], $docs->pluck('type')->all(), 'invoice first, then the credit note');
        $this->assertSame('order:'.$order->id, $docs[0]->source_ref);
        $this->assertSame('refund:'.$adj->id, $docs[1]->source_ref);
        $this->assertStringContainsString('<cbc:ID>'.$docs[0]->number.'</cbc:ID>', $docs[1]->payload);

        // the owed message runs later (relay retry): it finds the invoice issued — never a second one
        OrderFiscalInvoices::register($outbox);
        $this->travel(2)->hours(); // past the retry back-off
        $outbox->relay([$pending]);
        $this->assertSame('done', $outbox->status($pending));
        $this->assertSame(1, FiscalDocument::where('store_id', $store->id)->where('type', 'simplified')->count());
    }

    public function test_an_order_refunded_and_unpaid_before_the_relay_still_gets_invoice_and_credit_note(): void
    {
        // late-payment refund: paid, refunded and set unpaid in one transaction — before, the invoice handler skipped an
        // unpaid order, so neither document was issued
        $store = $this->store();
        $order = $this->order($store);
        DB::transaction(function () use ($order): void {
            $order->update(['is_paid' => 1]);
            $this->refund($order, 23.00, 'Late payment refund');
            $order->update(['is_paid' => 0]);
        });

        $this->assertSame(['simplified', 'credit_note'], $this->docs($store)->pluck('type')->all());
    }

    public function test_a_void_of_an_unpaid_order_issues_nothing(): void
    {
        $store = $this->store();
        $order = $this->order($store);
        OrderAdjustment::create(['order_id' => $order->id, 'type' => 'void', 'amount' => 5, 'reason' => 'Mistake']);
        $this->assertCount(0, $this->docs($store));
    }

    public function test_the_chain_returns_the_existing_document_for_a_source_already_issued(): void
    {
        $store = $this->store();
        $attrs = ['country' => 'SA', 'type' => 'simplified', 'total' => 1, 'tax_total' => 0, 'currency' => 'SAR', 'source_ref' => 'order:77'];
        $first = FiscalChain::issue($store->id, 'EGS-1', $attrs);
        $this->assertTrue($first->wasRecentlyCreated);
        $second = FiscalChain::issue($store->id, 'EGS-1', $attrs); // a racing issuer that missed the pre-check
        $this->assertSame($first->id, $second->id);
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame(1, FiscalDocument::where('store_id', $store->id)->count());
    }
}

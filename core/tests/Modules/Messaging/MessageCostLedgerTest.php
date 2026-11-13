<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Modules\Messaging\Contracts\MessageChargeGate;
use Juwri\Modules\Messaging\Events\MessageCostRecorded;
use Juwri\Modules\Messaging\Models\MessageCost;
use Juwri\Modules\Messaging\Services\MessageCostLedger;
use Juwri\Modules\Messaging\Services\NullMessageChargeGate;
use Juwri\Modules\Messaging\Services\SmsService;
use Juwri\Modules\Messaging\Services\WhatsApp\AbstractWhatsAppProvider;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppProviderFactory;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppResult;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** A driver that "sends" successfully (cost-ledger tests). */
class LedgerFakeProvider extends AbstractWhatsAppProvider
{
    public function isConfigured(): bool
    {
        return true;
    }

    public function capabilities(): array
    {
        return array_merge(parent::capabilities(), ['send' => true, 'template' => true]);
    }

    public function sendText(string $to, string $body, array $opts = []): WhatsAppResult
    {
        return WhatsAppResult::sent('fake', 'msg_1');
    }

    public function sendTemplate(string $to, string $template, array $vars = [], array $opts = []): WhatsAppResult
    {
        return WhatsAppResult::sent('fake', 'tmpl_1');
    }
}

/**
 * P1-08b follow-up (J27): the message_costs ledger — a line per sent WhatsApp/SMS/email message (units + cost when
 * known, NULL cost otherwise), tenant-scoped, and the MessageCostRecorded event Billing settles WhatsApp quota/wallet
 * from (Messaging no longer calls Billing).
 */
class MessageCostLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores(1, 2);
        WhatsAppProviderFactory::register('fake', LedgerFakeProvider::class);
        config(['whatsapp.default_provider' => 'fake', 'whatsapp.markup_pct' => 0, 'whatsapp.markup_flat' => 0, 'whatsapp.billing_mode' => 'quota_only']);
    }

    protected function tearDown(): void
    {
        WhatsAppProviderFactory::flush();
        TenantContext::current()->clear();
        parent::tearDown();
    }

    private function owner(?int $perMonth = null): User
    {
        $plan = TestPlans::create([
            'name' => 'P', 'description' => 'p', 'monthly_price' => 0, 'annual_price' => 0, 'lifetime_price' => 0,
            'settings' => $perMonth === null ? [] : ['whatsapp_messages_per_month' => $perMonth], 'status' => 1,
        ]);

        return User::factory()->create(['status' => 1, 'group_id' => $plan->id]);
    }

    public function test_a_whatsapp_send_writes_a_cost_line_and_billing_settles_from_the_event(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        $user = $this->owner();
        $res = app(WhatsAppService::class)->send('+15551234567', 'hi', storeId: 1, feature: 'order', template: 'order_confirm', userId: $user->id);
        $this->assertTrue($res->sent);

        $row = MessageCost::withoutGlobalScopes()->sole();
        $this->assertSame(['whatsapp', 'utility', 'US', 1, 'fake', 'tmpl_1', 'order', 'USD'], [$row->channel, $row->category, $row->country, $row->units, $row->provider, $row->provider_ref, $row->feature, $row->currency]);
        $this->assertSame('0.400000', $row->unit_cost_minor, 'US utility USD 0.004 = 0.4 cents, kept exactly');
        $this->assertSame('0.400000', $row->cost_minor);
        $this->assertSame(1, (int) $row->store_id);
        $this->assertSame(DB::table('stores')->where('id', 1)->value('account_id'), $row->account_id);

        // Billing (listener on MessageCostRecorded) still meters the send.
        $this->assertDatabaseHas('whatsapp_usage', ['user_id' => $user->id, 'store_id' => 1, 'category' => 'utility', 'billable' => true]);
    }

    public function test_billing_settles_only_through_the_event(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        Event::fake([MessageCostRecorded::class]);
        $user = $this->owner();
        app(WhatsAppService::class)->send('+15551234567', 'hi', storeId: 1, feature: 'otp', template: 'otp', userId: $user->id);

        Event::assertDispatched(MessageCostRecorded::class, fn (MessageCostRecorded $e) => $e->channel === 'whatsapp'
            && $e->category === 'auth' && $e->userId === $user->id && $e->storeId === 1
            && $e->charge['billable'] === true && $e->charge['category'] === 'authentication' && $e->messageCostId !== null);
        $this->assertDatabaseCount('whatsapp_usage', 0);
    }

    public function test_a_blocked_send_costs_nothing(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        $user = $this->owner(1);
        app(WhatsAppService::class)->send('+15551234567', 'a', storeId: 1, feature: 'order', template: 't', userId: $user->id);
        Event::fake([MessageCostRecorded::class]);
        $res = app(WhatsAppService::class)->send('+15551234567', 'b', storeId: 1, feature: 'order', template: 't', userId: $user->id);

        $this->assertSame('limit_exceeded', $res->status);
        Event::assertNotDispatched(MessageCostRecorded::class);
        $this->assertSame(1, MessageCost::withoutGlobalScopes()->count());
    }

    public function test_messaging_falls_back_to_a_gate_that_bills_nothing(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        $this->app->bind(MessageChargeGate::class, NullMessageChargeGate::class);
        app(WhatsAppService::class)->send('+971501234567', 'promo', storeId: 1, feature: 'campaign', template: 'promo', userId: $this->owner()->id);

        $row = MessageCost::withoutGlobalScopes()->sole();
        $this->assertSame(['marketing', 'AE', '4.990000'], [$row->category, $row->country, $row->cost_minor]);
        // Billing still meters the send, but nothing is billable without its gate.
        $this->assertDatabaseHas('whatsapp_usage', ['category' => 'marketing', 'billable' => false]);
    }

    public function test_an_sms_send_records_segments_with_an_unknown_cost(): void
    {
        config(['sms.provider' => 'twilio', 'sms.twilio.sid' => 'AC1', 'sms.twilio.token' => 't', 'sms.twilio.from' => '+15550000000']);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        $this->assertTrue(new SmsService()->send('+15551234567', str_repeat('a', 200), 2, 'otp'));

        $row = MessageCost::withoutGlobalScopes()->sole();
        $this->assertSame(['sms', 'auth', 2, 'twilio', 'US', 2], [$row->channel, $row->category, $row->units, $row->provider, $row->country, (int) $row->store_id]);
        $this->assertNull($row->cost_minor);
        $this->assertNull($row->unit_cost_minor);
        $this->assertNull($row->currency);
    }

    public function test_simulated_sms_is_not_a_cost(): void
    {
        config(['sms.provider' => 'log']);
        $this->assertTrue(new SmsService()->send('+15551234567', 'hi', 2, 'order'));
        $this->assertSame(0, MessageCost::withoutGlobalScopes()->count());
    }

    public function test_sms_segments(): void
    {
        $this->assertSame(1, MessageCostLedger::smsSegments(str_repeat('a', 160)));
        $this->assertSame(2, MessageCostLedger::smsSegments(str_repeat('a', 161)));
        $this->assertSame(1, MessageCostLedger::smsSegments(str_repeat('م', 70)));
        $this->assertSame(2, MessageCostLedger::smsSegments(str_repeat('م', 71)));
    }

    public function test_a_sent_email_records_its_recipients(): void
    {
        Mail::raw('hello', fn ($m) => $m->to(['a@example.test', 'b@example.test'])->cc('c@example.test')->subject('Hi'));

        $row = MessageCost::withoutGlobalScopes()->where('channel', 'email')->sole();
        $this->assertSame(['utility', 3], [$row->category, $row->units]);
        $this->assertNull($row->cost_minor);
        $this->assertNull($row->account_id, 'platform mail outside a tenant context');
    }

    public function test_an_email_inside_a_tenant_context_belongs_to_that_account(): void
    {
        $account = (int) DB::table('stores')->where('id', 2)->value('account_id');
        TenantContext::current()->actAs($account, [2]);
        Mail::raw('hello', fn ($m) => $m->to('a@example.test')->subject('Hi'));

        $row = MessageCost::where('channel', 'email')->sole();
        $this->assertSame([$account, 2], [(int) $row->account_id, (int) $row->store_id]);
    }

    public function test_cost_lines_are_tenant_scoped(): void
    {
        app(WhatsAppService::class)->send('+15551234567', 'hi', storeId: 1, feature: 'order', template: 't', userId: $this->owner()->id);
        $other = (int) DB::table('stores')->where('id', 2)->value('account_id');
        $mine = (int) DB::table('stores')->where('id', 1)->value('account_id');
        $this->assertNotSame($mine, $other);

        TenantContext::current()->actAs($other, [2]);
        $this->assertSame(0, MessageCost::count(), 'another account sees none of the lines');
        TenantContext::current()->actAs($mine, [1]);
        $this->assertSame(1, MessageCost::count());
    }

    public function test_a_failed_ledger_write_still_announces_the_send(): void
    {
        Event::fake([MessageCostRecorded::class]);
        $row = app(MessageCostLedger::class)->record(channel: 'fax', category: 'utility', storeId: 1, charge: ['billable' => true, 'category' => 'utility']);

        $this->assertNull($row);
        Event::assertDispatched(MessageCostRecorded::class, fn (MessageCostRecorded $e) => $e->messageCostId === null && $e->channel === 'fax');
        $this->assertSame(0, MessageCost::withoutGlobalScopes()->count());
    }

    public function test_messaging_never_calls_billing(): void
    {
        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Modules/Messaging')));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php'
                && preg_match('/WhatsAppBilling|Modules\\\\Billing\\\\/', (string) file_get_contents($file->getPathname()))) {
                $hits[] = $file->getPathname();
            }
        }
        $this->assertSame([], $hits);
    }
}

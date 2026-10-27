<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\EntitlementWriter;
use Tests\TestCase;

/**
 * BIOLINK-RUN A8: the email/phone capture endpoint writes into the store's
 * existing Customer marketing rail, with honeypot + dedup + consent stamping.
 */
class BioLinkCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class]);
    }

    private function enabledStore(): Store
    {
        $store = Store::factory()->create();
        config(['settings.admin_allow_biolink' => true]);
        EntitlementWriter::grant($store->user, 'biolink');

        return $store;
    }

    public function test_email_capture_creates_a_consented_subscriber(): void
    {
        $store = $this->enabledStore();

        $this->postJson("/{$store->slug}/bio/subscribe", ['email' => 'fan@example.com', 'name' => 'Fan'])
            ->assertOk()->assertJson(['ok' => true]);

        $c = Customer::where('store_id', $store->id)->where('email', 'fan@example.com')->first();
        $this->assertNotNull($c);
        $this->assertTrue($c->marketing_consent);
        $this->assertNotNull($c->consent_at);
        $this->assertSame('bio', $c->source);
    }

    public function test_phone_capture_sets_sms_consent(): void
    {
        $store = $this->enabledStore();

        $this->postJson("/{$store->slug}/bio/subscribe", ['phone' => '+1 555 0100'])->assertOk();

        $c = Customer::where('store_id', $store->id)->first();
        $this->assertNotNull($c);
        $this->assertTrue($c->sms_consent);
        $this->assertTrue($c->marketing_consent);
    }

    public function test_duplicate_email_updates_not_duplicates(): void
    {
        $store = $this->enabledStore();

        $this->postJson("/{$store->slug}/bio/subscribe", ['email' => 'dup@example.com'])->assertOk();
        $this->postJson("/{$store->slug}/bio/subscribe", ['email' => 'dup@example.com', 'name' => 'Now Named'])->assertOk();

        $this->assertSame(1, Customer::where('store_id', $store->id)->where('email', 'dup@example.com')->count());
        $this->assertSame('Now Named', Customer::where('store_id', $store->id)->where('email', 'dup@example.com')->value('name'));
    }

    public function test_honeypot_silently_drops_the_bot(): void
    {
        $store = $this->enabledStore();

        // gap-fix: the honeypot field the form actually renders is `company_website`
        // (the controller used to read a `company` field no form emits — a dead check).
        $this->postJson("/{$store->slug}/bio/subscribe", ['email' => 'bot@example.com', 'company_website' => 'AcmeBot'])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(0, Customer::where('store_id', $store->id)->count());
    }

    public function test_empty_submission_is_rejected(): void
    {
        $store = $this->enabledStore();

        $this->postJson("/{$store->slug}/bio/subscribe", [])->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertSame(0, Customer::where('store_id', $store->id)->count());
    }

    public function test_dark_store_captures_nothing(): void
    {
        $store = Store::factory()->create(); // feature dark

        $this->postJson("/{$store->slug}/bio/subscribe", ['email' => 'x@example.com'])->assertStatus(422);
        $this->assertSame(0, Customer::where('store_id', $store->id)->count());
    }
}

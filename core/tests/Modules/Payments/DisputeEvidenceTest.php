<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\AbuseHoldWall;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\SyncDashboardStore;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Models\Dispute;
use Tests\Support\EditionTestHooks;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WIRING-RUN E6.
 *
 * The disputes page used to tell owners "in-app evidence submission is coming soon"
 * and send them to the Stripe dashboard. Evidence for Stripe's text fields is now
 * submitted from the dashboard itself.
 *
 * These cases cover the guards around that submission. The Stripe call itself is not
 * exercised — it goes through \Stripe\StripeClient rather than Laravel's HTTP client,
 * so it cannot be faked with Http::fake; every case here fails before that call, which
 * is deliberately where all the tenant-safety logic lives.
 */
class DisputeEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        config(['settings.admin_allow_disputes' => true]);

        // Drop the install/csrf/dashboard-shell middleware but KEEP the feature
        // kill-switch, so the gate itself stays under test. Same set as FeatureGateAuditTest.
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
            SyncDashboardStore::class,
            ...EditionTestHooks::middleware(),
            AbuseHoldWall::class,
        ]);
    }

    private function ownerWithStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'd-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'D', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $store];
    }

    private function dispute(Store $store, string $status = 'needs_response'): Dispute
    {
        return Dispute::create([
            'store_id' => $store->id, 'gateway' => 'stripe',
            'external_id' => 'dp_'.Str::random(10), 'amount' => 50.00, 'currency' => 'USD',
            'status' => $status, 'reason' => 'fraudulent',
            'opened_at' => now()->subDay(), 'evidence_due_at' => now()->addDays(5),
        ]);
    }

    /** The critical guard: a dispute is resolved through the CURRENT store, never the posted id alone. */
    public function test_owner_cannot_submit_evidence_against_another_tenants_dispute(): void
    {
        [$owner] = $this->ownerWithStore();
        [, $strangerStore] = $this->ownerWithStore();
        $theirs = $this->dispute($strangerStore);

        $this->actingAs($owner)
            ->post(route('stores.disputes.evidence', $theirs->id), ['uncategorized_text' => 'not mine'])
            ->assertNotFound();

        $this->assertNull($theirs->fresh()->evidence_submitted_at);
    }

    public function test_submission_is_refused_for_a_closed_dispute(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $closed = $this->dispute($store, 'lost');

        $this->actingAs($owner)
            ->post(route('stores.disputes.evidence', $closed->id), ['uncategorized_text' => 'too late'])
            ->assertSessionHas('error');

        $this->assertNull($closed->fresh()->evidence_submitted_at);
    }

    public function test_empty_evidence_is_refused_before_any_stripe_call(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $d = $this->dispute($store);

        $this->actingAs($owner)
            ->post(route('stores.disputes.evidence', $d->id), ['uncategorized_text' => '   '])
            ->assertSessionHas('error');

        $this->assertNull($d->fresh()->evidence_submitted_at);
    }

    /** Without a Stripe key there is nothing to submit to — say so rather than failing opaquely. */
    public function test_missing_stripe_key_is_reported_clearly(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $d = $this->dispute($store);
        config(['settings.stripe_secret_key' => '']);
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_secret_key', '');

        $this->actingAs($owner)
            ->post(route('stores.disputes.evidence', $d->id), ['uncategorized_text' => 'we delivered it'])
            ->assertSessionHas('error');

        $this->assertNull($d->fresh()->evidence_submitted_at);
    }

    /** Only Stripe's documented evidence fields are accepted; anything else is rejected outright. */
    public function test_unknown_evidence_fields_are_rejected(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $d = $this->dispute($store);

        $this->actingAs($owner)
            ->post(route('stores.disputes.evidence', $d->id), [
                'uncategorized_text' => 'ok',
                'evidence[metadata]' => 'injected',
            ])
            ->assertSessionMissing('success');
    }

    public function test_the_disputes_page_is_still_gated_by_the_kill_switch(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $d = $this->dispute($store);
        config(['settings.admin_allow_disputes' => false]);

        $this->actingAs($owner)
            ->post(route('stores.disputes.evidence', $d->id), ['uncategorized_text' => 'x'])
            ->assertForbidden();
    }
}

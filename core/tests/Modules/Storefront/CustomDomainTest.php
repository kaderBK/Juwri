<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\TrustHosts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Juwri\Modules\Storefront\Services\DomainVerifier;
use Tests\TestCase;

class CustomDomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    private function owner(): array
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$user, $store];
    }

    private function fakeVerifier(bool $result): void
    {
        $this->app->instance(DomainVerifier::class, new class($result) extends DomainVerifier
        {
            public function __construct(private bool $r) {}

            public function verify(string $domain, string $token, bool $imported = false): bool
            {
                return $this->r;
            }
        });
    }

    public function test_store_creates_unverified_domain_with_token(): void
    {
        [$user, $store] = $this->owner();

        $res = $this->actingAs($user)->postJson("/stores/{$store->id}/domains", ['domain' => 'menu.thethai.com']);

        $res->assertStatus(201)->assertJsonPath('domain.verified', false);
        $this->assertDatabaseHas('store_domains', ['store_id' => $store->id, 'host' => 'menu.thethai.com', 'verified_at' => null]);
        $this->assertNotEmpty($res->json('domain.verification_token'));
        // BL-036 R2 review fix 6: the TXT proof goes on the challenge name, next to the CNAME on the host.
        $res->assertJsonPath('instructions.txt.host', DomainVerifier::CHALLENGE_LABEL.'.menu.thethai.com')
            ->assertJsonPath('instructions.cname.host', 'menu.thethai.com');
    }

    public function test_duplicate_domain_rejected(): void
    {
        [$user, $store] = $this->owner();
        StoreDomain::create(['store_id' => $store->id, 'domain' => 'taken.com', 'verification_token' => 't', 'verified' => false]);

        $this->actingAs($user)->postJson("/stores/{$store->id}/domains", ['domain' => 'taken.com'])
            ->assertStatus(409);
    }

    public function test_verify_marks_verified_when_dns_passes(): void
    {
        [$user, $store] = $this->owner();
        $d = StoreDomain::create(['store_id' => $store->id, 'domain' => 'ok.com', 'verification_token' => 'tok', 'verified' => false]);
        $this->fakeVerifier(true);

        $this->actingAs($user)->postJson("/stores/{$store->id}/domains/{$d->id}/verify")
            ->assertOk()->assertJsonPath('verified', true);

        $this->assertTrue($d->fresh()->verified);
        $this->assertSame('active', $d->fresh()->ssl_status);
    }

    public function test_verify_fails_when_dns_absent(): void
    {
        [$user, $store] = $this->owner();
        $d = StoreDomain::create(['store_id' => $store->id, 'domain' => 'no.com', 'verification_token' => 'tok', 'verified' => false]);
        $this->fakeVerifier(false);

        $this->actingAs($user)->postJson("/stores/{$store->id}/domains/{$d->id}/verify")
            ->assertStatus(422)->assertJsonPath('verified', false);
        $this->assertFalse($d->fresh()->verified);
    }

    public function test_cannot_verify_another_owners_domain(): void
    {
        [$user, $store] = $this->owner();
        [, $otherPost] = $this->owner();
        $d = StoreDomain::create(['store_id' => $otherPost->id, 'domain' => 'foreign.com', 'verification_token' => 't', 'verified' => false]);
        $this->fakeVerifier(true);

        $this->actingAs($user)->postJson("/stores/{$store->id}/domains/{$d->id}/verify")
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
    }

    public function test_resolve_returns_post_only_for_verified_domain(): void
    {
        [, $store] = $this->owner();
        StoreDomain::create(['store_id' => $store->id, 'domain' => 'live.com', 'verification_token' => 't', 'verified' => true]);
        StoreDomain::create(['store_id' => $store->id, 'domain' => 'pending.com', 'verification_token' => 't', 'verified' => false]);

        $this->assertSame($store->id, StoreDomain::resolve('live.com')?->id);
        $this->assertNull(StoreDomain::resolve('pending.com'));
        $this->assertNull(StoreDomain::resolve('unknown.com'));
    }

    public function test_destroy_removes_domain(): void
    {
        [$user, $store] = $this->owner();
        $d = StoreDomain::create(['store_id' => $store->id, 'domain' => 'del.com', 'verification_token' => 't', 'verified' => true]);

        $this->actingAs($user)->deleteJson("/stores/{$store->id}/domains/{$d->id}")->assertOk();
        $this->assertDatabaseMissing('store_domains', ['id' => $d->id]);
    }

    /**
     * The edit page's Domains tab is a separate Alpine component from the
     * dedicated /domains page; it reads this JSON. If the token were hidden it
     * could never render the TXT row, so pin the contract here.
     */
    public function test_index_json_exposes_the_verification_token(): void
    {
        [$user, $store] = $this->owner();
        $d = StoreDomain::create([
            'store_id' => $store->id, 'domain' => 'menu.thethai.com',
            'verification_token' => 'mb-verify-abc123', 'verified' => false,
        ]);

        $res = $this->actingAs($user)->getJson("/stores/{$store->id}/domains");

        $res->assertOk()
            ->assertJsonPath('domains.0.id', $d->id)
            ->assertJsonPath('domains.0.verification_token', 'mb-verify-abc123')
            ->assertJsonPath('domains.0.verified', false);
        $this->assertNotEmpty($res->json('target'));
    }

    /**
     * Regression: the Domains tab on the store EDIT page showed only
     * domain/status/Verify/Remove — never the CNAME + TXT records — so owners
     * could not find the TXT token they needed to verify. Asserted through the
     * controller (a blade-direct render would miss the route/contract).
     */
    public function test_edit_page_renders_dns_records_for_unverified_domain(): void
    {
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        // APP_INSTALLED=false skips the view composer, so share the globals by hand.
        $this->shareLayoutGlobals();

        [$user, $store] = $this->owner();
        StoreDomain::create([
            'store_id' => $store->id, 'domain' => 'menu.thethai.com',
            'verification_token' => 'mb-verify-abc123', 'verified' => false,
        ]);

        $html = $this->actingAs($user)->get(route('stores.edit', $store))->assertOk()->getContent();

        // Both record types are offered, and the TXT row binds the live token.
        $this->assertStringContainsString('<code>CNAME</code>', $html);
        $this->assertStringContainsString('<code>TXT</code>', $html);
        $this->assertStringContainsString('d.verification_token', $html);
        // The records block is gated on the domain still being unverified.
        $this->assertStringContainsString('x-if="!d.verified"', $html);
    }

    /**
     * TrustHosts anchors every verified domain exactly, so an exact-only list
     * made https://www.{domain} 400 on the global stack before routing ever
     * ran (2026-09-01: winningbuds.com apex worked, www 400'd). The www alias
     * must be trusted alongside the apex.
     */
    public function test_trust_hosts_covers_the_www_variant_of_verified_domains(): void
    {
        [, $store] = $this->owner();
        StoreDomain::create(['store_id' => $store->id, 'domain' => 'menu.thethai.com', 'verification_token' => 't', 'verified' => true]);

        Cache::forget('trusted_custom_domains');
        $hosts = app(TrustHosts::class)->hosts();

        $this->assertContains('^'.preg_quote('menu.thethai.com').'$', $hosts);
        $this->assertContains('^'.preg_quote('www.menu.thethai.com').'$', $hosts);
    }

    // P1-20: the www alias, deep paths, HEAD and unknown hosts are covered through the real HTTP stack in
    // tests/Modules/Storefront/StoreAddressTest (ResolveStoreHost replaced ResolveCustomDomain).

    /** BL-036 R2: a verify while another claim holds the host's lock answers "busy, retry", never a 500. */
    public function test_verify_while_the_host_is_being_claimed_says_busy(): void
    {
        [$user, $store] = $this->owner();
        $d = StoreDomain::create(['store_id' => $store->id, 'domain' => 'busy.com', 'verification_token' => 'tok', 'verified' => false]);
        $this->fakeVerifier(true);
        config(['hosts.claim_lock_wait_seconds' => 1]);
        $held = Cache::lock(StoreDomain::claimLockKey('busy.com'), 30);
        $this->assertTrue($held->get());
        $busy = ___('The domain is busy with another change. Please try again in a moment.');

        try {
            $this->actingAs($user)->postJson("/stores/{$store->id}/domains/{$d->id}/verify")
                ->assertStatus(409)->assertJsonPath('message', $busy);
            $this->actingAs($user)->post("/stores/{$store->id}/domains/{$d->id}/verify")
                ->assertRedirect()->assertSessionHas('error', $busy);
        } finally {
            $held->release();
        }
        $this->assertFalse($d->fresh()->verified);
    }
}

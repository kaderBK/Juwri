<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Controllers\User\StoreController;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Juwri\Modules\Storefront\Services\DomainVerifier;
use Juwri\Modules\Storefront\Services\StoreAddressResolver;
use Juwri\Modules\Storefront\Services\StoreDomains;
use Tests\TestCase;

/**
 * P1-20 slices 1–2 (J28): store_domains, the one resolver, primary address + 301s, store_url(), domain
 * takeover guards. Storefront controllers are stubbed so these pin routing, not theme rendering.
 */
class StoreAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, EnsureStaffDashboardPermission::class]);
        $this->app->instance(StoreController::class, new class extends StoreController
        {
            public function publicView($slug)
            {
                return response('STORE:'.$slug.'|'.request()->getHost());
            }

            public function unsubscribeInfo($slug)
            {
                return response('UNSUB:'.$slug.'|'.request()->getHost());
            }
        });
    }

    /** @return array{0: User, 1: Store} */
    private function store(string $slug = ''): array
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => $slug !== '' ? $slug : 's'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
        ]);

        return [$user, $store];
    }

    private function custom(Store $store, string $host, bool $verified = true): StoreDomain
    {
        return StoreDomain::create([
            'store_id' => $store->id, 'type' => 'custom', 'host' => $host,
            'verified_at' => $verified ? now() : null, 'verification_token' => 'tok-'.Str::random(6),
        ]);
    }

    // ---- slice 1: table + model ---------------------------------------------------------------------

    public function test_first_verified_custom_domain_becomes_primary_and_only_one_primary_per_store(): void
    {
        [, $store] = $this->store();
        $a = $this->custom($store, 'menu.alpha.test');
        $b = $this->custom($store, 'menu.beta.test');

        $this->assertTrue($a->fresh()->is_primary);
        $this->assertFalse($b->fresh()->is_primary);

        StoreDomains::makePrimary($b->fresh());
        $this->assertSame([$b->id], StoreDomain::where('store_id', $store->id)->where('is_primary', true)->pluck('id')->all());

        StoreDomains::usePathAsPrimary($store->id);
        $this->assertSame(0, StoreDomain::where('store_id', $store->id)->where('is_primary', true)->count());
        $this->assertSame('https://localhost/'.$store->slug, store_url($store));
    }

    public function test_unverified_domain_cannot_be_primary_and_legacy_attributes_still_work(): void
    {
        [, $store] = $this->store();
        $d = StoreDomain::create(['store_id' => $store->id, 'domain' => 'MENU.Legacy.Test.', 'verification_token' => 't', 'verified' => false]);

        $this->assertSame('menu.legacy.test', $d->host);
        $this->assertSame('menu.legacy.test', $d->toArray()['domain']);
        $this->assertFalse($d->toArray()['verified']);
        $this->assertFalse($d->is_primary);

        $this->expectException(\InvalidArgumentException::class);
        StoreDomains::makePrimary($d);
    }

    public function test_subdomain_claim_respects_reserved_labels_and_is_not_auto_primary(): void
    {
        [, $store] = $this->store();
        $this->assertSame('reserved', StoreDomains::subdomainProblem('admin'));
        $this->assertSame('reserved', StoreDomains::subdomainProblem('www'));
        $this->assertSame('invalid', StoreDomains::subdomainProblem('-bad'));

        $row = StoreDomains::claimSubdomain($store, 'pizza');
        $this->assertSame('pizza.localhost', $row->host);
        $this->assertTrue($row->isVerified());
        $this->assertFalse($row->is_primary);
        $this->assertSame('taken', StoreDomains::subdomainProblem('pizza'));
    }

    public function test_migration_round_trip_keeps_every_domain(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->markTestSkipped('DDL inside the RefreshDatabase transaction commits implicitly on MySQL.');
        }
        [, $store] = $this->store();
        $this->custom($store, 'kept.example.test');
        $this->custom($store, 'pending.example.test', false);
        $migration = require base_path('app/Modules/Storefront/Database/Migrations/2026_10_01_126100_create_store_domains_drop_restaurant_domains.php');

        $migration->down();
        $this->assertSame(2, DB::table('restaurant_domains')->count());
        $migration->up();

        $this->assertFalse(DB::getSchemaBuilder()->hasTable('restaurant_domains'));
        $rows = StoreDomain::where('store_id', $store->id)->orderBy('host')->get();
        $this->assertSame(['kept.example.test', 'pending.example.test'], $rows->pluck('host')->all());
        $this->assertTrue($rows[0]->verified && $rows[0]->is_primary);
        $this->assertFalse($rows[1]->verified || $rows[1]->is_primary);
    }

    // ---- takeover guards ------------------------------------------------------------------------------

    public function test_platform_hosts_and_their_subdomains_cannot_be_registered_as_custom_domains(): void
    {
        [$user, $store] = $this->store();
        $this->assertSame('platform', StoreDomains::customHostProblem('localhost.'));
        $this->assertSame('platform', StoreDomains::customHostProblem('victim.localhost'));
        $this->assertSame('invalid', StoreDomains::customHostProblem('not a host'));

        [, $other] = $this->store();
        $this->custom($other, 'menu.victim.test');
        $this->assertSame('taken', StoreDomains::customHostProblem('www.menu.victim.test'));

        $this->actingAs($user)->postJson("/stores/{$store->id}/domains", ['domain' => 'menu.victim.test'])->assertStatus(409);
    }

    public function test_unverified_custom_domain_never_serves_the_store(): void
    {
        [, $store] = $this->store();
        $this->custom($store, 'squat.example.test', false);

        $this->assertNull(app(StoreAddressResolver::class)->domainForHost('squat.example.test'));
        $res = $this->get('https://squat.example.test/');
        $this->assertFalse(str_starts_with((string) $res->getContent(), 'STORE:'), 'the platform answers, never the store');

        config(['hosts.mode' => 'split', 'hosts.storefront_url' => 'https://shop.localhost', 'hosts.admin_url' => 'https://admin.localhost', 'hosts.marketing_url' => 'https://www.localhost']);
        $this->get('https://squat.example.test/')->assertNotFound();
        $this->get('https://nobody.shop.localhost/')->assertNotFound();
    }

    public function test_dns_proof_needs_the_stores_txt_token_by_default(): void
    {
        $verifier = new class extends DomainVerifier
        {
            /** @var array<int, list<array<string, string>>> */
            public array $dns = [];

            protected function records(string $domain, int $type): array
            {
                return $this->dns[$type] ?? [];
            }
        };

        $verifier->dns = [DNS_CNAME => [['target' => 'localhost.']]];
        $this->assertFalse($verifier->verify('menu.x.test', 'tok-1'), 'a CNAME alone does not say which store owns the host');

        config(['hosts.domain_verification' => 'cname_or_txt']);
        $this->assertTrue($verifier->verify('menu.x.test', 'tok-1'));
        $verifier->dns = [DNS_CNAME => [['target' => 'localhost.attacker.test']]];
        $this->assertFalse($verifier->verify('menu.x.test', 'tok-1'), 'CNAME target must equal a platform host');

        config(['hosts.domain_verification' => 'txt']);
        $verifier->dns = [DNS_TXT => [['txt' => 'tok-1']]];
        $this->assertTrue($verifier->verify('menu.x.test', 'tok-1'));
        $this->assertFalse($verifier->verify('menu.x.test', 'tok-2'));
        // BL-036 R2: the TXT value must BE the token; one that merely contains it proves nothing.
        $verifier->dns = [DNS_TXT => [['txt' => 'site-verify=tok-1']]];
        $this->assertFalse($verifier->verify('menu.x.test', 'tok-1'));
    }

    // ---- slice 2: resolver, store_url, 301s ------------------------------------------------------------

    public function test_store_url_uses_the_primary_address_and_cannot_change_host(): void
    {
        [, $store] = $this->store();
        $this->assertSame('https://localhost/'.$store->slug.'/bio', store_url($store, 'bio'));
        $this->assertSame('https://localhost/'.$store->slug, effective_public_url($store));

        $this->custom($store, 'menu.primary.test');
        $this->assertSame('https://menu.primary.test', store_url($store));
        $this->assertSame('https://menu.primary.test/bio?src=qr', store_url($store->id, '/bio?src=qr'));
        $this->assertSame('https://menu.primary.test/evil.test/x', store_url($store, '//evil.test/x'));
        $this->assertSame('https://menu.primary.test/x', store_url($store, 'https://evil.test/x'));
        $this->assertSame('https://menu.primary.test', effective_public_url($store));
        $this->assertSame('https://menu.primary.test/bio?x=1', store_primary_url($store->id, 'https://localhost/'.$store->slug.'/bio?x=1'));
    }

    public function test_custom_domain_serves_every_storefront_route(): void
    {
        [, $store] = $this->store();
        $this->custom($store, 'menu.deep.test');

        $this->get('https://menu.deep.test/')->assertOk()->assertSee('STORE:'.$store->slug.'|menu.deep.test', false);
        $this->get('https://menu.deep.test/unsubscribe')->assertOk()->assertSee('UNSUB:'.$store->slug.'|menu.deep.test', false);
        // Links built with route('…', $slug) on the store host keep working as they are.
        $this->get('https://menu.deep.test/'.$store->slug.'/unsubscribe')->assertOk()->assertSee('UNSUB:', false);
        $this->call('HEAD', 'https://menu.deep.test/')->assertOk();
    }

    public function test_non_primary_addresses_301_to_the_primary_keeping_path_and_query(): void
    {
        [, $store] = $this->store();
        $this->custom($store, 'menu.main.test');
        $this->custom($store, 'menu.second.test');

        $this->get('https://localhost/'.$store->slug.'?table=5')->assertStatus(301)->assertHeader('Location', 'https://menu.main.test/?table=5');
        $this->get('https://localhost/'.$store->slug.'/unsubscribe?a=b')->assertStatus(301)->assertHeader('Location', 'https://menu.main.test/unsubscribe?a=b');
        $this->get('https://www.menu.main.test/unsubscribe')->assertStatus(301)->assertHeader('Location', 'https://menu.main.test/unsubscribe');
        $this->get('https://menu.second.test/')->assertStatus(301)->assertHeader('Location', 'https://menu.main.test');
        $this->get('https://localhost/restaurant/'.$store->slug.'/some-title')->assertStatus(301)->assertHeader('Location', 'https://menu.main.test');

        // The primary itself, POSTs and signed-in dashboard users are not redirected.
        $this->get('https://menu.main.test/?table=5')->assertOk();
        $this->assertNotSame(301, $this->post('https://localhost/'.$store->slug.'/unsubscribe')->getStatusCode());
    }

    public function test_redirect_target_host_is_always_the_primary(): void
    {
        [$owner, $store] = $this->store();
        $this->custom($store, 'menu.safe.test');

        foreach (['?next=https://evil.test', '?%0d%0aLocation:%20https://evil.test', '/unsubscribe?u=//evil.test'] as $tail) {
            $res = $this->get('https://localhost/'.$store->slug.$tail);
            $res->assertStatus(301);
            $this->assertSame('menu.safe.test', parse_url((string) $res->headers->get('Location'), PHP_URL_HOST), $tail);
        }

        $this->actingAs($owner)->get('https://localhost/'.$store->slug)->assertOk();
    }

    public function test_path_form_is_primary_without_domains_and_a_subdomain_redirects_to_it(): void
    {
        [, $store] = $this->store();
        $this->get('https://localhost/'.$store->slug)->assertOk()->assertSee('STORE:'.$store->slug, false);

        StoreDomains::claimSubdomain($store, 'tacos');
        $this->get('https://tacos.localhost/unsubscribe')->assertStatus(301)->assertHeader('Location', 'https://localhost/'.$store->slug.'/unsubscribe');

        StoreDomains::makePrimary(StoreDomain::where('host', 'tacos.localhost')->firstOrFail());
        $this->get('https://tacos.localhost/unsubscribe')->assertOk()->assertSee('UNSUB:', false);
        $this->get('https://localhost/'.$store->slug)->assertStatus(301)->assertHeader('Location', 'https://tacos.localhost');
    }

    public function test_owner_can_switch_the_primary_address(): void
    {
        [$owner, $store] = $this->store();
        $a = $this->custom($store, 'menu.one.test');
        $b = $this->custom($store, 'menu.two.test');
        $pending = $this->custom($store, 'menu.three.test', false);

        $this->actingAs($owner)->postJson("/stores/{$store->id}/domains/{$b->id}/primary")->assertOk()->assertJsonPath('url', 'https://menu.two.test');
        $this->assertTrue($b->fresh()->is_primary);
        $this->assertFalse($a->fresh()->is_primary);

        $this->actingAs($owner)->postJson("/stores/{$store->id}/domains/{$pending->id}/primary")->assertStatus(422);
        $this->actingAs($owner)->postJson("/stores/{$store->id}/domains/path/primary")->assertOk()->assertJsonPath('url', 'https://localhost/'.$store->slug);
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat;

use App\Http\Middleware\Installed;
use App\Http\Middleware\TrustHosts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Http\Hosts\HostMap;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\LegacyCompat\Import\RowLoader;
use Juwri\Modules\LegacyCompat\Providers\LegacyCompatServiceProvider;
use Juwri\Modules\LegacyCompat\Support\LegacyHosts;
use Juwri\Modules\Storefront\Http\Controllers\TlsAskController;
use Juwri\Modules\Storefront\Http\Middleware\ResolveStoreHost;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Juwri\Modules\Storefront\Services\StoreAddressResolver;
use Juwri\Modules\Storefront\Services\StoreDomains;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * J52b M8 (plan §3, Q13): the old platform's hosts through the J28 host map and store resolver. Synthetic hosts only.
 * Off = no change; on = the configured host is a storefront host and ONLY importer-written store subdomains under it
 * resolve, each to its own store (never another account's).
 */
class LegacyHostsTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY = 'old-platform.test';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://app.example.test',
            'hosts.mode' => 'split',
            'hosts.app_url' => 'https://app.example.test',
            'hosts.admin_url' => 'https://admin.example.test',
            'hosts.marketing_url' => 'https://example.test',
            'hosts.storefront_url' => 'https://shops.example.test',
            'legacy_compat.hosts' => [self::LEGACY, 'APP.example.test', 'not a host'],
        ]);
    }

    private function on(): void
    {
        config(['legacy_compat.enabled' => true]);
        LegacyCompatServiceProvider::activate($this->app);
        app(StoreAddressResolver::class)->flush();
    }

    private function store(): Store
    {
        $owner = User::factory()->create(['status' => 1]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'store-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Store',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123',
        ]);
    }

    /** A store_domains row like the importer writes it (unverified), optionally recorded in the import side table. */
    private function domainRow(Store $store, string $host, bool $imported): int
    {
        $id = (int) DB::table('store_domains')->insertGetId([
            'account_id' => $store->account_id, 'store_id' => $store->id, 'type' => 'subdomain', 'host' => $host,
            'is_primary' => false, 'verified_at' => null, 'verification_token' => 'tok', 'ssl_status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($imported) {
            DB::table(RowLoader::SIDE_TABLE)->insert([
                'source_table' => 'synthetic_domains', 'source_id' => (string) $id, 'target_table' => 'store_domains',
                'target_id' => (string) $id, 'source_checksum' => str_repeat('0', 64), 'run_id' => 'test-run', 'created_at' => now(),
            ]);
        }

        return $id;
    }

    private function through(string $url): string
    {
        $response = app(ResolveStoreHost::class)->handle(Request::create($url), static fn (Request $r) => response(
            $r->attributes->get(HostMap::STORE_ATTRIBUTE, 'none').'|'.$r->getPathInfo()
        ));

        return (string) $response->getContent();
    }

    public function test_off_the_configured_host_is_nothing(): void
    {
        LegacyHosts::register(); // even registered (an earlier test's activation), the sources re-check the switch
        $this->assertSame([], LegacyHosts::platformHosts());
        $this->assertSame([], HostMap::storefrontAliases());
        $this->assertSame(HostMap::UNKNOWN, HostMap::kind(self::LEGACY));
        $this->assertFalse(TlsAskController::serves(self::LEGACY, app(StoreAddressResolver::class)));
        $this->assertNull(StoreDomains::customHostProblem('shop.'.self::LEGACY));

        $store = $this->store();
        $this->domainRow($store, 'shop.'.self::LEGACY, true);
        $this->assertNull(app(StoreAddressResolver::class)->domainForHost('shop.'.self::LEGACY));
        $this->expectException(NotFoundHttpException::class);
        $this->through('https://shop.'.self::LEGACY.'/');
    }

    public function test_on_the_host_is_a_storefront_host_and_invalid_or_own_hosts_are_dropped(): void
    {
        $this->on();

        $this->assertSame([self::LEGACY], LegacyHosts::platformHosts()); // own app host and junk dropped
        $this->assertSame(HostMap::STOREFRONT, HostMap::kind(self::LEGACY));
        $this->assertSame(HostMap::STOREFRONT, HostMap::kind('www.'.self::LEGACY));
        $this->assertSame(HostMap::APP, HostMap::kind('app.example.test'));
        $this->assertTrue(TlsAskController::serves(self::LEGACY, app(StoreAddressResolver::class)));
        $this->assertTrue(TlsAskController::serves('www.'.self::LEGACY, app(StoreAddressResolver::class)));
        // An owner of this install can never claim a name under the old platform host.
        $this->assertSame('platform', StoreDomains::customHostProblem('shop.'.self::LEGACY));
        // Path-form pages on the host itself pass through unchanged (no store attribute, no 404).
        $this->assertSame('none|/some-store', $this->through('https://'.self::LEGACY.'/some-store'));
    }

    public function test_an_imported_store_subdomain_resolves_to_its_own_store_only(): void
    {
        $this->on();
        $mine = $this->store();
        $this->domainRow($mine, 'shop.'.self::LEGACY, true);

        $row = app(StoreAddressResolver::class)->domainForHost('SHOP.'.self::LEGACY.'.');
        $this->assertNotNull($row);
        $this->assertSame((int) $mine->id, (int) $row->store_id);
        $this->assertSame($mine->id.'|/'.$mine->slug, $this->through('https://shop.'.self::LEGACY.'/'));
        $this->assertTrue(TlsAskController::serves('shop.'.self::LEGACY, app(StoreAddressResolver::class)));
        // Unverified rows still never count as a verified custom domain anywhere else.
        $this->assertFalse(StoreDomain::query()->withoutGlobalScopes()->whereKey($row->id)->first()->isVerified());
    }

    public function test_a_row_the_importer_did_not_write_never_resolves_cross_tenant(): void
    {
        $this->on();
        $victim = $this->store();
        $attacker = $this->store();
        $this->assertNotSame($victim->account_id, $attacker->account_id);
        $this->domainRow($victim, 'victim.'.self::LEGACY, true);
        // Another account's row on a legacy subdomain (e.g. requested before the host was configured): not imported.
        $this->domainRow($attacker, 'grab.'.self::LEGACY, false);

        $this->assertNull(app(StoreAddressResolver::class)->domainForHost('grab.'.self::LEGACY));
        $this->assertSame($victim->id.'|/'.$victim->slug, $this->through('https://victim.'.self::LEGACY.'/'));
        // Deeper names, `www` and unknown labels are never a store.
        $this->assertNull(LegacyHosts::importedStoreDomainId('a.victim.'.self::LEGACY));
        $this->assertNull(LegacyHosts::importedStoreDomainId('www.'.self::LEGACY));
        $this->expectException(NotFoundHttpException::class);
        $this->through('https://grab.'.self::LEGACY.'/');
    }

    public function test_an_imported_row_resolves_only_under_a_configured_host(): void
    {
        $this->on();
        $store = $this->store();
        $this->domainRow($store, 'shop.elsewhere.test', true); // imported custom domain: must verify first
        $this->assertNull(app(StoreAddressResolver::class)->domainForHost('shop.elsewhere.test'));

        $this->domainRow($store, 'shop.'.self::LEGACY, true);
        config(['legacy_compat.hosts' => []]); // host removed from config: fail closed
        app(StoreAddressResolver::class)->flush();
        $this->assertNull(app(StoreAddressResolver::class)->domainForHost('shop.'.self::LEGACY));
        $this->assertSame(HostMap::UNKNOWN, HostMap::kind(self::LEGACY));
    }

    public function test_single_mode_the_host_is_a_storefront_host_without_dashboard_and_unknown_names_404(): void
    {
        config(['hosts.mode' => 'single', 'app.url' => 'http://localhost', 'hosts.app_url' => 'http://localhost']);
        $this->on();

        $this->assertSame(HostMap::STOREFRONT, HostMap::kind(self::LEGACY));
        $this->assertSame(HostMap::APP, HostMap::kind('localhost'));
        $this->assertSame('none|/some-store', $this->through('http://'.self::LEGACY.'/some-store'));
        // The dashboard / sign-in never answers on the old host, even in single-host mode.
        $this->withoutMiddleware([Installed::class]);
        $this->get('http://'.self::LEGACY.'/login')->assertNotFound();
        $this->assertNotSame(404, $this->get('http://localhost/login')->getStatusCode());

        $store = $this->store();
        $this->domainRow($store, 'shop.'.self::LEGACY, true);
        $this->assertSame($store->id.'|/'.$store->slug, $this->through('http://shop.'.self::LEGACY.'/'));
        foreach (['http://nobody.'.self::LEGACY.'/', 'http://a.shop.'.self::LEGACY.'/login'] as $url) {
            try {
                $this->through($url);
                $this->fail($url.' served the app');
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_trusted_host_patterns_cover_one_label_only(): void
    {
        $this->on();
        $patterns = HostMap::storefrontAliasPatterns();
        $this->assertCount(1, $patterns);
        $match = static fn (string $host): bool => preg_match('{'.$patterns[0].'}i', $host) === 1;
        foreach ([self::LEGACY, 'www.'.self::LEGACY, 'shop.'.self::LEGACY] as $ok) {
            $this->assertTrue($match($ok), $ok);
        }
        foreach (['a.b.'.self::LEGACY, 'evil'.self::LEGACY, self::LEGACY.'.evil.test'] as $bad) {
            $this->assertFalse($match($bad), $bad);
        }
        $this->assertContains($patterns[0], (new TrustHosts($this->app))->hosts());
    }

    public function test_a_configured_host_that_a_store_verified_here_is_refused(): void
    {
        $store = $this->store();
        DB::table('store_domains')->insert([
            'account_id' => $store->account_id, 'store_id' => $store->id, 'type' => 'custom', 'host' => 'www.'.self::LEGACY,
            'is_primary' => true, 'verified_at' => now(), 'verification_token' => 't', 'ssl_status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->on();

        $this->assertSame([], LegacyHosts::platformHosts());
        $this->assertSame(HostMap::UNKNOWN, HostMap::kind(self::LEGACY));
    }

    public function test_imported_pending_domains_get_the_longer_grace(): void
    {
        $this->on();
        config(['hosts.pending_grace_hours' => 72, 'legacy_compat.import.domain_grace_hours' => 2160]);
        $a = $this->store();
        $imported = $this->domainRow($a, 'imported.example', true);
        $native = $this->domainRow($a, 'native.example', false);
        DB::table('store_domains')->whereIn('id', [$imported, $native])->update(['type' => 'custom', 'created_at' => now()->subHours(100)]);

        $this->assertSame('taken', StoreDomains::customHostProblem('imported.example'));
        $this->assertNull(StoreDomains::customHostProblem('native.example'));

        config(['legacy_compat.enabled' => false]); // module off: the default grace again
        $this->assertNull(StoreDomains::customHostProblem('imported.example'));
    }

    public function test_a_later_boot_drops_the_registered_host_sources(): void
    {
        $this->on();
        $this->assertSame([self::LEGACY], HostMap::storefrontAliases());

        // The next application boots the provider again (switch off): the process-wide sources are gone, so even a
        // switch flipped back on without a new activation registers nothing.
        config(['legacy_compat.enabled' => false]);
        (new LegacyCompatServiceProvider($this->app))->boot();
        config(['legacy_compat.enabled' => true]);
        $this->assertSame([], HostMap::storefrontAliases());
        $this->assertNull(HostMap::resolveExtraStoreHost('shop.'.self::LEGACY));
        $this->assertNull(HostMap::pendingGraceHours(1));
    }
}

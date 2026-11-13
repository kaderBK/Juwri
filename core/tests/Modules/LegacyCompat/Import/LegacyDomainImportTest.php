<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat\Import;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacySourceFixture as Source;
use Juwri\Modules\LegacyCompat\Providers\LegacyCompatServiceProvider;
use Juwri\Modules\LegacyCompat\Support\LegacySlugConflicts;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Juwri\Modules\Storefront\Services\StoreAddressResolver;
use Juwri\Modules\Storefront\Services\StoreDomains;
use Tests\TestCase;

/**
 * J52b M8 (plan §3, R4, R5, Q9): store domains, the old platform's store subdomains and reseller hosts in
 * `legacy:import`, against a SYNTHETIC source (J19). Domains land unverified on their own store and account, conflicts
 * are refused and reported, reseller hosts are listed, shadowed slugs are stored for the route table.
 */
class LegacyDomainImportTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'legacy_test_source';

    private const LEGACY = 'old-platform.test';

    private Source $src;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.'.self::SOURCE => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'legacy_compat.enabled' => true,
            'legacy_compat.import.enabled' => true,
            'legacy_compat.cutover.owner_signoff' => true,
            'legacy_compat.import.target_host' => 'app.example.test',
            'legacy_compat.import.source_timezone' => 'UTC',
            'legacy_compat.import.source_app_key' => '',
            'legacy_compat.hosts' => [self::LEGACY],
            'app.url' => 'https://app.example.test',
            'hosts.mode' => 'split',
            'hosts.app_url' => 'https://app.example.test',
            'hosts.admin_url' => 'https://admin.example.test',
            'hosts.marketing_url' => 'https://example.test',
            'hosts.storefront_url' => 'https://shops.example.test',
        ]);
        LegacyCompatServiceProvider::activate($this->app);
        DB::purge(self::SOURCE);
        $this->src = (new Source(DB::connection(self::SOURCE)))->createSchema();
        $this->src->platformOption('currency_code', 'USD');
        $s = $this->src;
        $s->user(1);
        $s->user(2);
        $s->store(10, 1, ['slug' => 'first-store']);
        $s->store(20, 2, ['slug' => 'admin']); // reserved word: kept, listed as a slug conflict
    }

    protected function tearDown(): void
    {
        DB::purge(self::SOURCE);
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function import(array $args = []): array
    {
        $exit = Artisan::call('legacy:import', ['--source' => self::SOURCE] + $args);
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $report = json_decode($output, true);
        $this->assertIsArray($report, $output);

        return $report;
    }

    /** @return array<string, mixed>|null */
    private function row(string $host): ?array
    {
        $row = DB::table('store_domains')->where('host', $host)->first();

        return $row === null ? null : (array) $row;
    }

    public function test_domains_land_unverified_on_their_own_store_and_account(): void
    {
        $this->src->domain(31, 10, 'Menu.First-Store.Example.', ['verification_token' => 'tok-kept-0123456789abcdef']);
        $this->src->domain(32, 10, 'shop.'.self::LEGACY);
        $this->src->domain(41, 20, 'www.second.example');

        $report = $this->import();

        $first = $this->row('menu.first-store.example');
        $this->assertNotNull($first, 'host normalised (lower case, no trailing dot)');
        $this->assertSame(31, (int) $first['id']);
        $this->assertSame(10, (int) $first['store_id']);
        $this->assertSame((int) DB::table('stores')->where('id', 10)->value('account_id'), (int) $first['account_id']);
        $this->assertSame('custom', $first['type']);
        $this->assertNull($first['verified_at'], 'verified state is never copied');
        $this->assertSame('pending', $first['ssl_status']);
        $this->assertFalse((bool) $first['is_primary']);
        $this->assertSame('tok-kept-0123456789abcdef', $first['verification_token'], 'strong token of a source-verified row is kept');

        $sub = $this->row('shop.'.self::LEGACY);
        $this->assertSame('subdomain', $sub['type']);
        $this->assertNull($sub['verified_at']);

        $second = $this->row('www.second.example');
        $this->assertSame(20, (int) $second['store_id']);
        $this->assertNotSame($first['account_id'], $second['account_id']);

        $this->assertSame([
            ['store_id' => 10, 'host' => 'menu.first-store.example', 'type' => 'custom'],
            ['store_id' => 10, 'host' => 'shop.'.self::LEGACY, 'type' => 'subdomain'],
            ['store_id' => 20, 'host' => 'www.second.example', 'type' => 'custom'],
        ], $report['domains_to_verify']);

        // Custom domains serve nothing until this install's DNS proof passes; the old platform subdomain resolves to
        // its own store at once (the old platform host is this install's now).
        $resolver = app(StoreAddressResolver::class);
        $resolver->flush();
        $this->assertNull($resolver->domainForHost('menu.first-store.example'));
        $this->assertSame(10, (int) $resolver->domainForHost('shop.'.self::LEGACY)?->store_id);
        StoreDomains::markVerified(StoreDomain::query()->withoutGlobalScopes()->findOrFail(31));
        $resolver->flush();
        $this->assertSame(10, (int) $resolver->domainForHost('menu.first-store.example')?->store_id);
    }

    public function test_conflicting_and_invalid_hosts_are_refused_and_reported(): void
    {
        $this->src->reseller(5, 'Partner.Example');
        $this->src->reseller(6, 'app.example.test'); // a platform host of this install
        $s = $this->src;
        $s->domain(31, 10, 'menu.shared.example');
        $s->domain(41, 20, 'www.menu.shared.example', ['verified' => false]); // unverified twin of store 10's: refused
        $s->domain(42, 20, 'partner.example');           // the source's reseller host: refused
        $s->domain(43, 20, self::LEGACY);                 // the old platform host itself
        $s->domain(44, 20, 'a.b.'.self::LEGACY);          // deeper than one store label
        $s->domain(45, 20, 'www.'.self::LEGACY);          // the host's own twin
        $s->domain(46, 20, 'store.app.example.test');     // under this install's app host
        $s->domain(47, 20, 'not a host');
        $s->domain(48, 20, 'admin.shops.example.test');   // reserved label under this install's storefront host
        $s->domain(49, 20, 'second.shops.example.test');  // a storefront subdomain: allowed, unverified

        $report = $this->import();

        $this->assertSame(['menu.shared.example', 'second.shops.example.test'], DB::table('store_domains')->orderBy('id')->pluck('host')->all());
        $this->assertSame('subdomain', $this->row('second.shops.example.test')['type']);
        $this->assertNull($this->row('second.shops.example.test')['verified_at']);
        $this->assertSame(8, $report['tables']['store_domains']['skipped']);
        $conflicts = implode("\n", array_keys($report['conflicts']));
        foreach (['host on several source stores, the source-verified store#10 wins', 'a reseller host of the source (reseller#5)', 'the old platform host itself',
            'not a single store label under the old platform host', 'a host of this install', 'not a valid host name',
            'reserved subdomain label'] as $why) {
            $this->assertStringContainsString('store_domains: host refused ('.$why.')', $conflicts);
        }

        $this->assertSame([
            ['reseller_id' => 5, 'host' => 'partner.example', 'active' => true, 'status' => 'listed: import with the reseller (not by this importer); re-verify on this install', 'kind' => 'custom_domain'],
            ['reseller_id' => 6, 'host' => 'app.example.test', 'active' => true, 'status' => 'refused: a platform host', 'kind' => 'custom_domain'],
        ], $report['reseller_hosts']);
        $this->assertContains('resellers', $report['unmapped_source_tables']);
    }

    public function test_reseller_subdomains_under_the_legacy_host_and_requested_domains_are_protected(): void
    {
        $schema = DB::connection(self::SOURCE)->getSchemaBuilder();
        $schema->table(Source::RESELLERS_TABLE, fn ($t) => $t->string('subdomain', 63)->nullable());
        $schema->table(Source::RESELLERS_TABLE, fn ($t) => $t->string('requested_domain', 120)->nullable());
        $this->src->reseller(5, null, ['subdomain' => 'Partner', 'requested_domain' => 'pending-partner.example']);
        $this->src->reseller(6, null, ['subdomain' => 'bad label!']);
        $this->src->domain(31, 10, 'partner.'.self::LEGACY);     // a store subdomain on the reseller's label: refused
        $this->src->domain(41, 20, 'pending-partner.example', ['verified' => false]); // requested by the reseller, unverified: refused
        $this->src->domain(42, 20, 'shop.'.self::LEGACY);         // another label: allowed

        $report = $this->import();

        $this->assertSame(['shop.'.self::LEGACY], DB::table('store_domains')->pluck('host')->all());
        $conflicts = implode("\n", array_keys($report['conflicts']));
        $this->assertStringContainsString('store_domains: host refused (a reseller host of the source (reseller#5))', $conflicts);
        $hosts = collect($report['reseller_hosts'])->keyBy('host');
        $this->assertSame('subdomain', $hosts['partner.'.self::LEGACY]['kind']);
        $this->assertStringStartsWith('listed:', $hosts['partner.'.self::LEGACY]['status']);
        $this->assertSame('requested_domain', $hosts['pending-partner.example']['kind']);
        $this->assertStringStartsWith('listed: requested', $hosts['pending-partner.example']['status']);
        $this->assertSame('refused: not a single subdomain label', $hosts['bad label!']['status']);
    }

    public function test_a_reseller_request_never_takes_a_domain_the_source_verified_for_a_store(): void
    {
        $schema = DB::connection(self::SOURCE)->getSchemaBuilder();
        $schema->table(Source::RESELLERS_TABLE, fn ($t) => $t->string('requested_domain', 120)->nullable());
        $this->src->reseller(5, null, ['requested_domain' => 'wanted.example']);
        $this->src->reseller(6, null, ['requested_domain' => 'other.example']);
        $this->src->domain(31, 10, 'wanted.example');                       // verified at the source: store 10 keeps it
        $this->src->domain(41, 20, 'other.example', ['verified' => false]); // unverified: the request protects it

        $report = $this->import();

        $this->assertSame(10, (int) $this->row('wanted.example')['store_id']);
        $this->assertNull($this->row('other.example'));
        $this->assertSame(['requested by reseller#5, kept by store#10 wanted.example'],
            $report['conflicts']['store_domains: host requested by a reseller, kept by the store the source verified it for']['samples']);
        $conflicts = implode("\n", array_keys($report['conflicts']));
        $this->assertStringContainsString('store_domains: host refused (requested by reseller#6 at the source (not verified for this store))', $conflicts);
    }

    public function test_a_reseller_subdomain_is_listed_only_while_no_legacy_host_is_configured(): void
    {
        config(['legacy_compat.hosts' => []]);
        $schema = DB::connection(self::SOURCE)->getSchemaBuilder();
        $schema->table(Source::RESELLERS_TABLE, fn ($t) => $t->string('subdomain', 63)->nullable());
        $this->src->reseller(5, null, ['subdomain' => 'partner']);

        $report = $this->import();

        $this->assertSame([['reseller_id' => 5, 'host' => 'partner', 'active' => true,
            'status' => 'listed: no legacy host configured (protected once one is)', 'kind' => 'subdomain']], $report['reseller_hosts']);
    }

    public function test_a_store_with_a_foreign_store_column_cannot_take_a_domain_cross_tenant(): void
    {
        // A domain row naming a store that is not imported (owner missing) never lands on another store.
        $this->src->store(30, 99, ['slug' => 'orphan']);
        $this->src->domain(51, 30, 'orphan.example');

        $report = $this->import();

        $this->assertNull($this->row('orphan.example'));
        $this->assertSame(0, (int) ($report['tables']['store_domains']['written'] ?? 0));
    }

    public function test_slug_conflicts_are_reported_and_stored_on_real_runs_only(): void
    {
        $dry = $this->import(['--dry-run' => true]);
        $this->assertSame([['store_id' => 20, 'slug' => 'admin', 'reason' => 'reserved word']], $dry['slug_conflicts']);
        $this->assertSame(0, DB::table(LegacySlugConflicts::TABLE)->count());
        $this->assertSame(0, DB::table('store_domains')->count());

        $real = $this->import();
        $this->assertSame([['store_id' => 20, 'slug' => 'admin', 'reason' => 'reserved word']], $real['slug_conflicts']);
        $this->assertSame('admin', DB::table('stores')->where('id', 20)->value('slug'), 'slug kept byte-for-byte');
        $this->assertSame(['admin'], DB::table(LegacySlugConflicts::TABLE)->pluck('slug')->all());
    }

    public function test_a_resume_is_not_blocked_by_a_domain_an_imported_store_added_meanwhile(): void
    {
        $this->src->store(30, 1, ['slug' => 'later-store']);
        $this->import(['--store' => ['10']]);
        // The owner of imported store 10 adds a domain on this install before the next run.
        DB::table('store_domains')->insert([
            'id' => 999999, 'account_id' => DB::table('stores')->where('id', 10)->value('account_id'), 'store_id' => 10,
            'type' => 'custom', 'host' => 'native.example', 'is_primary' => false, 'verified_at' => null,
            'verification_token' => 'site-verify-x', 'ssl_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->src->domain(31, 30, 'native.example');

        $report = $this->import();

        $this->assertSame(30, (int) DB::table('stores')->where('id', 30)->value('id'));
        $this->assertSame(10, (int) DB::table('store_domains')->where('host', 'native.example')->value('store_id'), 'the native row is kept');
        $this->assertStringContainsString('store_domains: host refused (already on this install)', implode("\n", array_keys($report['conflicts'])));
    }

    public function test_one_host_on_several_source_stores_goes_to_the_source_verified_row_only(): void
    {
        $s = $this->src;
        $s->store(30, 1, ['slug' => 'third-store']);
        // Store 10 (imported first) squatted the host without verifying; store 20 verified it.
        $s->domain(31, 10, 'contested.example', ['verified' => false, 'verification_token' => 'squatter-token-0123456789']);
        $s->domain(41, 20, 'www.contested.example', ['verified' => true, 'verification_token' => 'owner-token-0123456789ab']);
        // Nobody verified this one: neither store gets it.
        $s->domain(32, 10, 'nobody.example', ['verified' => false]);
        $s->domain(51, 30, 'nobody.example', ['verified' => false]);

        $report = $this->import();

        $this->assertNull($this->row('contested.example'));
        $this->assertSame(20, (int) $this->row('www.contested.example')['store_id']);
        $this->assertSame('owner-token-0123456789ab', $this->row('www.contested.example')['verification_token']);
        $this->assertSame(0, DB::table('store_domains')->where('host', 'nobody.example')->count());
        $conflicts = implode("\n", array_keys($report['conflicts']));
        $this->assertStringContainsString('host on several source stores, the source-verified store#20 wins', $conflicts);
        $this->assertStringContainsString('host on several source stores, none (or more than one) verified', $conflicts);
    }

    public function test_one_store_with_apex_and_www_keeps_its_host_whatever_the_verified_flags(): void
    {
        $s = $this->src;
        $s->domain(31, 10, 'both.example', ['verified' => true]);
        $s->domain(32, 10, 'www.both.example', ['verified' => true]);
        $s->domain(33, 10, 'neither.example', ['verified' => false]);
        $s->domain(34, 10, 'www.neither.example', ['verified' => false]);

        $report = $this->import();

        // The store keeps the host (the www. twin resolves through the apex row); the twin row is a same-store duplicate.
        $this->assertSame(10, (int) $this->row('both.example')['store_id']);
        $this->assertSame(10, (int) $this->row('neither.example')['store_id']);
        $this->assertNull($this->row('www.both.example'));
        $conflicts = implode("\n", array_keys($report['conflicts']));
        $this->assertStringContainsString('store_domains: host refused (duplicate of another domain of the same store)', $conflicts);
        $this->assertStringNotContainsString('several source stores', $conflicts);
    }

    public function test_sequences_move_past_source_ids_a_partial_run_did_not_import_yet(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('ALTER TABLE would commit the test transaction (the bump is reported as skipped inside one).');
        }
        $this->src->domain(31, 10, 'first.example');
        $this->src->domain(4100, 20, 'later.example');

        $report = $this->import(['--store' => ['10']]);

        $this->assertSame(4100 + 1000, $report['sequences']['store_domains']);
        // A domain an owner adds now can never take an id the next run still has to import.
        $native = (int) DB::table('store_domains')->insertGetId([
            'account_id' => DB::table('stores')->where('id', 10)->value('account_id'), 'store_id' => 10, 'type' => 'custom',
            'host' => 'native.example', 'is_primary' => false, 'verification_token' => 'site-verify-x', 'ssl_status' => 'pending',
        ]);
        $this->assertGreaterThan(4100, $native);
        $this->import();
        $this->assertSame(20, (int) $this->row('later.example')['store_id']);
    }

    public function test_source_tokens_are_kept_only_when_verified_and_strong(): void
    {
        $s = $this->src;
        $s->domain(31, 10, 'short.example', ['verified' => true, 'verification_token' => 'tok-1']);
        $s->domain(32, 10, 'unverified.example', ['verified' => false, 'verification_token' => 'strong-but-unproven-0123']);
        $s->domain(33, 10, 'odd.example', ['verified' => true, 'verification_token' => 'has spaces in it 0123456']);
        $s->domain(34, 10, 'kept.example', ['verified' => true, 'verification_token' => 'Proven_Token.0123456789']);

        $this->import();

        foreach (['short.example', 'unverified.example', 'odd.example'] as $host) {
            $this->assertMatchesRegularExpression('/^site-verify-[a-z0-9]{32}$/', (string) $this->row($host)['verification_token'], $host);
        }
        $this->assertSame('Proven_Token.0123456789', $this->row('kept.example')['verification_token']);
    }

    public function test_exact_hosts_of_this_install_are_refused_whatever_their_shape(): void
    {
        // An operator host one label under the storefront host (not a reserved label).
        config(['hosts.admin_url' => 'https://panel.shops.example.test']);
        $this->src->domain(31, 10, 'panel.shops.example.test');
        $this->src->domain(32, 10, 'www.example.test');

        $report = $this->import();

        $this->assertSame(0, DB::table('store_domains')->count());
        $this->assertSame(2, $report['conflicts']['store_domains: host refused (a host of this install)']['count']);
    }

    public function test_import_needs_an_empty_store_domains_table(): void
    {
        $this->src->domain(31, 10, 'menu.first-store.example');
        $this->import();
        // Resume: the rows this importer wrote do not block a second run.
        $again = $this->import();
        $this->assertSame(1, DB::table('store_domains')->count());
        $this->assertSame([], $again['domains_to_verify']);
    }
}

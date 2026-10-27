<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Juwri\Core\Models\Store;
use Juwri\Core\Support\ExternalRedirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A1 — unit coverage of the External Redirect target builder (§3.3 table, every
 * row) and the static URL validator (§3.2). The HTTP-through-controller behaviour
 * of resolve() is covered by the A2 menu-hook feature test.
 */
class ExternalRedirectResolverTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\DataProvider('targetRows')]
    public function test_build_target_query_forwarding(string $saved, string $slug, string $orig, string $expected): void
    {
        $this->assertSame($expected, ExternalRedirect::buildTarget($saved, $slug, $orig));
    }

    public static function targetRows(): array
    {
        return [
            // §3.3 table rows.
            'placeholder slug+query, with query' => ['https://legacy.x.com/{slug}{query}', 'pizza-hub', 'table=5', 'https://legacy.x.com/pizza-hub?table=5'],
            'placeholder slug+query, no query' => ['https://legacy.x.com/{slug}{query}', 'pizza-hub', '', 'https://legacy.x.com/pizza-hub'],
            'no placeholder, merge params' => ['https://me.com/menu?src=qr', 'pizza-hub', 'table=5', 'https://me.com/menu?src=qr&table=5'],
            'no placeholder, target param wins collision' => ['https://me.com/menu?table=9', 'pizza-hub', 'table=5', 'https://me.com/menu?table=9'],
            // Extra edges.
            'no placeholder, no query' => ['https://me.com/menu', 'pizza-hub', '', 'https://me.com/menu'],
            'slug-only placeholder' => ['https://me.com/{slug}', 'pizza-hub', '', 'https://me.com/pizza-hub'],
            'query placeholder alone merges nothing when empty' => ['https://me.com/x{query}', 'pizza-hub', '', 'https://me.com/x'],
            'fragment preserved on merge' => ['https://me.com/menu#top', 'pizza-hub', 'table=5', 'https://me.com/menu?table=5#top'],
        ];
    }

    public function test_validate_accepts_good_https(): void
    {
        $this->assertNull(ExternalRedirect::validate('https://legacy.example.com/{slug}{query}'));
        $this->assertNull(ExternalRedirect::validate('https://me.example.com/menu?src=qr'));
    }

    public function test_validate_rejects_non_https(): void
    {
        $this->assertNotNull(ExternalRedirect::validate('http://legacy.example.com/x'));
        $this->assertNotNull(ExternalRedirect::validate('ftp://legacy.example.com/x'));
    }

    public function test_validate_rejects_userinfo(): void
    {
        $this->assertNotNull(ExternalRedirect::validate('https://user:pass@legacy.example.com/x'));
    }

    public function test_validate_rejects_overlong_url(): void
    {
        $long = 'https://legacy.example.com/'.str_repeat('a', 2048); // > 2048 total
        $this->assertNotNull(ExternalRedirect::validate($long));
    }

    public function test_validate_rejects_empty_and_malformed(): void
    {
        $this->assertNotNull(ExternalRedirect::validate(''));
        $this->assertNotNull(ExternalRedirect::validate('not a url'));
        $this->assertNotNull(ExternalRedirect::validate('https://')); // no host
    }

    public function test_validate_rejects_platform_host(): void
    {
        // phpunit.xml sets APP_URL=https://localhost → platform host 'localhost'.
        $this->assertNotNull(ExternalRedirect::validate('https://localhost/anything'));
    }

    public function test_validate_rejects_verified_restaurant_domain_but_allows_unverified(): void
    {
        $a = Store::factory()->create();
        $b = Store::factory()->create();
        DB::table('store_domains')->insert([
            'account_id' => $a->account_id, 'store_id' => $a->id, 'type' => 'custom', 'host' => 'verified-store.example', 'verified_at' => now(), 'verification_token' => 'tok',
        ]);
        DB::table('store_domains')->insert([
            'account_id' => $b->account_id, 'store_id' => $b->id, 'type' => 'custom', 'host' => 'unverified-store.example', 'verified_at' => null, 'verification_token' => 'tok2',
        ]);

        $this->assertNotNull(ExternalRedirect::validate('https://verified-store.example/x'));
        $this->assertNull(ExternalRedirect::validate('https://unverified-store.example/x'));
    }

    public function test_read_path_caches_trusted_hosts_while_save_path_is_fresh(): void
    {
        $this->requiresEditionPackage('a table only an edition package creates');
        // Prime the read cache with the current (domain-less) trusted-host list.
        $this->assertNull(ExternalRedirect::validate('https://newdomain.example/x'));

        // A reseller custom domain that matches the target is added AFTER priming.
        DB::table('resellers')->insert([
            'owner_user_id' => 1, 'brand_name' => 'R', 'custom_domain' => 'newdomain.example', 'active' => true,
        ]);

        // The read/302 path reads the 60s cache — still allows it in this window
        // (acceptable: the URL was validated fresh at save; no per-hit DB queries).
        $this->assertNull(ExternalRedirect::validate('https://newdomain.example/x'));
        // The save path forces a fresh list and catches the new loop immediately.
        $this->assertNotNull(ExternalRedirect::validate('https://newdomain.example/x', true));
    }

    public function test_validate_rejects_active_reseller_domain_but_allows_inactive(): void
    {
        $this->requiresEditionPackage('a table only an edition package creates');
        DB::table('resellers')->insert([
            'owner_user_id' => 1, 'brand_name' => 'R', 'custom_domain' => 'reseller.example', 'active' => true,
        ]);
        DB::table('resellers')->insert([
            'owner_user_id' => 2, 'brand_name' => 'R2', 'custom_domain' => 'inactive-reseller.example', 'active' => false,
        ]);

        $this->assertNotNull(ExternalRedirect::validate('https://reseller.example/x'));
        $this->assertNull(ExternalRedirect::validate('https://inactive-reseller.example/x'));
    }
}

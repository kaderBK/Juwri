<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * AL-40 (visitor_country geo helper) + AL-41 (login-time preferred_locale sync).
 * Both ship privacy-first: geo is dark by default (no IP ever leaves the box) and
 * the locale copy is one-way, unset-only.
 */
class AutoLocaleBackendTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user, array $cookies = []): TestResponse
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $req = $this;
        foreach ($cookies as $k => $v) {
            $req = $req->withUnencryptedCookie($k, $v);
        }

        return $req->post(route('login'), ['username' => $user->email, 'password' => 'secret-pass']);
    }

    private function user(?string $locale): User
    {
        return User::factory()->create([
            'status' => 1, 'email_verified_at' => now(), 'password' => bcrypt('secret-pass'),
            'preferred_locale' => $locale,
        ]);
    }

    /* ---------------------------------------------------------- AL-41 */

    public function test_login_copies_qc_lang_choice_into_null_preferred_locale(): void
    {
        Language::updateOrCreate(['code' => 'fr'], ['name' => 'Français', 'direction' => 'ltr', 'active' => 1]);
        $user = $this->user(null);

        $this->login($user, ['qc_lang_choice' => 'fr']);

        $this->assertSame('fr', $user->fresh()->preferred_locale, 'unset locale takes the visitor choice');
    }

    public function test_login_never_overwrites_an_existing_preferred_locale(): void
    {
        Language::updateOrCreate(['code' => 'fr'], ['name' => 'Français', 'direction' => 'ltr', 'active' => 1]);
        $user = $this->user('en'); // already set

        $this->login($user, ['qc_lang_choice' => 'fr']);

        $this->assertSame('en', $user->fresh()->preferred_locale, 'one-way: an explicit account preference wins');
    }

    public function test_login_ignores_an_inactive_or_unknown_language_choice(): void
    {
        // 'zz' is not an active language row.
        $user = $this->user(null);

        $this->login($user, ['qc_lang_choice' => 'zz']);

        $this->assertNull($user->fresh()->preferred_locale, 'garbage cookie never lands');
    }

    /* ---------------------------------------------------------- AL-40 */

    public function test_visitor_country_is_null_when_geo_is_dark(): void
    {
        config(['geoip.trust_cf_country' => false, 'geoip.mmdb_path' => null]);
        $this->assertNull(visitor_country(), 'ships dark — no source configured');
    }

    public function test_cf_country_header_is_ignored_when_trust_is_off(): void
    {
        config(['geoip.trust_cf_country' => false]);
        $this->swapRequest(['HTTP_CF_IPCOUNTRY' => 'DE'], ['1.2.3.4']);
        $this->assertNull(visitor_country(), 'header alone is never trusted');
    }

    public function test_cf_country_header_is_ignored_when_not_from_a_trusted_proxy(): void
    {
        config(['geoip.trust_cf_country' => true]);
        // trust ON but the request did NOT arrive via a configured trusted proxy →
        // a direct client could have spoofed CF-IPCountry, so it must be rejected.
        $this->swapRequest(['HTTP_CF_IPCOUNTRY' => 'DE'], []);
        $this->assertNull(visitor_country(), 'spoof protection: untrusted hop → null');
    }

    public function test_cf_country_header_is_used_when_trusted(): void
    {
        config(['geoip.trust_cf_country' => true]);
        $this->swapRequest(['HTTP_CF_IPCOUNTRY' => 'DE', 'REMOTE_ADDR' => '10.0.0.9'], ['10.0.0.9']);
        $this->assertSame('DE', visitor_country());
    }

    public function test_cf_xx_and_t1_resolve_to_null(): void
    {
        config(['geoip.trust_cf_country' => true]);
        foreach (['XX', 'T1'] as $code) {
            $this->swapRequest(['HTTP_CF_IPCOUNTRY' => $code, 'REMOTE_ADDR' => '10.0.0.9'], ['10.0.0.9']);
            $this->assertNull(visitor_country(), "$code is not a country");
        }
    }

    public function test_missing_mmdb_file_is_geo_absent_not_fatal(): void
    {
        config(['geoip.trust_cf_country' => false, 'geoip.mmdb_path' => '/nonexistent/GeoLite2-Country.mmdb']);
        $this->assertNull(visitor_country());
    }

    /* ---------------------------------------------------------- AL-20 */

    private function country(string $code, string $currency): void
    {
        DB::table('countries')->insert([
            'code' => $code, 'name' => $code, 'currency' => $currency,
            'capital' => '', 'continent' => '', 'continent_code' => '',
            'phone' => '', 'symbol' => '', 'alpha_3' => $code.'X',
        ]);
    }

    public function test_currency_country_map_filters_to_offered_currencies_only(): void
    {
        // The countries table ships seeded (DE→EUR, GB→GBP, US→USD, …).
        $map = currency_country_map(['EUR', 'GBP']);

        // INVARIANT: every mapped currency is one the store offers.
        foreach ($map as $cc => $cur) {
            $this->assertContains($cur, ['EUR', 'GBP'], "$cc mapped to an unoffered currency $cur");
        }
        // Known offered-currency countries resolve.
        $this->assertSame('EUR', $map['DE'] ?? null);
        $this->assertSame('GBP', $map['GB'] ?? null);
        // A country whose currency is NOT offered (USD) is dropped.
        $this->assertArrayNotHasKey('US', $map, 'unoffered-currency country dropped');
    }

    public function test_currency_country_map_is_empty_for_no_offered_codes(): void
    {
        $this->assertSame([], currency_country_map([]), 'no offered currencies → empty map');
    }

    public function test_currency_country_map_normalizes_and_memoizes(): void
    {
        // Use a currency code absent from the seed so the set is deterministic.
        $this->country('ZZ', 'XTS');
        $first = currency_country_map(['xts', 'XTS']); // lower-case + dupe normalize
        $this->assertSame(['ZZ' => 'XTS'], $first);

        // A row added AFTER the first call must NOT appear → proves the memo is hit.
        $this->country('YY', 'XTS');
        $this->assertSame(['ZZ' => 'XTS'], currency_country_map(['XTS']), 'memoized per offered-set');
    }

    /* ------------------------------------ AL-42 follow-up: user_ip_lookup privacy */

    public function test_user_ip_lookup_never_caches_the_raw_ip(): void
    {
        // REGRESSION: the old body did Cache::forever($ip, ...) — indefinite PII
        // retention keyed by the raw visitor IP. The rewrite must never touch the cache.
        \Illuminate\Support\Facades\Cache::flush();
        user_ip_lookup('203.0.113.7');
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has('203.0.113.7'), 'raw IP must never be cached');
    }

    public function test_user_ip_lookup_is_geo_dark_by_default(): void
    {
        config(['geoip.trust_cf_country' => false, 'geoip.mmdb_path' => null]);
        $info = user_ip_lookup('203.0.113.7');

        // Everything the old ip-api leak used to provide is now a safe sentinel.
        $this->assertSame('Other', $info['location']['country']);
        $this->assertSame('Other', $info['location']['country_code']);
        $this->assertSame('Other', $info['location']['city']);
        $this->assertSame('Unknown', $info['location']['postal_code']);
    }

    public function test_user_ip_lookup_resolves_country_name_from_local_table(): void
    {
        config(['geoip.trust_cf_country' => true]);
        // countries ships seeded (DE row exists); pin its name for a deterministic assert.
        DB::table('countries')->where('code', 'DE')->update(['name' => 'Germany']);
        $this->swapRequest(['HTTP_CF_IPCOUNTRY' => 'DE', 'REMOTE_ADDR' => '10.0.0.9'], ['10.0.0.9']);

        $info = user_ip_lookup();

        $this->assertSame('DE', $info['location']['country_code'], 'code from visitor_country()');
        $this->assertSame('Germany', $info['location']['country'], 'name resolved LOCALLY, no third-party call');
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has('10.0.0.9'), 'still no raw-IP cache');
    }

    /** Bind a request carrying the given server vars, with the given trusted proxies. */
    private function swapRequest(array $server, array $trustedProxies): void
    {
        Request::setTrustedProxies($trustedProxies, Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST);
        $req = Request::create('/', 'GET', [], [], [], $server + ['REMOTE_ADDR' => '10.0.0.9']);
        $this->app->instance('request', $req);
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        parent::tearDown();
    }
}

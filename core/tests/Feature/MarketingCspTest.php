<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Content\Http\Middleware\CacheMarketingResponse;
use Tests\TestCase;

/**
 * P4-17 follow-up (ZAP: "CSP header not set" on the marketing pages, /login and /studio): they carry the enforced
 * nonce policy (csp.marketing: the dashboard policy, without marking the route a dashboard route). The marketing full-page cache stores a nonce placeholder and fills each hit with that
 * request's nonce, so a cached page always matches its header and no two visitors share a nonce.
 */
class MarketingCspTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketing_login_and_studio_routes_carry_the_csp_middleware(): void
    {
        foreach (['pricing', 'login', 'studio'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains('csp.marketing', $route->gatherMiddleware(), $name);
            $this->assertNotContains('csp.dashboard', $route->gatherMiddleware(), $name.': csp.dashboard would mark it a dashboard route (EnforceHostSplit)');
        }
    }

    public function test_the_login_page_is_served_with_an_enforced_nonce_policy_that_matches_its_body(): void
    {
        $this->withoutMiddleware([Installed::class]);
        $res = $this->get('/login')->assertOk();
        $policy = (string) $res->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src [^;]*'nonce-([^']+)'/", $policy);
        preg_match("/'nonce-([^']+)'/", $policy, $m);
        $this->assertStringContainsString('nonce="'.$m[1].'"', (string) $res->getContent());
    }

    public function test_a_cached_page_is_stored_with_a_placeholder_and_served_with_the_current_nonce(): void
    {
        $nonce = csp_nonce();
        $body = '<html><script nonce="'.$nonce.'">x()</script></html>';
        $mw = new CacheMarketingResponse;
        $store = new \ReflectionMethod($mw, 'safeToStore');
        $this->assertTrue($store->invoke($mw, $body));

        // what handle() stores on a miss and serves on a hit (the cache itself is off under the test runner)
        $stored = str_replace('nonce="'.$nonce.'"', 'nonce="'.CacheMarketingResponse::NONCE_PLACEHOLDER.'"', $body);
        $this->assertStringNotContainsString($nonce, $stored);
        app()->instance('csp-nonce', 'NEXTREQUESTNONCE');
        $this->assertSame('<html><script nonce="NEXTREQUESTNONCE">x()</script></html>', str_replace(CacheMarketingResponse::NONCE_PLACEHOLDER, csp_nonce(), $stored));
        $src = (string) file_get_contents(app_path('Modules/Content/Http/Middleware/CacheMarketingResponse.php'));
        $this->assertStringContainsString("str_replace(self::NONCE_PLACEHOLDER, csp_nonce(), (string) \$hit['body'])", $src);
        $this->assertStringContainsString("'nonce=\"'.self::NONCE_PLACEHOLDER.'\"'", $src);
    }
}

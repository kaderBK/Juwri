<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Http\Kernel;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P1-00a (Laravel 13): VerifyCsrfToken became PreventRequestForgery with a
 * Sec-Fetch-Site origin check. Gateways, voice providers and beacons POST
 * cross-site without a token, so every such route must stay in $except.
 *
 * The framework skips CSRF entirely while running unit tests, so these tests
 * run the middleware with that bypass switched off.
 */
class PreventRequestForgeryWebhookTest extends TestCase
{
    /** URI shapes that are called by third parties (no session, no token). */
    private const INBOUND = '#^(ipn|webhook|webhooks)/|^studio/stripe-webhook$|^voice/.+/incoming$#i';

    private function middleware(): PreventRequestForgery
    {
        return new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };
    }

    private function crossSitePost(string $uri): Request
    {
        $request = Request::create('/'.ltrim($uri, '/'), 'POST', [], [], [], [
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
            'CONTENT_TYPE' => 'application/json',
        ], '{}');
        $request->setLaravelSession($this->app['session']->driver('array'));

        return $request;
    }

    private function passes(Request $request): bool
    {
        try {
            $this->middleware()->handle($request, fn () => response('ok'));

            return true;
        } catch (TokenMismatchException) {
            return false;
        }
    }

    /** @return list<string> */
    private function inboundPostUris(): array
    {
        $uris = [];
        /** @var Route $route */
        foreach ($this->app['router']->getRoutes() as $route) {
            $uri = $route->uri();
            if (array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods()) === []) {
                continue;
            }
            if (! preg_match(self::INBOUND, $uri)) {
                continue;
            }
            // Only routes that actually run the web CSRF middleware matter.
            if (! in_array('web', $route->gatherMiddleware(), true)) {
                continue;
            }
            $uris[] = preg_replace('/\{[^}]+\}/', 'x', $uri);
        }

        return array_values(array_unique($uris));
    }

    public function test_web_group_runs_the_juwri_request_forgery_middleware(): void
    {
        $groups = $this->app->make(Kernel::class)->getMiddlewareGroups();

        $this->assertContains(PreventRequestForgery::class, $groups['web']);
    }

    public function test_every_gateway_webhook_and_callback_passes_without_a_token(): void
    {
        $uris = $this->inboundPostUris();
        $this->assertNotEmpty($uris, 'no inbound webhook routes found — route loading changed?');

        $blocked = array_values(array_filter($uris, fn (string $uri) => ! $this->passes($this->crossSitePost($uri))));

        $this->assertSame([], $blocked, 'cross-site POST without a CSRF token was refused (add to $except): '.implode(', ', $blocked));
    }

    public function test_each_listed_exception_still_passes(): void
    {
        $except = (fn () => $this->except)->call($this->middleware());

        foreach ($except as $pattern) {
            $uri = str_replace('*', 'stripe', $pattern);
            $this->assertTrue($this->passes($this->crossSitePost($uri)), "'{$pattern}' is refused");
        }
    }

    public function test_known_gateway_endpoints_pass(): void
    {
        foreach (['stripe', 'paypal', 'razorpay', 'paystack', 'paytm', 'payumoney', 'iyzico', 'paytabs', 'midtrans',
            'telr', 'ccavenue', 'mollie', 'two_checkout', 'tap', 'paymob', 'paylink', 'noon', 'fawry', 'tdsp'] as $gateway) {
            $this->assertTrue($this->passes($this->crossSitePost('ipn/'.$gateway)), "ipn/{$gateway}");
            $this->assertTrue($this->passes($this->crossSitePost('webhook/'.$gateway)), "webhook/{$gateway}");
        }
        $this->assertTrue($this->passes($this->crossSitePost('studio/stripe-webhook')));
        $this->assertTrue($this->passes($this->crossSitePost('webhooks/reseller/paddle')));
        $this->assertTrue($this->passes($this->crossSitePost('voice/'.Str::random(6).'/incoming')));
        $this->assertTrue($this->passes($this->crossSitePost('auth/social/customer/apple/callback')));
    }

    public function test_dashboard_post_without_token_is_refused_cross_site_and_allowed_same_origin(): void
    {
        $this->assertFalse($this->passes($this->crossSitePost('login')));

        $sameOrigin = $this->crossSitePost('login');
        $sameOrigin->headers->set('Sec-Fetch-Site', 'same-origin');
        $this->assertTrue($this->passes($sameOrigin), 'Laravel 13 accepts same-origin browser POSTs by Sec-Fetch-Site');

        $noHeader = $this->crossSitePost('login');
        $noHeader->headers->remove('Sec-Fetch-Site');
        $this->assertFalse($this->passes($noHeader));
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Http\Middleware\AddCspHeaders;
use Juwri\Core\Support\Csp\StudioSharePolicy;
use Tests\Browser\Support\Fixtures;
use Tests\TestCase;

/**
 * P6-122b: the HTML route groups that still had no CSP middleware after P6-122 carry one now. Storefront-side pages
 * (vertical cart/checkout/goods/booking, kiosk, deposits, hotel guest, order hub, theme preview) get the storefront
 * policy (AddCspHeaders); the payment views among them are replaced by their own payment policy at runtime
 * (AddPaymentPageCsp). App surfaces get csp.app, dashboard groups csp.dashboard, public docs/landing pages
 * csp.marketing, and the Studio share page a scriptless policy of its own.
 */
class RemainingSurfacesCspTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, list<string>> expected CSP middleware => GET URIs */
    private const GROUPS = [
        AddCspHeaders::class => [
            '{slug}/cart', '{slug}/checkout', '{slug}/goods/{menu}', '{slug}/book', '{slug}/book/schedule', '{slug}/wishlist',
            '{slug}/size-guide/{guide}', 'order/track/{token}/shipment', 'order/track/{token}/returns', '{slug}/kiosk',
            '{slug}/unsubscribe', '{slug}/reservation/deposit/{token}', '{slug}/catering/deposit/{token}',
            'restaurant/{slug}/{slug_title?}', 'restaurant/{storeId}/wallet/topup', 'feedback/{token}',
            'campaigns/unsubscribe/{customer}', 'hotel/{slug}/info', 'hotel/{slug}/folio/{token}', 'hotel/{slug}/book',
            'hotel/{slug}/booking/{token}', 'order-hub', 'order-hub/checkout', 'marketplace/{slug}/preview', 'studio/s/{token}',
        ],
        'csp.app' => [
            'billing/invoice/{invoice}', 'p/{token}', 'reseller-signup/{resellerId}', 'reseller/team/accept/{token}',
            'fusion', 'fusion/explore', 'fusion/biz/{slug}', 'fusion/store/{slug}', 'fusion/search', 'fusion/bag',
            'fusion/checkout', 'fusion/orders', 'fusion/account', 'fusion/wallet/pay/{transaction}', 'signage/{token}', 'board/{slug}',
        ],
        'csp.dashboard' => [
            'hotel/stays', 'hotel/rooms', 'hotel/requests', 'hotel/info', 'dashboard/hotel/pms/calendar', 'dashboard/hotel/pms/bookings',
            'dashboard/hotel/folio/{stay}/statement', 'biolink', 'biolink/insights', 'integrations', 'integrations/odoo',
            'integrations/switch', 'integrations/deliverect', 'stores/{store}/channels/zomato', 'schedules', 'stories',
            'stores/{store}/table-verification',
        ],
        'csp.marketing' => [
            'developers', 'developers/themes', 'developers/apps', 'integrations/docs/{slug}', 'switch-from-gloriafood',
            'studio/app', 'fusion/help', 'fusion/help/{slug}', 'dashboard/offline', 'account/restore/{deletion}', 'account/email-undo/{undo}',
            'spotlight/trial-emails/unsubscribe/{user}',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        config(['csp.enabled' => true, 'csp.enforce_dashboard' => true, 'csp.storefront_enforce' => 'all']);
    }

    /** @return list<string> middleware names (arguments dropped) of every GET route with this URI */
    private function middlewareOf(string $uri): array
    {
        $found = null;
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === $uri && in_array('GET', $route->methods(), true)) {
                $found = array_map(fn (string $m) => explode(':', $m, 2)[0], array_values(array_filter($route->gatherMiddleware(), 'is_string')));
                break;
            }
        }
        $this->assertNotNull($found, "GET {$uri} is registered");

        return $found;
    }

    public function test_each_group_carries_its_csp_middleware(): void
    {
        foreach (self::GROUPS as $middleware => $uris) {
            foreach ($uris as $uri) {
                $this->assertContains($middleware, $this->middlewareOf($uri), $uri);
            }
        }
    }

    public function test_public_surfaces_are_not_marked_as_dashboard_routes(): void
    {
        foreach ([...self::GROUPS[AddCspHeaders::class], ...self::GROUPS['csp.app'], ...self::GROUPS['csp.marketing']] as $uri) {
            if ($uri === 'reseller/team/accept/{token}') {
                continue; // `auth` already makes it a dashboard route
            }
            $this->assertNotContains('csp.dashboard', $this->middlewareOf($uri), $uri);
        }
    }

    public function test_vertical_cart_and_checkout_are_served_with_the_enforced_storefront_policy(): void
    {
        $store = Fixtures::store(Fixtures::owner(), 'Csp Goods');
        foreach (['/'.$store->slug.'/cart', '/'.$store->slug.'/checkout'] as $url) {
            $res = $this->get($url);
            $this->assertContains($res->getStatusCode(), [200], $url);
            $this->assertFalse($res->headers->has('Content-Security-Policy-Report-Only'), $url);
            $csp = (string) $res->headers->get('Content-Security-Policy');
            $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[^']+'/", $csp, $url);
            $this->assertStringNotContainsString("'unsafe-eval'", $csp, $url);
            $this->assertStringContainsString("script-src-attr 'none'", $csp, $url);
        }
    }

    public function test_the_developer_docs_are_served_with_the_enforced_policy(): void
    {
        $csp = (string) $this->get('/developers')->assertOk()->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src 'self'[^;]*'nonce-/", $csp);
    }

    public function test_the_studio_share_policy_runs_no_script_and_keeps_inline_styles_for_the_design(): void
    {
        $policy = new StudioSharePolicy;
        $policy->prepareHeader();
        $csp = (string) $policy;
        $this->assertStringContainsString("script-src 'none'", $csp);
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertMatchesRegularExpression("/style-src [^;]*'unsafe-inline'/", $csp);
        $this->assertStringNotContainsString('nonce-', $csp, 'a nonce would switch unsafe-inline off for the srcdoc design');
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProductionSecurityRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        $this->withoutMiddleware(Installed::class);
    }

    public function test_csrf_exemptions_are_explicit_and_do_not_cover_owner_webhook_management(): void
    {
        $middleware = new class(app(), app('encrypter')) extends PreventRequestForgery
        {
            public function isExcepted(Request $request): bool
            {
                return $this->inExceptArray($request);
            }

            /** @return array<int, string> */
            public function exemptions(): array
            {
                return $this->except;
            }
        };

        $this->assertNotContains('*webhook*', $middleware->exemptions());
        $this->assertNotContains('*ipn*', $middleware->exemptions());

        foreach ([
            'ipn/stripe',
            'webhook/stripe',
            'webhook/fiscal/eta/token',
            'webhooks/reseller/12/lemon_squeezy',
            'studio/stripe-webhook',
            'voice/twilio/incoming',
        ] as $path) {
            $this->assertTrue(
                $middleware->isExcepted(Request::create('/'.$path, 'POST')),
                $path.' should remain available to external callback providers.'
            );
        }

        foreach ([
            'api/webhooks',
            'api/webhooks/12',
            'api/webhooks/12/toggle',
            'api/webhooks/12/test',
            'api/webhooks/deliveries/12/redeliver',
        ] as $path) {
            $this->assertFalse(
                $middleware->isExcepted(Request::create('/'.$path, 'POST')),
                $path.' is an authenticated owner session write and must require CSRF.'
            );
        }
    }

    public function test_explicit_callback_path_bypasses_csrf_but_owner_management_shape_does_not(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');

        Route::middleware('web')->post(
            '/webhook/csrf-audit/probe',
            static fn () => response()->noContent(202)
        );
        Route::middleware('web')->post(
            '/api/webhooks/csrf-audit/probe',
            static fn () => response()->noContent(202)
        );

        $this->post('/webhook/csrf-audit/probe')->assertStatus(202);
        $this->post('/api/webhooks/csrf-audit/probe')->assertStatus(419);
    }

    public function test_trusted_proxy_middleware_uses_the_explicit_configured_ranges(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.0/8', '192.0.2.10']]);

        $middleware = new TrustProxies;
        $property = new \ReflectionProperty($middleware, 'proxies');
        $property->setAccessible(true);

        $this->assertSame(['10.0.0.0/8', '192.0.2.10'], $property->getValue($middleware));
    }

    public function test_customer_password_auth_routes_use_tight_named_limiters(): void
    {
        $routes = [
            'customer.login' => 'throttle:auth-login',
            'customer.register' => 'throttle:auth-register',
            'api.v1.customer.login' => 'throttle:auth-login',
            'api.v1.customer.register' => 'throttle:auth-register',
        ];

        foreach ($routes as $name => $limiter) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains($limiter, $route->gatherMiddleware(), $name);
        }
    }
}

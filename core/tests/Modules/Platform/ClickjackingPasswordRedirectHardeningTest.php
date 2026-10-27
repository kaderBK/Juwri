<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Juwri\Core\Http\Middleware\AddSecurityHeaders;
use Juwri\Core\Support\SafeRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/** P6-77 clickjacking allowlist, P6-76 one password rule, P6-82 one redirect guard. */
class ClickjackingPasswordRedirectHardeningTest extends TestCase
{
    private function headersFor(\Illuminate\Routing\Route $route, ?string $csp = null): Response
    {
        $request = Request::create('/x');
        $request->setRouteResolver(fn () => $route);

        return (new AddSecurityHeaders)->handle($request, function () use ($csp) {
            $r = new Response('ok');
            if ($csp !== null) {
                $r->headers->set('Content-Security-Policy', $csp);
            }

            return $r;
        });
    }

    public function test_only_the_explicit_allowlist_of_public_view_routes_is_embeddable(): void
    {
        $this->assertSame(['publicView'], AddSecurityHeaders::EMBEDDABLE_ROUTES);

        $seen = 0;
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! is_string($name) || ! str_starts_with($name, 'publicView')) {
                continue;
            }
            $seen++;
            $resp = $this->headersFor($route, "default-src 'self'; frame-ancestors 'self'");
            if ($name === 'publicView') {
                $this->assertFalse($resp->headers->has('X-Frame-Options'), $name);
                $this->assertStringContainsString('frame-ancestors *', $resp->headers->get('Content-Security-Policy'), $name);
            } else {
                $this->assertSame('SAMEORIGIN', $resp->headers->get('X-Frame-Options'), $name);
                $this->assertStringContainsString("frame-ancestors 'self'", $resp->headers->get('Content-Security-Policy'), $name);
                $this->assertStringNotContainsString('frame-ancestors *', $resp->headers->get('Content-Security-Policy'), $name);
            }
        }
        $this->assertGreaterThan(10, $seen);
    }

    public function test_locked_route_without_csp_gets_frame_ancestors_self(): void
    {
        $route = Route::getRoutes()->getByName('publicView.kiosk');
        $resp = $this->headersFor($route);
        $this->assertSame("frame-ancestors 'self'", $resp->headers->get('Content-Security-Policy'));
    }

    public function test_deposit_pages_kiosk_unsubscribe_are_locked(): void
    {
        foreach (['publicView.reservation.deposit', 'publicView.catering.deposit', 'publicView.kiosk', 'publicView.unsubscribe.info'] as $n) {
            $this->assertSame('SAMEORIGIN', $this->headersFor(Route::getRoutes()->getByName($n))->headers->get('X-Frame-Options'), $n);
        }
    }

    public function test_password_rule_is_shared_default_on_setting_paths(): void
    {
        config(['auth.password_policy_strict' => true]);
        // The strict default rejects a 6-char password; the old hard-coded min:6 accepted it.
        $v = Validator::make(['password' => 'abc123'], ['password' => ['required', 'string', Password::defaults()]]);
        $this->assertTrue($v->fails());

        $root = base_path();
        foreach ([
            'app/Http/Controllers/User/SettingsController.php',
            'app/Core/Http/Requests/Admin/Accounts/AccountStoreRequest.php',
            'app/Modules/Auth/Http/Requests/Admin/Accounts/AccountPasswordRequest.php',
            'app/Modules/Auth/Http/Controllers/RegisterController.php',
        ] as $file) {
            $src = file_get_contents("$root/$file");
            $this->assertDoesNotMatchRegularExpression("/'(new_)?password'\s*=>[^\n]*min:6/", $src, $file);
            $this->assertStringContainsString('Password::defaults()', $src, $file);
        }
    }

    #[DataProvider('redirectCases')]
    public function test_redirect_table(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, SafeRedirect::path($raw));
        $this->assertSame($expected ?? '/dashboard', SafeRedirect::path($raw, '/dashboard'));
    }

    public static function redirectCases(): array
    {
        return [
            'orders ok' => ['/orders', '/orders'],
            'orders query' => ['/orders?a=1', '/orders?a=1'],
            'scheme relative' => ['//x', null],
            'slash backslash' => ['/\\x', null],
            'encoded backslash' => ['/%5Cx', null],
            'encoded slashes' => ['/%2F%2Fx', null],
            'double encoded' => ['/%255Cx', null],
            'absolute other host' => ['https://x', null],
            'javascript' => ['javascript:alert(1)', null],
            'double backslash' => ['\\\\x', null],
            'tab' => ["/\t/x", null],
            'newline' => ["/\n/x", null],
            'leading tab' => ["\t//x", null],
            'empty' => ['', null],
        ];
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Tests\TestCase;

/**
 * P0-9: every state-changing / token-guessable public Studio endpoint is
 * rate-limited, and the Stripe webhook verifies its signature.
 */
class StudioRouteHardeningTest extends TestCase
{
    private function middleware(string $name): array
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->getName() === $name);

        return $route ? $route->gatherMiddleware() : [];
    }

    private function assertThrottled(string $name): void
    {
        $throttled = collect($this->middleware($name))
            ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));
        $this->assertTrue($throttled, "route {$name} must be throttled");
    }

    public function test_state_changing_and_token_studio_routes_are_throttled(): void
    {
        $this->assertThrottled('studio.output');
        $this->assertThrottled('studio.forget');
        $this->assertThrottled('studio.export');
        $this->assertThrottled('studio.share');
    }

    public function test_stripe_webhook_verifies_signature(): void
    {
        $src = file_get_contents(app_path('Modules/Content/Http/Controllers/Studio/StudioController.php'));
        // Signature is verified via Stripe's constructEvent, which throws on mismatch.
        $this->assertStringContainsString('Webhook::constructEvent', $src);
        $this->assertStringContainsString("Stripe-Signature", $src);
    }
}

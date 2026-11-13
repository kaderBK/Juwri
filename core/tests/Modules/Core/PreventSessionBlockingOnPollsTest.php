<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Illuminate\Http\Request;
use Juwri\Core\Http\Middleware\PreventSessionBlockingOnPolls;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Guards PreventSessionBlockingOnPolls: the middleware must turn the per-session
 * block lock OFF for every SAFE (read) method so a burst of same-session reads
 * can't serialise into a LockTimeoutException 500, while state-changing methods
 * keep the production session.block double-submit hardening.
 */
class PreventSessionBlockingOnPollsTest extends TestCase
{
    private function blockDisabledFor(string $path, string $method = 'GET'): bool
    {
        // Emulate production hardening being on for this request.
        config(['session.block' => true]);

        (new PreventSessionBlockingOnPolls)->handle(
            Request::create('/'.ltrim($path, '/'), $method),
            fn ($r) => new Response('ok')
        );

        // block flipped false ⇒ this request is not serialised on the lock.
        return config('session.block') === false;
    }

    public static function safeMethods(): array
    {
        return ['GET' => ['GET'], 'HEAD' => ['HEAD'], 'OPTIONS' => ['OPTIONS']];
    }

    public static function unsafeMethods(): array
    {
        return ['POST' => ['POST'], 'PUT' => ['PUT'], 'PATCH' => ['PATCH'], 'DELETE' => ['DELETE']];
    }

    #[DataProvider('safeMethods')]
    public function test_safe_methods_disable_session_block(string $method): void
    {
        $this->assertTrue($this->blockDisabledFor('anything/at/all', $method));
    }

    #[DataProvider('unsafeMethods')]
    public function test_state_changing_methods_keep_session_block(string $method): void
    {
        $this->assertFalse($this->blockDisabledFor('checkout', $method));
        // Even the geo path keeps the lock on a non-safe method.
        $this->assertFalse($this->blockDisabledFor('geo/autocomplete', $method));
    }

    public function test_the_historically_failing_reads_are_covered(): void
    {
        // The endpoints that motivated this middleware are all GETs, so the
        // method-based rule must still cover them.
        $this->assertTrue($this->blockDisabledFor('geo/autocomplete'));
        $this->assertTrue($this->blockDisabledFor('geo/reverse'));
        $this->assertTrue($this->blockDisabledFor('dashboard/notifications'));
        $this->assertTrue($this->blockDisabledFor('restaurant/42/wallet/balance'));
        $this->assertTrue($this->blockDisabledFor('kds/poll'));
        // …and an arbitrary future read that was never on any allow-list.
        $this->assertTrue($this->blockDisabledFor('some-store/menu/ai-translate/progress'));
    }

    /**
     * Octane (P6-39): config() writes to a per-request clone of the repository, but the session manager (a worker singleton)
     * keeps the repository it was built with. StartSession asks the manager, so the flag has to reach it, and only for the
     * length of the safe request.
     */
    public function test_the_session_manager_sees_the_flag_under_a_cloned_config_repository(): void
    {
        config(['session.block' => true]);
        $manager = app('session');
        app()->instance('config', clone app('config')); // what Octane's CreateConfigurationSandbox does per request

        $seenOnGet = null;
        (new PreventSessionBlockingOnPolls)->handle(Request::create('/kds/poll', 'GET'), function ($r) use ($manager, &$seenOnGet) {
            $seenOnGet = $manager->shouldBlock();

            return new Response('ok');
        });
        $this->assertFalse($seenOnGet, 'a safe request must not take the session lock');
        $this->assertTrue($manager->shouldBlock(), 'the manager is put back after the request');

        $seenOnPost = null;
        (new PreventSessionBlockingOnPolls)->handle(Request::create('/checkout', 'POST'), function ($r) use ($manager, &$seenOnPost) {
            $seenOnPost = $manager->shouldBlock();

            return new Response('ok');
        });
        $this->assertTrue($seenOnPost, 'a state-changing request keeps the lock');
    }

    public function test_browser_beacon_posts_skip_session_blocking(): void
    {
        $this->assertTrue($this->blockDisabledFor('csp-report', 'POST'));
        $this->assertTrue($this->blockDisabledFor('dash/client-error', 'POST'));
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use Tests\TestCase;

/**
 * ISS-V-C-002 / ISS-V-C-003 — global defense-in-depth headers.
 *   - X-Content-Type-Options: nosniff on every response.
 *   - X-Frame-Options: SAMEORIGIN everywhere EXCEPT the embeddable public
 *     storefront (publicView* routes), which owners iframe cross-origin.
 */
class SecurityHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    public function test_login_page_carries_nosniff_and_frame_deny(): void
    {
        $resp = $this->get('/login');

        // /login may redirect (locale) — follow to the rendered page.
        if (in_array($resp->status(), [301, 302], true)) {
            $resp = $this->followingRedirects()->get('/login');
        }

        $resp->assertHeader('X-Content-Type-Options', 'nosniff');
        $resp->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_storefront_gets_nosniff_but_stays_embeddable(): void
    {
        // A missing slug still resolves the publicView route (name-matched) and
        // the exclusion applies: nosniff present, X-Frame-Options absent so the
        // owner's cross-origin menu <iframe> is not blocked.
        $resp = $this->get('/no-such-store-'.uniqid());

        $resp->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertFalse(
            $resp->headers->has('X-Frame-Options'),
            'storefront must stay embeddable (no X-Frame-Options)'
        );
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content\Studio;

use App\Http\Middleware\Installed;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * W1-CTA: the Studio shell shows a guest → owner conversion CTA ("Save my work")
 * ONLY for guests. An authenticated owner must never see it. Presentation only —
 * this asserts the CTA markup + the auth links that carry a ?redirect back to the
 * studio app shell, and that both are absent once signed in.
 */
class StudioGuestCtaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        Storage::fake('local');
    }

    public function test_guest_sees_the_save_my_work_cta(): void
    {
        $res = $this->get('/studio/app');

        $res->assertOk();
        // The CTA section renders. Anchor on the element attribute, not the copy:
        // the suite runs under DEFAULT_LANGUAGE=ar, so the visible text is the
        // Arabic translation — the markup + redirect links are locale-independent.
        // (The bare `.savebar` token also lives in the stylesheet, hence the exact
        // `class="savebar"` match on the element.)
        $res->assertSeeHtml('class="savebar"');
        // Both auth entry points are offered and carry a redirect back to Studio.
        $res->assertSeeHtml('signup?redirect=');   // register
        $res->assertSeeHtml('login?redirect=');     // login
        $res->assertSeeHtml('%2Fstudio%2Fapp');     // ?redirect target is studio.app.shell
    }

    public function test_authed_owner_does_not_see_the_cta(): void
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        $res = $this->actingAs($user)->get('/studio/app');

        $res->assertOk();
        // The guest CTA element and its auth links must not render for an owner.
        $res->assertDontSeeHtml('class="savebar"');
        $res->assertDontSeeHtml('signup?redirect=');
        $res->assertDontSeeHtml('login?redirect=');
    }
}

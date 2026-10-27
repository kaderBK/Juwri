<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Modules\Content\Services\Studio\StudioCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * App-shell wiring: inline design preview (cookie-scoped) and the config-gated
 * welcome-credit grant for brand-new guest sessions.
 */
class StudioAppShellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class]);
        Storage::fake('local');
    }

    private function mkSession(): StudioSession
    {
        return StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
    }

    public function test_design_preview_serves_owners_image_inline(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\EncryptCookies::class);
        $s = $this->mkSession();
        Storage::disk('local')->put($s->storagePath('design.png'), 'PNGBYTES');

        $res = $this->call('GET', route('studio.design.preview'), [], ['studio_guest' => $s->guest_token]);

        $res->assertOk();
        $this->assertSame('image/png', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    public function test_design_preview_404_for_other_session(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\EncryptCookies::class);
        $owner = $this->mkSession();
        Storage::disk('local')->put($owner->storagePath('design.png'), 'PNGBYTES');
        $stranger = $this->mkSession();

        $this->call('GET', route('studio.design.preview'), [], ['studio_guest' => $stranger->guest_token])
            ->assertNotFound();
    }

    public function test_new_guest_session_gets_welcome_credits_when_enabled(): void
    {
        config(['studio.welcome_credits' => 7]);

        $res = $this->get('/studio/app');
        $res->assertOk();

        $session = StudioSession::latest('id')->first();
        $this->assertNotNull($session);
        $this->assertSame(7, app(StudioCreditService::class)->balance($session));
    }

    public function test_welcome_credits_off_by_default(): void
    {
        $this->get('/studio/app')->assertOk();

        $session = StudioSession::latest('id')->first();
        $this->assertSame(0, app(StudioCreditService::class)->balance($session));
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Models\StudioSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SI1 (#2): public read-only, watermarked share preview.
 */
class StudioShareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class]);
        Storage::fake('local');
    }

    private function sessionWithDraft(): StudioSession
    {
        $s = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
        Storage::disk('local')->put($s->storagePath('draft.json'),
            json_encode(['categories' => [['name' => 'Mains', 'items' => [['name' => 'Falafel Wrap', 'description' => '', 'price' => 6]]]]]));
        return $s;
    }

    public function test_share_shows_read_only_watermarked_preview(): void
    {
        $s = $this->sessionWithDraft();

        $res = $this->get('/studio/s/'.$s->ensureShareToken());

        $res->assertOk();
        $res->assertSee('Falafel Wrap');
        $res->assertSee(___('Made with'));           // watermark / attribution
        $res->assertDontSee('studio-publish');   // no publish/download controls
    }

    public function test_share_404_on_bad_token(): void
    {
        $this->get('/studio/s/'.Str::random(48))->assertNotFound();
    }

    public function test_share_rejects_guest_credential_token(): void
    {
        // The guest token is a session credential (resume uses it) — it must
        // never work as a public share URL.
        $s = $this->sessionWithDraft();
        $this->get('/studio/s/'.$s->guest_token)->assertNotFound();
    }

    public function test_share_404_when_no_draft_yet(): void
    {
        $s = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
        $this->get('/studio/s/'.$s->ensureShareToken())->assertNotFound();
    }

    public function test_share_link_endpoint_mints_public_token(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\EncryptCookies::class);
        $s = $this->sessionWithDraft();

        $res = $this->call('POST', '/studio/share-link', [], ['studio_guest' => $s->guest_token]);

        $res->assertOk();
        $token = $res->json('code');
        $this->assertNotEmpty($token);
        $this->assertNotSame($s->guest_token, $token);
        $this->assertStringContainsString('/studio/s/'.$token, $res->json('url'));

        // Stable across calls.
        $res2 = $this->call('POST', '/studio/share-link', [], ['studio_guest' => $s->guest_token]);
        $this->assertSame($token, $res2->json('code'));
    }
}

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
 * SI4: chat-editing power — draft autosave (#28) + resume link (#29).
 */
class StudioChatEditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\EncryptCookies::class,
        ]);
        Storage::fake('local');
    }

    public function test_autosave_persists_normalized_draft(): void
    {
        $res = $this->postJson('/studio/save-draft', ['categories' => [
            ['name' => ' Drinks ', 'items' => [['name' => 'Cola', 'description' => '', 'price' => '3,50']]],
        ]]);

        $res->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.categories.0.name', 'Drinks');       // trimmed
        $s = StudioSession::first();
        $draft = json_decode(Storage::disk('local')->get($s->storagePath('draft.json')), true);
        $this->assertEquals(3.5, $draft['categories'][0]['items'][0]['price']); // locale-parsed
    }

    public function test_resume_link_restores_prior_session(): void
    {
        $prior = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);

        $res = $this->get('/studio?resume='.$prior->guest_token);

        $res->assertOk();
        $res->assertCookie('studio_guest', $prior->guest_token, false); // plain (EncryptCookies bypassed)
        $this->assertSame(1, StudioSession::count());            // no new session minted
    }
}

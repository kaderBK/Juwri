<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Modules\Content\Services\Studio\StudioCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P4-2: Nano Banana background art — cached reuse free, quota'd key never debits.
 */
class StudioTemplateBackgroundTest extends TestCase
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
        config(['studio.gemini.api_key' => 'k', 'studio.credit_cost.pdf_design' => 5]);
    }

    protected function fundedSession(int $credits = 20): StudioSession
    {
        $s = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
        Storage::disk('local')->put($s->storagePath('draft.json'), json_encode([
            'categories' => [['name' => 'Grill', 'items' => [['name' => 'Kofta', 'description' => '', 'price' => 52, 'variants' => [], 'extras' => []]]]],
        ]));
        $this->call('POST', '/studio/template/options', ['slug' => 'classic-bistro'],
            ['studio_guest' => $s->guest_token])->assertOk();
        if ($credits) {
            app(StudioCreditService::class)->credit($s, $credits, 'topup');
        }

        return $s;
    }

    protected function balance(StudioSession $s): int
    {
        return app(StudioCreditService::class)->balance($s);
    }

    public function test_generates_stores_and_debits_once_then_cache_is_free(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['inline_data' => ['data' => base64_encode('PNGBYTES')]]]]]],
        ], 200)]);
        $s = $this->fundedSession();

        $first = $this->call('POST', '/studio/template/background', [], ['studio_guest' => $s->guest_token]);
        $first->assertOk()->assertJsonPath('cached', false);
        $this->assertTrue(Storage::disk('local')->exists($s->storagePath('template-bg.png')));
        $this->assertSame(15, $this->balance($s));

        // Second call reuses the cache — no HTTP, no debit.
        $second = $this->call('POST', '/studio/template/background', [], ['studio_guest' => $s->guest_token]);
        $second->assertOk()->assertJsonPath('cached', true);
        $this->assertSame(15, $this->balance($s));
        Http::assertSentCount(1);

        // The no-text art prompt reached the model.
        Http::assertSent(function ($r) {
            $text = data_get($r->data(), 'contents.0.parts.0.text', '');
            return str_contains($text, 'NO text') && str_contains($text, 'vintage bistro');
        });
    }

    public function test_quota_429_returns_502_and_never_debits(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 429]], 429)]);
        $s = $this->fundedSession();

        $res = $this->call('POST', '/studio/template/background', [], ['studio_guest' => $s->guest_token]);

        $res->assertStatus(502);
        $this->assertSame(20, $this->balance($s)); // refunded
        $this->assertFalse(Storage::disk('local')->exists($s->storagePath('template-bg.png')));
    }

    public function test_no_credits_402_before_any_http(): void
    {
        Http::fake();
        $s = $this->fundedSession(0);

        $this->call('POST', '/studio/template/background', [], ['studio_guest' => $s->guest_token])
            ->assertStatus(402)->assertJsonPath('need_credits', true);
        Http::assertNothingSent();
    }

    public function test_force_regenerates_and_debits_again(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['inline_data' => ['data' => base64_encode('PNG2')]]]]]],
        ], 200)]);
        $s = $this->fundedSession();
        Storage::disk('local')->put($s->storagePath('template-bg.png'), 'OLD');

        $res = $this->call('POST', '/studio/template/background', ['force' => 1], ['studio_guest' => $s->guest_token]);

        $res->assertOk()->assertJsonPath('cached', false);
        $this->assertSame('PNG2', Storage::disk('local')->get($s->storagePath('template-bg.png')));
        $this->assertSame(15, $this->balance($s));
    }
}

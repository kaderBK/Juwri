<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Http\Controllers\Studio\StudioController;
use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Modules\Content\Services\Studio\StudioCreditService;
use Juwri\Modules\Content\Services\Studio\StudioDesignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SI14: trust/safety — per-session generation cap (#99) + GDPR export (#100).
 * (Rate-limits + delete-now shipped in S4-P6; EXIF is stripped by the JPEG re-encode.)
 */
class StudioSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class]);
        Storage::fake('local');
        config(['studio.gemini.api_key' => 'k', 'studio.credit_cost.pdf_design' => 5, 'studio.max_designs_per_session' => 1]);
    }

    private function sessionWithDraft(): StudioSession
    {
        $s = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
        Storage::disk('local')->put($s->storagePath('draft.json'),
            json_encode(['categories' => [['name' => 'M', 'items' => [['name' => 'X', 'price' => 5]]]]]));
        return $s;
    }

    public function test_generation_is_capped_per_session(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['inline_data' => ['data' => base64_encode('PNG')]]]]]],
        ], 200)]);
        $s = $this->sessionWithDraft();
        app(StudioCreditService::class)->credit($s, 100, 'topup');
        $svc = app(StudioDesignService::class);

        $svc->generate($s, 'look one'); // 1st ok (cap = 1)

        $this->expectException(\RuntimeException::class);
        $svc->generate($s, 'look two'); // 2nd blocked by the cap
    }

    public function test_export_returns_session_and_draft(): void
    {
        $s = $this->sessionWithDraft();
        $s->update(['output_type' => 'pdf', 'status' => 'extracted']);

        $req = Request::create('/studio/export', 'GET');
        $req->cookies->set('studio_guest', $s->guest_token);
        $data = new StudioController()->export($req)->getData(true);

        $this->assertSame('pdf', $data['session']['output_type']);
        $this->assertSame('X', $data['draft']['categories'][0]['items'][0]['name']);
    }
}

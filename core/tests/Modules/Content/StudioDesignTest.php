<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Modules\Ai\Services\AiProviderException;
use Juwri\Modules\Content\Services\Studio\InsufficientCreditsException;
use Juwri\Modules\Content\Services\Studio\StudioCreditService;
use Juwri\Modules\Content\Services\Studio\StudioDesignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * S4-P5: Nano Banana PDF-styled generation, credit-gated. Live key mocked.
 */
class StudioDesignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class]);
        Storage::fake('local');
        config(['studio.gemini.api_key' => 'k', 'studio.credit_cost.pdf_design' => 5]);
    }

    private function sessionWithDraft(int $credits): StudioSession
    {
        $s = StudioSession::create(['guest_token' => Str::random(48), 'output_type' => 'pdf', 'expires_at' => now()->addHours(48)]);
        Storage::disk('local')->put($s->storagePath('draft.json'),
            json_encode(['categories' => [['name' => 'Mains', 'items' => [['name' => 'Burger', 'price' => 9]]]]]));
        if ($credits > 0) {
            app(StudioCreditService::class)->credit($s, $credits, 'topup');
        }
        return $s;
    }

    private function fakeGeminiImage(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [
                ['inline_data' => ['mime_type' => 'image/png', 'data' => base64_encode('PNGBYTES')]],
            ]]]],
        ], 200)]);
    }

    public function test_generate_debits_credits_and_stores_design(): void
    {
        $this->fakeGeminiImage();
        $s = $this->sessionWithDraft(10);

        $out = app(StudioDesignService::class)->generate($s, 'warm rustic trattoria, cream paper');

        Storage::disk('local')->assertExists($s->storagePath('design.png'));
        $this->assertSame(5, app(StudioCreditService::class)->balance($s)); // 10 - cost 5
        $this->assertSame('designed', $s->fresh()->status);
        $this->assertSame(5, $out['cost']);
    }

    public function test_insufficient_credits_blocks_before_any_spend(): void
    {
        $this->fakeGeminiImage();
        $s = $this->sessionWithDraft(2); // < cost 5

        try {
            app(StudioDesignService::class)->generate($s, 'anything');
            $this->fail('expected InsufficientCreditsException');
        } catch (InsufficientCreditsException $e) {
            // expected
        }

        Http::assertNothingSent();                 // never called the paid model
        $this->assertSame(2, app(StudioCreditService::class)->balance($s)); // not charged
        Storage::disk('local')->assertMissing($s->storagePath('design.png'));
    }

    public function test_refund_when_model_fails(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response('err', 500)]);
        $s = $this->sessionWithDraft(10);

        try {
            app(StudioDesignService::class)->generate($s, 'anything');
            $this->fail('expected AiProviderException');
        } catch (AiProviderException $e) {
            // expected
        }

        $this->assertSame(10, app(StudioCreditService::class)->balance($s)); // debit refunded
        Storage::disk('local')->assertMissing($s->storagePath('design.png'));
    }

    public function test_generate_route_requires_described_look(): void
    {
        $this->postJson('/studio/generate-design', [])->assertStatus(422);
    }

    /** A small real PNG so GD can decode it during the PDF wrap. */
    private function realPng(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 200, 120, 80));
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);
        return $bytes;
    }

    public function test_service_wraps_an_image_into_a_valid_single_page_pdf(): void
    {
        $pdf = app(\Juwri\Modules\Content\Services\Studio\StudioPdfService::class)->imageToPdf($this->realPng(300, 420));

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('/DCTDecode', $pdf);   // image embedded as JPEG, not raw PNG
        $this->assertStringContainsString('/MediaBox [0 0 300 420]', $pdf); // page sized to the image
        $this->assertStringContainsString('%%EOF', $pdf);
    }

    public function test_download_route_returns_a_pdf_for_the_generated_design(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\EncryptCookies::class);
        $s = $this->sessionWithDraft(0);
        Storage::disk('local')->put($s->storagePath('design.png'), $this->realPng(300, 420));

        $res = $this->call('GET', route('studio.design.download'), [], ['studio_guest' => $s->guest_token]);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $body = $res->getContent() !== '' && $res->getContent() !== false
            ? $res->getContent() : $res->streamedContent();
        $this->assertStringStartsWith('%PDF', $body);
    }

    public function test_download_404_when_no_design_generated_yet(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\EncryptCookies::class);
        $s = $this->sessionWithDraft(0);

        $this->withCookie('studio_guest', $s->guest_token)
            ->get(route('studio.design.download'))
            ->assertNotFound();
    }
}

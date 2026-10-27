<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\AbuseRateLimit;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Models\AiUsage;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * ZERO-TOUCH Track B2 — POST {store}/agent/stt (Track A's server-STT seam).
 * Contract: multipart `audio` (webm/mp4/m4a/ogg/wav, <=60s, <=2MB) ->
 * {"text"} | 422 {"error"} | 403 gate | 429 throttle/spend-cap.
 */
class AgentSttEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            ThrottleRequests::class,
            AbuseRateLimit::class,
        ]);

        config([
            'ai.transcribe' => ['groq'],
            'ai.providers.groq' => [
                'driver' => 'openai', 'base_url' => 'https://api.groq.com/openai/v1',
                'api_key' => 'test-groq', 'model' => 'llama-3.3-70b-versatile',
                'supports_json' => true, 'supports_vision' => false,
                'supports_transcription' => true,
                'transcription_model' => 'whisper-large-v3-turbo',
            ],
        ]);
    }

    /** @return array{0:Store,1:User} */
    private function storeFixture(bool $chat = true): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'ai_chatbot' => $chat ? '1' : '0'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'stt-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($r->id, 'agent_chat_enabled', $chat ? '1' : '0');

        return [$r, $owner];
    }

    /**
     * A fake upload with REAL bytes on disk (the provider layer reads/sizes the
     * actual file, so a size-only fake would trip its guards) + an explicit
     * reported mime.
     */
    private function blob(string $name = 'speech.webm', int $kb = 40, string $mime = 'audio/webm'): UploadedFile
    {
        $file = UploadedFile::fake()->createWithContent($name, str_repeat('a', $kb * 1024));
        $file->mimeTypeToReport = $mime;

        return $file;
    }

    public function test_happy_path_returns_text_and_meters_owner(): void
    {
        [$r, $owner] = $this->storeFixture();
        Http::fake(['api.groq.com/*' => Http::response(['text' => 'two pizzas please', 'language' => 'en', 'duration' => 4.0], 200)]);

        $this->post(route('agent.stt', ['store' => $r->id]), [
            'audio' => $this->blob(), 'duration' => 4, 'language' => 'en',
        ])->assertOk()->assertExactJson(['text' => 'two pizzas please']);

        $this->assertDatabaseHas('ai_usage', [
            'user_id' => $owner->id, 'provider' => 'groq', 'feature' => 'agent_stt', 'audio_seconds' => 4,
        ]);
    }

    public function test_oversize_blob_rejected_422(): void
    {
        [$r] = $this->storeFixture();
        Http::fake();

        $this->post(route('agent.stt', ['store' => $r->id]), [
            'audio' => $this->blob('speech.webm', 3000), 'duration' => 5,
        ])->assertStatus(422)->assertJsonStructure(['error']);

        Http::assertNothingSent();
        $this->assertSame(0, AiUsage::count());
    }

    public function test_wrong_mime_rejected_422(): void
    {
        [$r] = $this->storeFixture();
        Http::fake();

        $this->post(route('agent.stt', ['store' => $r->id]), [
            'audio' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'), 'duration' => 3,
        ])->assertStatus(422)->assertJsonStructure(['error']);

        Http::assertNothingSent();
    }

    public function test_overlong_duration_rejected_422(): void
    {
        [$r] = $this->storeFixture();
        Http::fake();

        $this->post(route('agent.stt', ['store' => $r->id]), [
            'audio' => $this->blob(), 'duration' => 120,
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_missing_audio_rejected_422(): void
    {
        [$r] = $this->storeFixture();
        Http::fake();

        $this->post(route('agent.stt', ['store' => $r->id]), ['duration' => 3])
            ->assertStatus(422)->assertJsonStructure(['error']);
    }

    public function test_agent_chat_disabled_403(): void
    {
        [$r] = $this->storeFixture(chat: false);
        Http::fake();

        $this->post(route('agent.stt', ['store' => $r->id]), [
            'audio' => $this->blob(), 'duration' => 3,
        ])->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_option_on_but_plan_without_chatbot_403(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$r] = $this->storeFixture(chat: false);
        StoreOption::updateStoreOption($r->id, 'agent_chat_enabled', '1'); // toggle on, plan still off

        $this->post(route('agent.stt', ['store' => $r->id]), [
            'audio' => $this->blob(), 'duration' => 3,
        ])->assertStatus(403);
    }

    public function test_no_configured_provider_degrades_422(): void
    {
        [$r] = $this->storeFixture();
        config(['ai.providers.groq.api_key' => null]);
        Http::fake();

        $this->post(route('agent.stt', ['store' => $r->id]), [
            'audio' => $this->blob(), 'duration' => 3,
        ])->assertStatus(422)->assertJsonStructure(['error']);

        Http::assertNothingSent();
    }

    public function test_provider_failure_degrades_422_not_500(): void
    {
        [$r] = $this->storeFixture();
        Http::fake(['api.groq.com/*' => Http::response('upstream boom', 500)]);

        $this->post(route('agent.stt', ['store' => $r->id]), [
            'audio' => $this->blob(), 'duration' => 3,
        ])->assertStatus(422)->assertJsonStructure(['error']);
    }

    public function test_spend_cap_blocks_with_429(): void
    {
        [$r, $owner] = $this->storeFixture();
        config([
            'settings.abuse_ai_spend_mode' => 'enforce',
            'settings.abuse_ai_spend_user_daily_cap' => 1,
        ]);
        AiUsage::create([
            'user_id' => $owner->id, 'provider' => 'groq', 'model' => 'w', 'feature' => 'agent_stt',
            'prompt_tokens' => 0, 'completion_tokens' => 0, 'fell_back' => false,
        ]);
        Http::fake();

        $this->post(route('agent.stt', ['store' => $r->id]), [
            'audio' => $this->blob(), 'duration' => 3,
        ])->assertStatus(429)->assertJsonStructure(['error']);

        Http::assertNothingSent();
    }

    public function test_limiter_registered_with_session_and_ip_dimensions(): void
    {
        $limiter = app(RateLimiter::class)->limiter('agent-stt');
        $this->assertNotNull($limiter, 'agent-stt limiter must be registered');

        $request = Request::create('/x/agent/stt', 'POST');
        $limits = $limiter($request);

        $this->assertCount(2, $limits);
        $this->assertSame(10, $limits[0]->maxAttempts); // per session (IP fallback)
        $this->assertSame(30, $limits[1]->maxAttempts); // per IP ceiling
        $this->assertStringEndsWith('|stt', $limits[0]->key);
        $this->assertStringEndsWith('|stt-ip', $limits[1]->key);
    }

    public function test_throttled_by_ip_ceiling_even_when_sessions_rotate(): void
    {
        [$r] = $this->storeFixture();
        // Re-enable ONLY the route throttle for this test. Test requests get a
        // fresh session per call, so the per-IP ceiling (30/min) is the
        // dimension that trips here — exactly the cookie-rotation abuse case.
        $this->withMiddleware(ThrottleRequests::class);
        Http::fake(['api.groq.com/*' => Http::response(['text' => 'ok', 'duration' => 1.0], 200)]);

        for ($i = 0; $i < 30; $i++) {
            $this->post(route('agent.stt', ['store' => $r->id]), [
                'audio' => $this->blob(), 'duration' => 1,
            ])->assertOk();
        }

        $this->post(route('agent.stt', ['store' => $r->id]), [
            'audio' => $this->blob(), 'duration' => 1,
        ])->assertStatus(429);
    }

    public function test_resolves_restaurant_by_slug_too(): void
    {
        [$r] = $this->storeFixture();
        Http::fake(['api.groq.com/*' => Http::response(['text' => 'via slug', 'duration' => 2.0], 200)]);

        $this->post(route('agent.stt', ['store' => $r->slug]), [
            'audio' => $this->blob(), 'duration' => 2,
        ])->assertOk()->assertExactJson(['text' => 'via slug']);
    }
}

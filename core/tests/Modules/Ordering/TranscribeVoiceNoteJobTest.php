<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Controllers\Api\V1\Concerns\FormatsOrders;
use App\Http\Middleware\AbuseHoldWall;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\AbuseRateLimit;
use Juwri\Core\Http\Middleware\BranchRateLimit;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Services\AiProviderFactory;
use Juwri\Modules\Ai\Services\AiService;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Jobs\TranscribeVoiceNote;
use Juwri\Modules\Ordering\Models\OrderVoiceNote;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * ZERO-TOUCH Track B3 — TranscribeVoiceNote job fills the features-5
 * `order_voice_notes.transcript` seam; staff payloads carry the text;
 * failures/missing providers never block or dirty an order.
 */
class TranscribeVoiceNoteJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Storage::fake('local');

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            ThrottleRequests::class,
            AbuseRateLimit::class,
            AbuseHoldWall::class,
            BranchRateLimit::class,
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

    /** @return array{owner:User,r:Store,menu:Menu} */
    private function storeFixture(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999, 'scan_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'vn-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'parent' => null, 'position' => 1]);
        $menu = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'description' => 'd',
            'price' => 10, 'image' => 'default.png', 'type' => 'veg', 'active' => '1', 'position' => 1,
        ]);

        return compact('owner', 'r', 'menu');
    }

    /** A stored voice note (real bytes on the fake local disk), attached to an order. */
    private function note(Store $r, ?int $orderId = null, array $attrs = []): OrderVoiceNote
    {
        $filename = Str::random(48).'.webm';
        Storage::disk('local')->put('voice-notes/'.$r->id.'/'.$filename, str_repeat('a', 4096));

        return OrderVoiceNote::create(array_merge([
            'order_id' => $orderId,
            'store_id' => $r->id,
            'token' => OrderVoiceNote::newToken(),
            'filename' => $filename,
            'mime' => 'audio/webm',
            'duration_seconds' => 6,
            'size_bytes' => 4096,
        ], $attrs));
    }

    private function order(Store $r, string $status = 'new'): Order
    {
        return Order::create([
            'store_id' => $r->id, 'status' => $status, 'type' => 'on-table',
            'customer_name' => 'Test Customer', 'table_number' => 1, 'created_at' => now(),
        ]);
    }

    public function test_job_fills_transcript_and_meters_by_audio_seconds(): void
    {
        ['r' => $r, 'owner' => $owner] = $this->storeFixture();
        $note = $this->note($r, $this->order($r)->id);

        Http::fake(['api.groq.com/*' => Http::response(['text' => 'no onions please', 'language' => 'en', 'duration' => 5.6], 200)]);

        new TranscribeVoiceNote($note->id)->handle(app(AiService::class), app(AiProviderFactory::class));

        $this->assertSame('no onions please', $note->fresh()->transcript);
        $this->assertDatabaseHas('ai_usage', [
            'user_id' => $owner->id, 'provider' => 'groq', 'feature' => 'voice_note', 'audio_seconds' => 6,
        ]);
    }

    public function test_job_noops_cleanly_when_no_provider_configured(): void
    {
        config(['ai.providers.groq.api_key' => null]);
        ['r' => $r] = $this->storeFixture();
        $note = $this->note($r, $this->order($r)->id);
        Http::fake();

        new TranscribeVoiceNote($note->id)->handle(app(AiService::class), app(AiProviderFactory::class));

        $this->assertNull($note->fresh()->transcript);
        Http::assertNothingSent();
    }

    public function test_job_noops_when_owner_toggle_off(): void
    {
        ['r' => $r] = $this->storeFixture();
        StoreOption::updateStoreOption($r->id, 'voice_note_transcription', '0');
        $note = $this->note($r, $this->order($r)->id);
        Http::fake();

        new TranscribeVoiceNote($note->id)->handle(app(AiService::class), app(AiProviderFactory::class));

        $this->assertNull($note->fresh()->transcript);
        Http::assertNothingSent();
    }

    public function test_job_swallows_provider_failure_without_throwing(): void
    {
        ['r' => $r] = $this->storeFixture();
        $note = $this->note($r, $this->order($r)->id);
        Http::fake(['api.groq.com/*' => Http::response('boom', 500)]);

        new TranscribeVoiceNote($note->id)->handle(app(AiService::class), app(AiProviderFactory::class));

        $this->assertNull($note->fresh()->transcript); // and no exception escaped
    }

    public function test_job_skips_already_transcribed_and_missing_notes(): void
    {
        ['r' => $r] = $this->storeFixture();
        $note = $this->note($r, $this->order($r)->id, ['transcript' => 'already here']);
        Http::fake();

        new TranscribeVoiceNote($note->id)->handle(app(AiService::class), app(AiProviderFactory::class));
        new TranscribeVoiceNote(999999)->handle(app(AiService::class), app(AiProviderFactory::class));

        $this->assertSame('already here', $note->fresh()->transcript);
        Http::assertNothingSent();
    }

    public function test_send_order_dispatches_transcription_after_response(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        foreach (['accept_voice_notes' => '1', 'store_submit_order_notification' => 0, 'quickorder_enable' => 0] as $k => $v) {
            StoreOption::updateStoreOption($r->id, $k, $v);
        }

        $file = UploadedFile::fake()->create('n.webm', 100, 'audio/webm');
        $token = $this->post(route('store.voiceNote.upload', $r), ['voice_note' => $file, 'duration' => 6])
            ->assertOk()->json('token');

        Bus::fake([TranscribeVoiceNote::class]);

        $this->post(route('store.submitOrder', $r), [
            'items' => json_encode([['id' => $menu->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'tip_amount' => '0', 'name' => 'Jane', 'ordering-type' => 'takeaway',
            'phone-number' => '555-1', 'pay_via' => 'cod', 'voice_note_token' => $token,
        ])->assertOk()->assertJson(['success' => true]);

        $noteId = (int) OrderVoiceNote::where('token', $token)->value('id');
        Bus::assertDispatchedAfterResponse(TranscribeVoiceNote::class, fn ($job) => $job->voiceNoteId === $noteId);
    }

    public function test_send_order_does_not_dispatch_when_toggle_off(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        foreach (['accept_voice_notes' => '1', 'voice_note_transcription' => '0', 'store_submit_order_notification' => 0, 'quickorder_enable' => 0] as $k => $v) {
            StoreOption::updateStoreOption($r->id, $k, $v);
        }

        $file = UploadedFile::fake()->create('n.webm', 100, 'audio/webm');
        $token = $this->post(route('store.voiceNote.upload', $r), ['voice_note' => $file, 'duration' => 6])->json('token');

        Bus::fake([TranscribeVoiceNote::class]);

        $this->post(route('store.submitOrder', $r), [
            'items' => json_encode([['id' => $menu->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'tip_amount' => '0', 'name' => 'Jane', 'ordering-type' => 'takeaway',
            'phone-number' => '555-1', 'pay_via' => 'cod', 'voice_note_token' => $token,
        ])->assertOk();

        Bus::assertNotDispatched(TranscribeVoiceNote::class);
        Bus::assertNotDispatchedAfterResponse(TranscribeVoiceNote::class);
    }

    public function test_staff_payloads_include_transcript(): void
    {
        ['r' => $r, 'owner' => $owner] = $this->storeFixture();
        $order = $this->order($r);
        $this->note($r, $order->id, ['transcript' => 'extra spicy']);

        // Staff API shape (FormatsOrders::orderDetail).
        $formatter = new class
        {
            use FormatsOrders;

            public function detail(Order $o): array
            {
                return $this->orderDetail($o);
            }
        };
        $this->assertSame('extra spicy', $formatter->detail($order->fresh())['voice_note_transcript']);

        // KDS poll payload.
        $this->withoutMiddleware();
        $row = collect($this->actingAs($owner)
            ->getJson(route('kds.poll', ['store_id' => $r->id]))
            ->assertOk()
            ->json())->firstWhere('id', $order->id);

        $this->assertSame('extra spicy', $row['voice_note_transcript'] ?? null);
    }
}

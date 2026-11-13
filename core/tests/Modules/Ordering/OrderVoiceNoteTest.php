<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\AbuseHoldWall;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\AbuseRateLimit;
use Juwri\Core\Http\Middleware\BranchRateLimit;
use Juwri\Core\Http\Middleware\SyncDashboardStore;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Models\OrderVoiceNote;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Tests\Support\EditionTestHooks;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * FEATURES-5 Track C — customer order voice notes.
 */
class OrderVoiceNoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Storage::fake('local');
        // Disable blocking middleware but KEEP StartSession so the customer-own
        // replay guard has a session to read/write.
        $this->withoutMiddleware([
            PreventRequestForgery::class,
            ThrottleRequests::class,
            AbuseRateLimit::class,
            AbuseHoldWall::class,
            BranchRateLimit::class,
            EnsureStaffDashboardPermission::class,
            SyncDashboardStore::class,
            ...EditionTestHooks::middleware(),
            LaravelLocalizationRedirectFilter::class,
            Installed::class,
            AuthenticateSession::class,
        ]);
    }

    private function storeFixture(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999, 'scan_limit' => 999, 'allow_ordering' => '1', 'hide_branding' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
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

    private function opt(Store $r, array $kv): void
    {
        foreach ($kv as $k => $v) {
            StoreOption::updateStoreOption($r->id, $k, $v);
        }
    }

    private function payload(Menu $menu, array $extra = []): array
    {
        return array_merge([
            'items' => json_encode([['id' => $menu->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'tip_amount' => '0', 'name' => 'Jane', 'ordering-type' => 'takeaway',
            'phone-number' => '555-1', 'pay_via' => 'cod',
        ], $extra);
    }

    public function test_upload_rejected_when_toggle_off(): void
    {
        ['r' => $r] = $this->storeFixture();
        // toggle default OFF
        $file = UploadedFile::fake()->create('n.webm', 100, 'audio/webm');
        $this->post(route('store.voiceNote.upload', $r), ['voice_note' => $file, 'duration' => 5])
            ->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame(0, OrderVoiceNote::count());
    }

    public function test_upload_succeeds_returns_token_when_toggle_on(): void
    {
        ['r' => $r] = $this->storeFixture();
        $this->opt($r, ['accept_voice_notes' => '1']);
        $file = UploadedFile::fake()->create('n.webm', 100, 'audio/webm');
        $res = $this->post(route('store.voiceNote.upload', $r), ['voice_note' => $file, 'duration' => 8])
            ->assertOk()->assertJson(['success' => true]);
        $token = $res->json('token');
        $this->assertNotEmpty($token);
        $this->assertGreaterThanOrEqual(40, strlen($token));
        $note = OrderVoiceNote::where('token', $token)->firstOrFail();
        $this->assertNull($note->order_id);
        $this->assertSame(8, $note->duration_seconds);
        Storage::disk('local')->assertExists($note->relativePath());
    }

    public function test_upload_rejects_bad_mime(): void
    {
        ['r' => $r] = $this->storeFixture();
        $this->opt($r, ['accept_voice_notes' => '1']);
        $file = UploadedFile::fake()->create('n.exe', 100, 'application/octet-stream');
        $this->post(route('store.voiceNote.upload', $r), ['voice_note' => $file, 'duration' => 5])
            ->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame(0, OrderVoiceNote::count());
    }

    public function test_upload_rejects_oversize(): void
    {
        ['r' => $r] = $this->storeFixture();
        $this->opt($r, ['accept_voice_notes' => '1']);
        // 3 MB > 2 MB cap
        $file = UploadedFile::fake()->create('n.webm', 3000, 'audio/webm');
        $this->post(route('store.voiceNote.upload', $r), ['voice_note' => $file, 'duration' => 5])
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_upload_rejects_overlong_duration(): void
    {
        ['r' => $r] = $this->storeFixture();
        $this->opt($r, ['accept_voice_notes' => '1']);
        $file = UploadedFile::fake()->create('n.webm', 100, 'audio/webm');
        $this->post(route('store.voiceNote.upload', $r), ['voice_note' => $file, 'duration' => 120])
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_send_order_attaches_voice_note_by_token(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $this->opt($r, ['accept_voice_notes' => '1', 'store_submit_order_notification' => 0, 'quickorder_enable' => 0]);
        $file = UploadedFile::fake()->create('n.webm', 100, 'audio/webm');
        $token = $this->post(route('store.voiceNote.upload', $r), ['voice_note' => $file, 'duration' => 6])
            ->assertOk()->json('token');

        $this->post(route('store.submitOrder', $r), $this->payload($menu, ['voice_note_token' => $token]))
            ->assertOk()->assertJson(['success' => true]);

        $order = Order::where('store_id', $r->id)->latest('id')->firstOrFail();
        $note = OrderVoiceNote::where('token', $token)->firstOrFail();
        $this->assertSame((int) $order->id, (int) $note->order_id);
        $this->assertNotNull($order->voiceNote);
    }

    public function test_staff_stream_authorized_for_owner(): void
    {
        ['owner' => $owner, 'r' => $r, 'menu' => $menu] = $this->storeFixture();
        $this->opt($r, ['accept_voice_notes' => '1', 'store_submit_order_notification' => 0, 'quickorder_enable' => 0]);
        $file = UploadedFile::fake()->create('n.webm', 100, 'audio/webm');
        $token = $this->post(route('store.voiceNote.upload', $r), ['voice_note' => $file, 'duration' => 6])->json('token');
        $this->post(route('store.submitOrder', $r), $this->payload($menu, ['voice_note_token' => $token]))->assertOk();
        $order = Order::where('store_id', $r->id)->latest('id')->firstOrFail();

        $this->actingAs($owner)
            ->get(route('stores.orders.voiceNote', $order))
            ->assertOk()
            ->assertHeader('Accept-Ranges', 'bytes');
    }

    public function test_staff_stream_forbidden_for_foreign_owner(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $this->opt($r, ['accept_voice_notes' => '1', 'store_submit_order_notification' => 0, 'quickorder_enable' => 0]);
        $file = UploadedFile::fake()->create('n.webm', 100, 'audio/webm');
        $token = $this->post(route('store.voiceNote.upload', $r), ['voice_note' => $file, 'duration' => 6])->json('token');
        $this->post(route('store.submitOrder', $r), $this->payload($menu, ['voice_note_token' => $token]))->assertOk();
        $order = Order::where('store_id', $r->id)->latest('id')->firstOrFail();

        $stranger = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->actingAs($stranger)
            ->get(route('stores.orders.voiceNote', $order))
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
    }

    public function test_customer_can_replay_own_note_but_not_others(): void
    {
        ['r' => $r] = $this->storeFixture();
        $this->opt($r, ['accept_voice_notes' => '1']);
        $file = UploadedFile::fake()->create('n.webm', 100, 'audio/webm');
        $token = $this->post(route('store.voiceNote.upload', $r), ['voice_note' => $file, 'duration' => 6])->json('token');

        // Same session (owns token) -> allowed.
        $this->get(route('store.voiceNote.customerStream', ['store' => $r->id, 'token' => $token]))
            ->assertOk();

        // Fresh session (flush) -> forbidden.
        $this->flushSession();
        $this->get(route('store.voiceNote.customerStream', ['store' => $r->id, 'token' => $token]))
            ->assertForbidden();
    }

    public function test_signed_stream_valid_signature_ok_and_tampered_forbidden(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->storeFixture();
        $this->opt($r, ['accept_voice_notes' => '1', 'store_submit_order_notification' => 0, 'quickorder_enable' => 0]);
        $file = UploadedFile::fake()->create('n.webm', 100, 'audio/webm');
        $token = $this->post(route('store.voiceNote.upload', $r), ['voice_note' => $file, 'duration' => 6])->json('token');
        $this->post(route('store.submitOrder', $r), $this->payload($menu, ['voice_note_token' => $token]))->assertOk();
        $order = Order::where('store_id', $r->id)->latest('id')->firstOrFail();

        $signed = URL::temporarySignedRoute('voiceNote.signedStream', now()->addHour(), ['order' => $order->id]);
        $this->get($signed)->assertOk()->assertHeader('Accept-Ranges', 'bytes');

        // Tampered signature -> rejected by the `signed` middleware.
        $this->get($signed.'x')->assertForbidden();
    }

    public function test_orphan_prune_deletes_unattached_old_notes(): void
    {
        ['r' => $r] = $this->storeFixture();
        $this->opt($r, ['accept_voice_notes' => '1']);
        $file = UploadedFile::fake()->create('n.webm', 100, 'audio/webm');
        $token = $this->post(route('store.voiceNote.upload', $r), ['voice_note' => $file, 'duration' => 6])->json('token');
        $note = OrderVoiceNote::where('token', $token)->firstOrFail();
        // Age it beyond 24h.
        $note->created_at = now()->subHours(30);
        $note->save();

        $this->artisan('voice-notes:prune')->assertExitCode(0);
        $this->assertSame(0, OrderVoiceNote::where('token', $token)->count());
        Storage::disk('local')->assertMissing($note->relativePath());
    }
}

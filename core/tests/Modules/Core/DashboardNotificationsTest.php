<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Juwri\Modules\Payments\Models\Dispute;
use Juwri\Core\Models\Language;
use App\Models\MenuReview;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-8 — notification bell counts endpoint (context-scoped, cached) and the
 * dashboard layout endpoint's widget-order persistence.
 */
class DashboardNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware();
    }

    private function makeOwner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P ' . Str::random(6), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);
        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeStore(User $o): Store
    {
        $t = 'R ' . Str::random(6);
        return Store::create([
            'user_id' => $o->id, 'slug' => Str::slug($t) . '-' . Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $t, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_notification_counts_are_scoped_to_the_context_store(): void
    {
        $owner = $this->makeOwner();
        $a = $this->makeStore($owner);
        $b = $this->makeStore($owner);
        $menu = Menu::create(['store_id' => $a->id, 'name' => 'I', 'price' => 5, 'description' => 'T', 'active' => 1, 'position' => 1]);

        // Store A: 2 pending reviews, 1 open dispute, 1 waiting guest.
        MenuReview::create(['store_id' => $a->id, 'menu_id' => $menu->id, 'stars' => 5, 'status' => 'pending', 'reviewer_name' => 'X']);
        MenuReview::create(['store_id' => $a->id, 'menu_id' => $menu->id, 'stars' => 4, 'status' => 'pending', 'reviewer_name' => 'Y']);
        Dispute::create(['store_id' => $a->id, 'gateway' => 'stripe', 'external_id' => 'd1', 'amount' => 10, 'currency' => 'USD', 'status' => 'needs_response', 'opened_at' => now()]);
        WaitlistEntry::create(['store_id' => $a->id, 'name' => 'G', 'phone' => '1', 'party_size' => 2, 'status' => 'waiting']);
        // Store B noise that must NOT leak into A's scope.
        Dispute::create(['store_id' => $b->id, 'gateway' => 'stripe', 'external_id' => 'd2', 'amount' => 10, 'currency' => 'USD', 'status' => 'needs_response', 'opened_at' => now()]);

        $res = $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $a->id])
            ->getJson(route('dashboard.notifications'));

        $res->assertOk()
            ->assertJson(['reviews' => 2, 'disputes' => 1, 'waitlist' => 1, 'total' => 4]);
    }

    public function test_new_orders_are_counted_and_scoped(): void
    {
        $owner = $this->makeOwner();
        $a = $this->makeStore($owner);
        $b = $this->makeStore($owner);

        $mk = function (Store $r, string $status, ?\Carbon\Carbon $at = null, ?\Carbon\Carbon $held = null) {
            $o = new \App\Models\Order();
            $o->forceFill(['store_id' => $r->id, 'type' => 'takeaway', 'status' => $status,
                'is_paid' => 0, 'source' => 'qr', 'held_at' => $held, 'created_at' => $at ?? now()])->save();
        };
        $mk($a, 'pending');                       // counted
        $mk($a, 'new');                           // counted
        $mk($a, 'preparing');                     // accepted → not counted
        $mk($a, 'new', now()->subDays(3));        // stale → not counted
        $mk($a, 'new', now(), now());             // held → not counted
        $mk($b, 'pending');                       // other store → not counted

        $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $a->id])
            ->getJson(route('dashboard.notifications'))
            ->assertOk()
            ->assertJsonPath('orders', 2);
    }

    public function test_search_returns_context_scoped_entities_only(): void
    {
        $owner = $this->makeOwner();
        $a = $this->makeStore($owner);
        $b = $this->makeStore($owner);
        \Juwri\Modules\Menu\Models\Menu::create(['store_id' => $a->id, 'name' => 'Margherita Pizza', 'price' => 5, 'description' => 'T', 'active' => 1, 'position' => 1]);
        \Juwri\Modules\Menu\Models\Menu::create(['store_id' => $b->id, 'name' => 'Margherita Special', 'price' => 6, 'description' => 'T', 'active' => 1, 'position' => 1]);

        $res = $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $a->id])
            ->getJson(route('dashboard.search', ['q' => 'Margherita']));

        $res->assertOk();
        $labels = collect($res->json('groups'))->firstWhere('key', 'items')['items'] ?? [];
        $names = array_column($labels, 'label');
        $this->assertContains('Margherita Pizza', $names);       // context store A
        $this->assertNotContains('Margherita Special', $names);  // store B must NOT leak
    }

    public function test_search_is_owner_scoped_no_cross_tenant_leak(): void
    {
        $me = $this->makeOwner();
        $mine = $this->makeStore($me);
        $other = $this->makeOwner();
        $theirs = $this->makeStore($other);
        \Juwri\Modules\Menu\Models\Menu::create(['store_id' => $theirs->id, 'name' => 'SecretDish', 'price' => 5, 'description' => 'T', 'active' => 1, 'position' => 1]);

        // scope=all (no context store) still limited to MY accessible stores.
        $res = $this->actingAs($me)->getJson(route('dashboard.search', ['q' => 'SecretDish']));
        $res->assertOk();
        $items = collect($res->json('groups'))->firstWhere('key', 'items')['items'] ?? [];
        $this->assertEmpty($items);
    }

    public function test_sound_library_lists_audio_files(): void
    {
        $lib = notification_sound_library();
        $this->assertNotEmpty($lib);
        $this->assertArrayHasKey('id', $lib[0]);
        $this->assertArrayHasKey('label', $lib[0]);
        // Every entry is a real playable file.
        foreach ($lib as $s) {
            $this->assertMatchesRegularExpression('/\.(mp3|ogg|wav)$/i', $s['id']);
        }
    }

    public function test_sound_settings_save_and_merge_over_defaults(): void
    {
        $owner = $this->makeOwner();
        $a = $this->makeStore($owner);
        $lib = notification_sound_library();
        $pick = $lib[0]['id'];

        $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $a->id])
            ->postJson(route('dashboard.sounds.save'), [
                'volume' => 45,
                'events' => [
                    'order_new' => ['sound' => $pick, 'repeat' => true, 'interval' => 15],
                    'review'    => ['sound' => '', 'repeat' => false],
                ],
            ])->assertOk()->assertJsonPath('ok', true);

        $cfg = notification_sounds_config($a->id);
        $this->assertSame(45, $cfg['volume']);
        $this->assertSame($pick, $cfg['events']['order_new']['sound']);
        $this->assertTrue($cfg['events']['order_new']['repeat']);
        $this->assertSame(15, $cfg['events']['order_new']['interval']);
        $this->assertSame('', $cfg['events']['review']['sound']); // muted event persists

        // Unknown sound id → 422.
        $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $a->id])
            ->postJson(route('dashboard.sounds.save'), [
                'volume' => 50, 'events' => ['order_new' => ['sound' => '../../etc/passwd']],
            ])->assertStatus(422);
    }

    public function test_custom_sound_upload_appears_in_library_and_deletes(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $owner = $this->makeOwner();
        $a = $this->makeStore($owner);

        $file = \Illuminate\Http\UploadedFile::fake()->create('my-ding.mp3', 20, 'audio/mpeg');
        $res = $this->actingAs($owner)->withSession(['dashboard_store_id' => $a->id])
            ->post(route('dashboard.sounds.upload'), ['sound' => $file])
            ->assertOk();

        $lib = $res->json('library');
        $custom = collect($lib)->firstWhere('custom', true);
        $this->assertNotNull($custom, 'custom sound present in library');
        $this->assertStringStartsWith('/storage/notif-sounds/' . $a->id . '/', $custom['id']);
        // A custom id is a valid choice at save time.
        $this->assertContains($custom['id'], collect(notification_sound_library($a->id))->pluck('id')->all());

        // Wrong type → 422.
        $this->actingAs($owner)->withSession(['dashboard_store_id' => $a->id])
            ->post(route('dashboard.sounds.upload'),
                ['sound' => \Illuminate\Http\UploadedFile::fake()->create('x.txt', 5, 'text/plain')],
                ['Accept' => 'application/json'])
            ->assertStatus(422);

        // Delete removes it; cross-store id rejected.
        $this->actingAs($owner)->withSession(['dashboard_store_id' => $a->id])
            ->postJson(route('dashboard.sounds.delete'), ['id' => '/storage/notif-sounds/999999/other.mp3'])
            ->assertStatus(422);
        $this->actingAs($owner)->withSession(['dashboard_store_id' => $a->id])
            ->postJson(route('dashboard.sounds.delete'), ['id' => $custom['id']])
            ->assertOk();
        $this->assertEmpty(collect(notification_sound_library($a->id))->where('custom', true));
    }

    public function test_branch_override_resolves_over_store_layer(): void
    {
        $owner = $this->makeOwner();
        $a = $this->makeStore($owner);
        $branch = \Juwri\Core\Models\Branch::create(['store_id' => $a->id, 'name' => 'Downtown', 'active' => 1, 'position' => 1]);
        $lib = notification_sound_library();
        $storeSound = $lib[0]['id'];
        $branchSound = $lib[1]['id'];

        // Store-level layer.
        $this->actingAs($owner)->withSession(['dashboard_store_id' => $a->id])
            ->postJson(route('dashboard.sounds.save'), [
                'volume' => 40, 'events' => ['order_new' => ['sound' => $storeSound, 'repeat' => true, 'interval' => 25]],
            ])->assertOk();

        // Branch override for just order_new.
        $this->actingAs($owner)->withSession(['dashboard_store_id' => $a->id])
            ->postJson(route('dashboard.sounds.save'), [
                'branch_id' => $branch->id,
                'volume' => 90, 'events' => ['order_new' => ['sound' => $branchSound, 'repeat' => false, 'interval' => 30]],
            ])->assertOk();

        // Store config keeps its own values; branch config sees the override.
        $store = notification_sounds_config($a->id);
        $this->assertSame(40, $store['volume']);
        $this->assertSame($storeSound, $store['events']['order_new']['sound']);

        $br = notification_sounds_config($a->id, $branch->id);
        $this->assertSame(90, $br['volume']);
        $this->assertSame($branchSound, $br['events']['order_new']['sound']);
        $this->assertFalse($br['events']['order_new']['repeat']);

        // Unknown branch id falls back to the store layer.
        $this->assertSame(40, notification_sounds_config($a->id, 999999)['volume']);
    }

    public function test_quiet_hours_saved_and_bad_time_rejected(): void
    {
        $owner = $this->makeOwner();
        $a = $this->makeStore($owner);

        $this->actingAs($owner)->withSession(['dashboard_store_id' => $a->id])
            ->postJson(route('dashboard.sounds.save'), [
                'volume' => 60, 'events' => ['order_new' => ['sound' => '']],
                'quiet' => ['enabled' => true, 'from' => '22:30', 'to' => '06:15'],
            ])->assertOk();

        $q = notification_sounds_config($a->id)['quiet'];
        $this->assertTrue($q['enabled']);
        $this->assertSame('22:30', $q['from']);
        $this->assertSame('06:15', $q['to']);

        // Malformed time → 422.
        $this->actingAs($owner)->withSession(['dashboard_store_id' => $a->id])
            ->postJson(route('dashboard.sounds.save'), [
                'volume' => 60, 'events' => ['order_new' => ['sound' => '']],
                'quiet' => ['enabled' => true, 'from' => '25:99', 'to' => '06:00'],
            ])->assertStatus(422);
    }

    public function test_layout_endpoint_persists_widget_order(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)->post(route('dashboard.layout'), [
            'cards' => ['trend', 'topitems', 'breakdowns', 'livestrip'],
            'order' => ['topitems', 'trend', 'livestrip', 'breakdowns', 'bogus'], '_ajax' => 1,
        ])->assertStatus(200);

        // 'bogus' filtered out; valid order preserved.
        $this->assertSame(
            ['topitems', 'trend', 'livestrip', 'breakdowns'],
            json_decode(UserOption::getUserOption($owner->id, 'dashboard_card_order'), true)
        );
    }
}

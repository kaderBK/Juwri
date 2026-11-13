<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Content\Http\Controllers\Studio\StudioController;
use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

/**
 * S4-P3: publishing an online-ordering menu is auth-gated (Google login), and
 * publishing migrates the guest session onto the account + persists the menu.
 */
class StudioPublishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    public function test_publish_requires_auth(): void
    {
        // Guest hitting publish is bounced to login (the Google-login gate).
        $this->post('/studio/publish', [])->assertStatus(302);
    }

    public function test_publish_migrates_session_and_creates_menu(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $session = StudioSession::create(['guest_token' => Str::random(48), 'output_type' => 'online', 'expires_at' => now()->addHours(48)]);
        Storage::disk('local')->put(
            $session->storagePath('draft.json'),
            json_encode(['categories' => [['name' => 'Mains', 'items' => [['name' => 'Burger', 'description' => 'Beef', 'price' => 9]]]]])
        );

        // Craft an authenticated request carrying the guest cookie (deterministic).
        $req = Request::create('/studio/publish', 'POST');
        $req->cookies->set('studio_guest', $session->guest_token);
        $req->setUserResolver(fn () => $user);

        $resp = new StudioController()->publish($req);
        $data = $resp->getData(true);

        $this->assertTrue($data['success']);
        $store = Store::where('user_id', $user->id)->first();
        $this->assertNotNull($store);
        $this->assertSame($store->slug, $data['slug']);
        $this->assertSame(1, MenuCategory::count());
        $this->assertSame('Burger', Menu::where('store_id', $store->id)->first()->name);

        $session->refresh();
        $this->assertSame($user->id, $session->user_id);   // migrated to account
        $this->assertSame('published', $session->status);
    }

    public function test_publish_without_draft_is_rejected(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $session = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);

        $req = Request::create('/studio/publish', 'POST');
        $req->cookies->set('studio_guest', $session->guest_token);
        $req->setUserResolver(fn () => $user);

        $resp = new StudioController()->publish($req);
        $this->assertSame(400, $resp->getStatusCode());
        $this->assertSame(0, MenuCategory::count());
    }
}

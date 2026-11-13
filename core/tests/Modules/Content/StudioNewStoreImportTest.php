<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Modules\Content\Services\Studio\StudioDraftImporter;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Task 3 — AI Studio → store creation. A signed-in owner who built a menu in
 * the public Studio (a draft keyed on the studio_guest cookie) gets it imported
 * into the new store, with variants/extras and plan limits, and the Studio
 * session is consumed so it can't re-import.
 */
class StudioNewStoreImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
        Storage::fake('local');
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'category_limit' => 999], 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function seedDraft(string $token, array $categories, ?int $userId = null): StudioSession
    {
        $session = StudioSession::create([
            'guest_token' => $token, 'status' => 'exploring', 'user_id' => $userId,
            'locale' => 'en', 'expires_at' => now()->addHours(48),
        ]);
        Storage::disk('local')->put("studio/{$token}/draft.json", json_encode(['categories' => $categories]));

        return $session;
    }

    private function store(int $userId): Store
    {
        return Store::create([
            'user_id' => $userId, 'slug' => 'x-'.Str::random(5), 'color' => '#111',
            'title' => 'X', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);
    }

    public function test_no_pending_draft_without_a_cookie(): void
    {
        $request = Request::create('/', 'GET');
        $this->assertFalse(app(StudioDraftImporter::class)->hasPendingDraft($request));
    }

    public function test_a_live_session_with_a_draft_is_pending(): void
    {
        $token = Str::random(48);
        $this->seedDraft($token, [['name' => 'Mains', 'items' => [['name' => 'Burger', 'price' => 10]]]]);
        $request = Request::create('/', 'GET', [], ['studio_guest' => $token]);
        $this->assertTrue(app(StudioDraftImporter::class)->hasPendingDraft($request));
    }

    public function test_import_seeds_the_store_menu_and_consumes_the_session(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner->id);

        $token = Str::random(48);
        $session = $this->seedDraft($token, [
            ['name' => 'Mains', 'items' => [
                ['name' => 'Burger', 'price' => 10, 'description' => 'Juicy'],
                ['name' => 'Fries', 'price' => 5],
            ]],
            ['name' => 'Drinks', 'items' => [['name' => 'Cola', 'price' => 3]]],
        ]);
        $request = Request::create('/', 'GET', [], ['studio_guest' => $token]);

        $counts = app(StudioDraftImporter::class)->importInto($store, $owner, $request);

        $this->assertSame(['categories' => 2, 'items' => 3], $counts);
        $this->assertSame(2, MenuCategory::where('store_id', $store->id)->count());
        $this->assertSame(3, Menu::where('store_id', $store->id)->count());
        $this->assertSame('published', $session->fresh()->status);
        $this->assertSame($owner->id, (int) $session->fresh()->user_id);
    }

    public function test_import_is_idempotent_once_consumed(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner->id);

        $token = Str::random(48);
        $this->seedDraft($token, [['name' => 'Mains', 'items' => [['name' => 'Burger', 'price' => 10]]]]);
        $request = Request::create('/', 'GET', [], ['studio_guest' => $token]);
        $importer = app(StudioDraftImporter::class);

        $importer->importInto($store, $owner, $request);
        // Second call: session is now 'published' → no double import.
        $second = $importer->importInto($store, $owner, $request);

        $this->assertNull($second);
        $this->assertSame(1, Menu::where('store_id', $store->id)->count());
    }

    public function test_create_wizard_offers_studio_when_no_draft(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->get(route('stores.create'))
            ->assertOk()
            ->assertSee('Start with AI Menu Studio');
    }

    /**
     * W2-IMPORTER (a): an authed owner whose account has a live draft session —
     * built on another device or after the guest cookie was cleared — is offered
     * the import even with NO studio_guest cookie on this request. The owner
     * fallback (StudioSessionService::ownedLiveSession) resolves it.
     */
    public function test_authed_owner_with_owned_draft_and_no_cookie_is_pending(): void
    {
        $owner = $this->owner();
        $this->seedDraft(Str::random(48), [
            ['name' => 'Mains', 'items' => [['name' => 'Burger', 'price' => 10]]],
        ], $owner->id);

        // No studio_guest cookie on the request — only the account link resolves it.
        $request = Request::create('/', 'GET');

        $this->actingAs($owner);
        $this->assertTrue(app(StudioDraftImporter::class)->hasPendingDraft($request));
    }

    /**
     * W2-IMPORTER (b): a guest cookie that names a session owned by ANOTHER user
     * is never imported into this owner's store — it is ignored as if absent, so
     * (with no session of their own) the current owner has no pending draft and
     * the import is a no-op.
     */
    public function test_a_different_users_cookie_draft_is_not_imported(): void
    {
        $userA = $this->owner();
        $userB = $this->owner();

        $store = $this->store($userA->id);

        // The cookie points at user B's owned draft session.
        $token = Str::random(48);
        $this->seedDraft($token, [
            ['name' => 'Mains', 'items' => [['name' => 'Burger', 'price' => 10]]],
        ], $userB->id);
        $request = Request::create('/', 'GET', [], ['studio_guest' => $token]);

        $this->actingAs($userA);
        $importer = app(StudioDraftImporter::class);

        $this->assertFalse($importer->hasPendingDraft($request));
        $this->assertNull($importer->importInto($store, $userA, $request));
        $this->assertSame(0, MenuCategory::where('store_id', $store->id)->count());
        $this->assertSame(0, Menu::where('store_id', $store->id)->count());
    }
}

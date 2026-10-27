<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * WA-D3: the owner-facing notice for legacy WhatsApp numbers 2–10.
 *
 * The migration deliberately never places these on a branch — only the merchant
 * knows which physical location each belongs to, and inventing a branch to hold
 * one would be worse than asking. 168 stores are affected.
 */
class WhatsAppExtraNumbersNoticeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
    }

    private function store(): Store
    {
        $this->owner = User::factory()->create(['status' => 1]);

        return Store::create([
            'user_id' => $this->owner->id,
            'slug' => 'r-'.Str::lower(Str::random(8)),
            'color' => '#111',
            'title' => 'WA Extras Test',
            'sub_title' => 't',
            'description' => 't',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 St',
            'phone' => '123',
        ]);
    }

    public function test_it_lists_every_extra_number_the_store_still_has(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'whatsapp_number', '+11111111111');
        StoreOption::updateStoreOption($store->id, 'whatsapp_number2', '+22222222222');
        StoreOption::updateStoreOption($store->id, 'whatsapp_number5', '+55555555555');

        // Number 1 is on the branch already and is NOT an "extra".
        $this->assertSame(['+22222222222', '+55555555555'], whatsapp_extra_numbers($store->id));
    }

    public function test_a_store_with_only_the_main_number_has_no_extras(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'whatsapp_number', '+11111111111');

        $this->assertSame([], whatsapp_extra_numbers($store->id));
    }

    public function test_a_store_with_no_numbers_at_all_has_no_extras(): void
    {
        // Guards the getStoreOption()-returns-FALSE trap: an absent option must not
        // be counted as an extra number.
        $this->assertSame([], whatsapp_extra_numbers($this->store()->id));
    }

    public function test_dismissing_the_notice_persists_server_side(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'whatsapp_number2', '+22222222222');

        $this->assertFalse(whatsapp_extras_notice_dismissed($store->id));

        $this->actingAs($this->owner)
            ->post(route('stores.whatsappExtras.dismiss', $store->id))
            ->assertRedirect();

        // Server-side, so it survives a different device — not localStorage.
        $this->assertTrue(whatsapp_extras_notice_dismissed($store->id));
    }

    public function test_dismissing_twice_is_harmless(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'whatsapp_number2', '+22222222222');

        $this->actingAs($this->owner)->post(route('stores.whatsappExtras.dismiss', $store->id));
        $this->actingAs($this->owner)->post(route('stores.whatsappExtras.dismiss', $store->id))->assertRedirect();

        $this->assertTrue(whatsapp_extras_notice_dismissed($store->id));
    }

    public function test_a_stranger_cannot_dismiss_someone_elses_notice(): void
    {
        $store = $this->store();
        $intruder = User::factory()->create(['status' => 1]);

        $this->actingAs($intruder)
            ->post(route('stores.whatsappExtras.dismiss', $store->id))
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)

        $this->assertFalse(whatsapp_extras_notice_dismissed($store->id));
    }
}

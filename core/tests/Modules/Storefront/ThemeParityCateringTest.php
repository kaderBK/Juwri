<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GAPS-RUN-2 TP-P1: the shared catering-form partial that all 6 non-crave active
 * themes include renders (gated) and points at the public catering endpoint.
 */
class ThemeParityCateringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function storeFixture(bool $catering): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($catering) {
            StoreOption::updateStoreOption($r->id, 'catering_enabled', '1');
        }

        return $r;
    }

    private function render(Store $r): string
    {
        $opts = store_options($r->id);

        return view('store_templates._partials.catering-form', ['store' => $r, 'postOptions' => (object) $opts])->render();
    }

    public function test_partial_shows_when_enabled_and_gated_on(): void
    {
        config(['settings.admin_allow_catering' => true]);
        $r = $this->storeFixture(true);
        $html = $this->render($r);
        // Stable, translation-independent markers: the endpoint + form fields.
        $this->assertStringContainsString($r->slug.'/catering', $html);
        $this->assertStringContainsString('name="headcount"', $html);
        $this->assertStringContainsString('name="event_at"', $html);
    }

    public function test_partial_hidden_when_owner_disabled(): void
    {
        config(['settings.admin_allow_catering' => true]);
        $r = $this->storeFixture(false);
        $this->assertStringNotContainsString('name="headcount"', $this->render($r));
    }

    public function test_partial_hidden_when_killswitch_off(): void
    {
        config(['settings.admin_allow_catering' => false]);
        $r = $this->storeFixture(true);
        $this->assertStringNotContainsString('name="headcount"', $this->render($r));
    }

    /** C2: the deposit expectation note shows only when the store takes catering deposits. */
    public function test_deposit_note_shows_when_catering_deposit_enabled(): void
    {
        config(['settings.admin_allow_catering' => true]);
        $r = $this->storeFixture(true);
        StoreOption::updateStoreOption($r->id, 'catering_deposit_enabled', '1');

        $html = $this->render($r);
        $this->assertStringContainsString('data-catering-deposit-note', $html, 'the deposit note renders');
        // No payment UI on the customer form — the note is expectation-only.
        $this->assertStringNotContainsString('catering.deposit', $html);
    }

    /** C2: no deposit configured → no note (so it never mis-sets the expectation). */
    public function test_deposit_note_absent_when_deposit_disabled(): void
    {
        config(['settings.admin_allow_catering' => true]);
        $r = $this->storeFixture(true); // catering on, but NO catering_deposit_enabled

        $this->assertStringNotContainsString('data-catering-deposit-note', $this->render($r));
    }

    public function test_every_active_theme_includes_the_partial(): void
    {
        foreach (['matcha', 'glow', 'aurora', 'vivid', 'feast', 'velvet'] as $theme) {
            $src = file_get_contents(resource_path("views/store_templates/{$theme}/index.blade.php"));
            $this->assertStringContainsString('store_templates._partials.catering-form', $src, "$theme includes the catering partial");
        }
    }
}

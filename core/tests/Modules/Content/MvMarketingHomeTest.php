<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * rev/mv-marketing — the home page presents the multi-vertical capability:
 * a "business types" section links each seeded demo store, and the hero social
 * proof no longer claims "restaurants" only. The section is existence-guarded,
 * so it never advertises a demo that isn't seeded on this host.
 */
class MvMarketingHomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function demoStore(string $slug, string $type): Store
    {
        return Store::create([
            'user_id' => User::factory()->create(['status' => 1, 'email_verified_at' => now()])->id,
            'store_type' => $type, 'slug' => $slug, 'color' => '#111',
            'title' => Str::title(str_replace('-', ' ', $slug)), 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
    }

    public function test_verticals_section_lists_seeded_demos(): void
    {
        app()->setLocale('en');
        $this->demoStore('retail-demo', 'retail');
        $this->demoStore('pharmacy-demo', 'pharmacy');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Not a restaurant? Still yours.', $html);
        // Seeded demos are linked...
        $this->assertStringContainsString(route('publicView', 'retail-demo'), $html);
        $this->assertStringContainsString(route('publicView', 'pharmacy-demo'), $html);
        $this->assertStringContainsString('View live demo', $html);
        // ...and each card is also an internal link to that trade's industry page.
        $this->assertStringContainsString(route('industries.show', 'retail'), $html);
        $this->assertStringContainsString(route('industries.show', 'pharmacy'), $html);
        // ...unseeded ones are NOT (existence guard, no 404 links).
        $this->assertStringNotContainsString(route('publicView', 'clothing-demo'), $html);
    }

    public function test_section_is_hidden_when_no_demos_exist(): void
    {
        app()->setLocale('en');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('Not a restaurant? Still yours.', $html);
    }

    public function test_hero_social_proof_is_vertical_neutral(): void
    {
        app()->setLocale('en');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Trusted by businesses worldwide', $html);
        $this->assertStringNotContainsString('Trusted by restaurants worldwide', $html);
    }
}

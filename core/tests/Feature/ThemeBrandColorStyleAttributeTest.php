<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BL-036 R1 review (INFO): the orbit and lime storefronts print the theme's primary colour into a style= attribute.
 * A saved value that is not a hex colour must never reach it; the theme's own default is printed instead.
 */
class ThemeBrandColorStyleAttributeTest extends TestCase
{
    use RefreshDatabase;

    private const BREAKOUT = 'red;background:url(//x.example/a)';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function themes(): array
    {
        return [
            'orbit' => ['orbit', '--theme-color-1: ', '#f4633c'],
            'lime' => ['lime', '--lime-brand: ', '#cdf34b'],
        ];
    }

    private function store(string $theme, string $primary): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 't-'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => 'T', 'sub_title' => 'T',
            'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $theme);
        ThemeSetting::create(['store_id' => $store->id, 'template' => $theme, 'settings' => ['colors.primary' => $primary]]);

        return $store;
    }

    #[DataProvider('themes')]
    public function test_a_non_hex_primary_never_reaches_the_style_attribute(string $theme, string $prop, string $default): void
    {
        $html = (string) $this->get(route('publicView', $this->store($theme, self::BREAKOUT)->slug))->assertOk()->getContent();

        $this->assertStringNotContainsString('url(//x.example', $html, $theme);
        $this->assertStringContainsString($prop.$default, $html, $theme.' falls back to its own default');
    }

    #[DataProvider('themes')]
    public function test_a_hex_primary_is_printed(string $theme, string $prop, string $default): void
    {
        $html = (string) $this->get(route('publicView', $this->store($theme, '#1A2B3C')->slug))->assertOk()->getContent();

        $this->assertStringContainsString($prop.'#1a2b3c', $html, $theme);
        $this->assertStringNotContainsString($prop.$default, $html, $theme.': the saved colour wins over the default');
    }
}

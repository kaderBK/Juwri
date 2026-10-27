<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\MenuBanner;
use App\Models\MenuCategory;
use App\Models\PriceSchedule;
use App\Models\Story;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Tests\TestCase;

/**
 * BL-037d: eight synthetic demo stores, one per reference store shape, so a
 * theme can be screenshot-compared against a menu of a known size.
 */
class ThemeParitySeedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The reference shapes: slug => [template, top-level categories, items].
     *
     * @var array<string, array{0: string, 1: int, 2: int}>
     */
    private const SHAPES = [
        'parity-velora-a' => ['velora', 6, 56],
        'parity-velora-b' => ['velora', 7, 56],
        'parity-crave-a' => ['crave', 6, 56],
        'parity-crave-b' => ['crave', 6, 42],
        'parity-crumb-a' => ['crumb', 5, 12],
        'parity-crumb-b' => ['crumb', 5, 12],
        'parity-scoop-a' => ['scoop', 7, 56],
        'parity-scoop-b' => ['scoop', 5, 33],
    ];

    /** The command is a demo-data seeder: it never runs on a production host. */
    public function test_it_refuses_to_run_in_production_and_creates_nothing(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('juwri:theme-parity-seed')
            ->expectsOutputToContain('production')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, Store::where('slug', 'like', 'parity-%')->count());
        $this->assertSame(0, Menu::count());
    }

    /** Eight stores, each with the template and the size of its shape. */
    public function test_it_seeds_eight_stores_with_their_reference_shape(): void
    {
        $this->artisan('juwri:theme-parity-seed')
            ->expectsOutputToContain('parity-velora-a')
            ->assertSuccessful();

        $this->assertSame(8, Store::where('slug', 'like', 'parity-%')->count());

        foreach (self::SHAPES as $slug => [$template, $categories, $items]) {
            $store = Store::where('slug', $slug)->first();

            $this->assertNotNull($store, "$slug exists");
            $this->assertSame($template, StoreSettings::legacy((int) $store->id, 'store_template'), "$slug keeps its theme");
            $this->assertSame($this->title($slug, $template), $store->title, "$slug is titled");

            $this->assertSame(
                $categories,
                MenuCategory::where('store_id', $store->id)->whereNull('parent')->count(),
                "$slug has $categories top-level categories",
            );

            $rows = Menu::where('store_id', $store->id)->get();
            $this->assertSame($items, $rows->count(), "$slug has $items items");
            $this->assertSame($rows->count(), $rows->pluck('name')->unique()->count(), "$slug item names are unique");

            // Every item keeps its photo — a parity store is compared by eye.
            $this->assertTrue($rows->every(fn ($m) => ! empty($m->image)), "$slug items carry images");
            if (function_exists('imagecreatetruecolor') && $rows->isNotEmpty()) {
                $this->assertFileExists(public_path('storage/menu/'.$rows->first()->image));
            }
        }
    }

    /** Idempotent: a second run rebuilds the same eight stores, never more. */
    public function test_running_it_twice_leaves_the_same_eight_stores(): void
    {
        $this->artisan('juwri:theme-parity-seed')->assertSuccessful();
        $this->artisan('juwri:theme-parity-seed')->assertSuccessful();

        $this->assertSame(8, Store::where('slug', 'like', 'parity-%')->count());

        foreach (self::SHAPES as $slug => [$template, $categories, $items]) {
            $store = Store::where('slug', $slug)->first();

            $this->assertNotNull($store, "$slug still exists");
            $this->assertSame($categories, MenuCategory::where('store_id', $store->id)->whereNull('parent')->count());
            $this->assertSame($items, Menu::where('store_id', $store->id)->count());
        }
    }

    /** Lean shape per reference: no circle rail or offers anywhere, banners only on velora, splash on the five references that open on one. */
    public function test_lean_mode_switches_extras_off_per_reference(): void
    {
        $this->artisan('juwri:theme-parity-seed')->assertSuccessful();

        $splash = ['parity-crave-b', 'parity-crumb-a', 'parity-crumb-b', 'parity-scoop-a', 'parity-scoop-b'];
        foreach (self::SHAPES as $slug => [$template]) {
            $store = Store::where('slug', $slug)->first();
            $id = (int) $store->id;

            $this->assertSame(0, Story::where('store_id', $id)->count(), "$slug has no story rail");
            $this->assertSame(0, PriceSchedule::where('store_id', $id)->count(), "$slug has no offers strip");
            $this->assertSame($template === 'velora' ? 3 : 0, MenuBanner::where('store_id', $id)->count(), "$slug banners");

            $row = ThemeSetting::where('store_id', $id)->where('template', $template)->first();
            $this->assertNotNull($row, "$slug has a theme setting row");
            $this->assertSame(in_array($slug, $splash, true), $row->settings['splash.enabled'], "$slug splash");
        }

        $cover = Store::where('slug', 'parity-velora-a')->value('cover_image');
        $this->assertStringEndsWith('-wordmark.svg', (string) $cover);
        $this->assertFileExists(public_path('storage/store/cover/'.$cover));
    }

    /** --showcase keeps the full showcase extras. */
    public function test_showcase_option_keeps_the_extras(): void
    {
        $this->artisan('juwri:theme-parity-seed', ['--showcase' => true])->assertSuccessful();

        $id = (int) Store::where('slug', 'parity-crave-a')->value('id');
        $this->assertGreaterThan(0, Story::where('store_id', $id)->count());
        $this->assertGreaterThan(0, MenuBanner::where('store_id', $id)->count());
    }

    /** "parity-velora-a" + "velora" => "Parity Velora A". */
    private function title(string $slug, string $template): string
    {
        $parts = explode('-', $slug);

        return 'Parity '.ucfirst($template).' '.strtoupper((string) end($parts));
    }
}

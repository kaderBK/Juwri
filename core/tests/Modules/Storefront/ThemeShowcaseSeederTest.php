<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\MenuBanner;
use App\Models\MenuCategory;
use App\Models\MenuReview;
use App\Models\PriceSchedule;
use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Menu\Services\Availability\MenuScheduleService;
use Juwri\Modules\Storefront\Database\Seeders\ThemeShowcaseSeeder;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\Support\PinsMiddayClock;
use Tests\TestCase;

/**
 * WI-G2: one full themed demo store per modern template, idempotent.
 */
class ThemeShowcaseSeederTest extends TestCase
{
    use PinsMiddayClock;
    use RefreshDatabase;

    public function test_seeds_seventeen_themed_stores_idempotently(): void
    {
        $this->seed(ThemeShowcaseSeeder::class);
        $this->seed(ThemeShowcaseSeeder::class); // second run must not duplicate

        $templates = KeptTemplates::MODERN;

        foreach ($templates as $t) {
            $store = Store::where('slug', $t.'-demo')->first();
            $this->assertNotNull($store, "store for $t exists");
            $this->assertSame($t, StoreSettings::legacy((int) $store->id, 'store_template'));
            $this->assertGreaterThan(0, MenuCategory::where('store_id', $store->id)->count(), "$t has categories");
            $this->assertGreaterThan(0, Menu::where('store_id', $store->id)->count(), "$t has items");
        }

        // Idempotent: exactly 17 stores, no duplicates.
        $this->assertSame(17, Store::where('slug', 'like', '%-demo')->count());

        // Real image files, not dead URLs: items carry an image whose file exists.
        if (function_exists('imagecreatetruecolor')) {
            $item = Menu::whereIn('store_id', Store::where('slug', 'like', '%-demo')->pluck('id'))
                ->whereNotNull('image')->where('image', '!=', '')->first();
            $this->assertNotNull($item, 'demo items carry an image');
            $this->assertFileExists(public_path('storage/menu/'.$item->image));

            // Logo and cover must land in the dirs the dashboard list and the
            // storefront layout read them from — storage/store/logo|cover,
            // NOT menu/. Every store copied into menu/ left all 17 logos and
            // covers 404ing on /restaurants.
            foreach (Store::where('slug', 'like', '%-demo')->get() as $store) {
                $this->assertFileExists(
                    public_path('storage/store/logo/'.$store->main_image),
                    "{$store->slug} logo exists where the view reads it"
                );
                $this->assertFileExists(
                    public_path('storage/store/cover/'.$store->cover_image),
                    "{$store->slug} cover exists where the view reads it"
                );
            }
        }
    }

    /** Flagships (crave/velora/ember) are full professional stores, not starters. */
    public function test_flagship_stores_are_professional_depth(): void
    {
        $this->seed(ThemeShowcaseSeeder::class);
        $this->seed(ThemeShowcaseSeeder::class); // pro path wipes + rebuilds — must stay stable

        $mins = ['crave' => 100, 'velora' => 40, 'ember' => 30, 'matcha' => 15, 'glow' => 15,
            'aurora' => 15, 'vivid' => 15, 'feast' => 15, 'velvet' => 15, 'dough' => 15,
            'plate' => 15, 'lime' => 15, 'picnic' => 15, 'orbit' => 15, 'crumb' => 15,
            'soiree' => 15, 'scoop' => 15];
        foreach ($mins as $t => $minItems) {
            $store = Store::where('slug', $t.'-demo')->firstOrFail();
            $rid = $store->id;

            // Category images everywhere; two-tier nav where the menu warrants it.
            $this->assertGreaterThan(0, MenuCategory::where('store_id', $rid)->whereNotNull('image')->where('image', '!=', '')->count(), "$t categories carry images");
            if (in_array($t, ['crave', 'velora', 'ember', 'dough'], true)) {
                $this->assertGreaterThan(0, MenuCategory::where('store_id', $rid)->whereNotNull('parent')->count(), "$t has sub-categories");
            }

            // Item depth + no duplicates from the double seed.
            $items = Menu::where('store_id', $rid)->get();
            $this->assertGreaterThanOrEqual($minItems, $items->count(), "$t has a full menu");
            $this->assertSame($items->count(), $items->pluck('name')->unique()->count(), "$t items not duplicated");

            // Rich metadata made it to the rows.
            $this->assertTrue($items->contains(fn ($m) => $m->calories > 0), "$t items carry calories");
            $this->assertTrue($items->contains(fn ($m) => ! empty($m->gallery_images)), "$t items carry galleries");
            $this->assertTrue($items->contains(fn ($m) => $m->getRawOriginal('allergies') !== '' && $m->getRawOriginal('allergies') !== null), "$t items carry allergies");
            $this->assertTrue($items->contains(fn ($m) => ! empty($m->translations->ar->title)), "$t items carry Arabic translations");
            $this->assertTrue($items->contains(fn ($m) => (float) $m->rating_avg > 0), "$t items carry ratings");

            // Library option groups with additive prices + attached to items.
            $groups = MenuVariantOption::where('store_id', $rid)->where('is_global', true)->get();
            $this->assertGreaterThanOrEqual(3, $groups->count(), "$t has library option groups");
            $this->assertTrue($groups->contains(fn ($g) => array_sum($g->option_prices ?? []) > 0), "$t groups carry option prices");
            $groupAttach = \DB::table('item_modifier_group')->whereNull('modifier_id')->whereIn('menu_id', $items->pluck('id'))->count();
            $this->assertGreaterThan(0, $groupAttach, "$t groups attached to items");

            // Priced add-ons with images + attached to items.
            $extras = MenuExtra::where('store_id', $rid)->where('is_global', true)->get();
            $this->assertGreaterThanOrEqual(4, $extras->count(), "$t has library extras");
            $this->assertTrue($extras->every(fn ($e) => ! empty($e->image)), "$t extras carry images");
            $extraAttach = \DB::table('item_modifier_group')->whereNotNull('modifier_id')->whereIn('menu_id', $items->pluck('id'))->count();
            $this->assertGreaterThan(0, $extraAttach, "$t extras attached to items");

            // Banners + reviews.
            $this->assertGreaterThanOrEqual(3, MenuBanner::where('store_id', $rid)->count(), "$t has banners");
            $this->assertGreaterThanOrEqual(5, MenuReview::where('store_id', $rid)->count(), "$t has reviews");

            // Bilingual storefront wired: switcher needs menu_languages + default_language.
            $this->assertSame(['ar', 'en'], json_decode(StoreSettings::legacy((int) $rid, 'menu_languages'), true), "$t offers ar+en");
            $this->assertSame('ar', StoreSettings::legacy((int) $rid, 'default_language'), "$t defaults to Arabic");

            // Stories carry real media (empty media_url renders an empty viewer).
            $stories = Story::where('store_id', $rid)->get();
            $this->assertGreaterThanOrEqual(3, $stories->count(), "$t has stories");
            $this->assertTrue($stories->every(fn ($s) => ! empty($s->media_url)), "$t stories carry media");

            // A live happy hour, so the offers strip has something to show. The
            // double seed above wipes the catalog, so this also proves the rule
            // is rebuilt against the CURRENT item ids rather than left dangling.
            $schedules = PriceSchedule::with('targets')->where('store_id', $rid)->get();
            $this->assertCount(1, $schedules, "$t has exactly one demo schedule after a double seed");
            $happy = $schedules->first();
            $this->assertTrue($happy->active, "$t happy hour is active");
            $this->assertSame('percent', $happy->adjustment_type, "$t happy hour is a percentage");
            // Five auto picks, plus any per-store happy_hour_extra pins (crumb pins
            // Baked Cheesecake). include (not require) re-returns the array, matching
            // the seeder's own load path.
            $pins = (include database_path("seeders/data/showcase/{$t}.php"))['store']['happy_hour_extra'] ?? [];
            $expectedTargets = 5 + count($pins);
            $this->assertCount($expectedTargets, $happy->targets, "$t happy hour covers $expectedTargets items");
            // A pinned item must survive the double-seed rebuild.
            $targetNames = Menu::whereIn('id', $happy->targets->pluck('target_id'))->pluck('name')->all();
            foreach ($pins as $pin) {
                $this->assertContains($pin, $targetNames, "$t happy hour includes pinned $pin");
            }
            $liveIds = $items->pluck('id')->all();
            foreach ($happy->targets as $target) {
                $this->assertContains((int) $target->target_id, $liveIds, "$t happy hour targets a live item");
            }
        }

    }

    /** The seeded rule must actually discount at render time, not merely exist. */
    public function test_happy_hour_produces_a_visible_discount(): void
    {
        $this->seed(ThemeShowcaseSeeder::class);

        $rid = Store::where('slug', 'crave-demo')->firstOrFail()->id;
        $targetIds = PriceSchedule::where('store_id', $rid)
            ->firstOrFail()->targets->pluck('target_id')->all();

        $menus = Menu::whereIn('id', $targetIds)->get();
        MenuScheduleService::flush();
        MenuScheduleService::decorate($menus, $rid);

        foreach ($menus as $menu) {
            // decorate() sets original_price ONLY while a schedule is live —
            // that field is the offers strip's whole signal.
            $this->assertNotNull($menu->original_price, "item {$menu->id} is discounted right now");
            $this->assertGreaterThan((float) $menu->price, (float) $menu->original_price, 'strike-through price is higher');
        }
    }
}

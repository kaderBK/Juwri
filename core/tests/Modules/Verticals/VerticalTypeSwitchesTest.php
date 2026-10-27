<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use App\Services\StorefrontThemeService;
use App\Services\Theme\VerticalThemeSets;
use App\Services\Verticals\FulfillmentRegistry;
use App\Services\Verticals\VerticalRegistry;
use App\Services\Verticals\VerticalVocab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\OwnerApp;
use Juwri\Modules\Ordering\Services\Fulfillment\FulfillmentSettingsService;
use Juwri\Modules\Ordering\Services\Orders\SchedulingService;
use Juwri\Modules\Storefront\Services\Theme\KitThemes;
use Juwri\Modules\Storefront\Services\ThemeStudio\Ai\StoreVars;
use Juwri\Modules\Storefront\Services\ThemeStudio\Ai\StudioThemes;
use Juwri\Modules\Storefront\Services\ThemeStudio\Recipes;
use Juwri\Modules\Verticals\Services\StoreVerticalConversionService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P3-00 part 2 — every store type x every place that branches on the store type.
 *
 * A store type is `config/verticals.php` plus the places below. This file runs each of the switches for EVERY
 * configured type (data provider), so a type added later fails here until it is wired everywhere:
 *
 *   S1  registry capabilities (group / item form / theme set / fulfilment modes valid)
 *   S2  vocabulary + store noun per group (VerticalVocab, config verticals.vocab / noun)
 *   S3  storefront theme set: inherited templates of the type's set exist (VerticalThemeSets, StorefrontThemeService)
 *   S4  new-store wizard: radio, hint, icon, template heading, confirm note (owner.stores.create)
 *   S5  store creation persists the type and accepts a template of its theme set (StoreController::store)
 *   S6  convert / duplicate guard (StoreVerticalConversionService::guardTargetType)
 *   S7  owner app tabs (OwnerApp::tabsFor)
 *   S8  dashboard catalogue + order board + fulfilment settings pages (by item form / group)
 *   S9  scheduling covers every slot mode of the type (SchedulingService::TYPES / enabledTypes)
 *   S10 fulfilment settings service: available / enabled modes = what the type declares
 *   S11 Theme Studio: recipe candidates, demo dataset, studio theme group (Recipes, StudioThemes, StoreVars)
 *   S12 public storefront renders on the type's default inherited template
 */
class VerticalTypeSwitchesTest extends TestCase
{
    use RefreshDatabase;

    /** Add a type to config/verticals.php and this list together (the test below fails otherwise). */
    private const KNOWN_TYPES = ['restaurant', 'retail', 'cosmetic', 'clothing', 'carparts', 'laundry', 'pharmacy', 'cafe', 'grocery', 'services', 'hotel'];

    /** The inherited storefront template a type opens on until its kit theme has views (P3-05+). */
    private const DEFAULT_TEMPLATE = ['food' => 'matcha', 'retail' => 'zevoa', 'service' => 'bookla'];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** @return array<string, array{0: string}> */
    public static function types(): array
    {
        $types = array_keys((array) (require dirname(__DIR__, 3).'/config/verticals.php')['types']);

        return array_combine($types, array_map(fn (string $t) => [$t], $types));
    }

    private function owner(): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 9, 'menu_limit' => 999, 'category_limit' => 999], 'status' => 1]);
        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        EntitlementOverride::create(['user_id' => $user->id, 'feature_key' => 'multi_vertical', 'value' => '1', 'source_type' => 'admin']);
        FeatureAccess::flush();

        return $user;
    }

    private function store(User $owner, string $type): Store
    {
        $title = 'S'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'store_type' => $type, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_every_configured_type_is_covered_by_this_file(): void
    {
        $this->assertEqualsCanonicalizing(self::KNOWN_TYPES, array_keys((array) config('verticals.types')), 'a store type was added or removed: wire it into every switch above and update KNOWN_TYPES');
    }

    #[DataProvider('types')]
    public function test_s1_registry_capabilities_are_valid(string $type): void
    {
        $registry = app(VerticalRegistry::class);
        $config = $registry->config($type);

        $this->assertTrue($registry->exists($type));
        $this->assertContains($config['group'], ['food', 'goods', 'services', 'stays']);
        $this->assertContains($registry->itemForm($type), ['food', 'retail', 'service']);
        $this->assertContains($registry->themeSet($type), ['food', 'retail', 'service', 'hotel']);
        $this->assertNotSame([], $registry->fulfillmentModes($type));
        $catalog = app(FulfillmentRegistry::class);
        foreach ($registry->fulfillmentModes($type) as $mode) {
            $this->assertTrue($catalog->isValid($mode), "$type lists the undefined mode $mode");
        }
        $this->assertSame($registry->fulfillmentModes($type), $catalog->modesFor($type));
        $this->assertSame($registry->isFood($type), $config['group'] === 'food');
        $this->assertNotSame('', trim(___($config['label'])));
    }

    #[DataProvider('types')]
    public function test_s2_vocabulary_and_noun_exist_for_the_group(string $type): void
    {
        $group = (string) app(VerticalRegistry::class)->config($type)['group'];
        $vocab = (array) config('verticals.vocab');
        $noun = (array) config('verticals.noun');

        $this->assertArrayHasKey($group, $vocab);
        $this->assertArrayHasKey($group, $noun);
        foreach (['item', 'items', 'catalog', 'category', 'categories', 'create_item', 'add_to_cart', 'order', 'orders', 'board'] as $key) {
            $this->assertArrayHasKey($key, $vocab[$group]);
        }
        $this->assertNotSame('', trim((string) app(VerticalVocab::class)->map($type)['items']));
    }

    #[DataProvider('types')]
    public function test_s3_the_type_has_inherited_templates_in_its_theme_set(string $type): void
    {
        $sets = app(VerticalThemeSets::class);
        $wanted = app(VerticalRegistry::class)->themeSet($type);
        $offered = $sets->filterFor(app(StorefrontThemeService::class)->available(), $type);

        $this->assertNotSame([], $offered, "no template is offered to a $type store");
        foreach (array_keys($offered) as $slug) {
            $this->assertSame($wanted, $sets->setFor($slug));
        }
        if (isset(self::DEFAULT_TEMPLATE[$wanted])) {
            $this->assertArrayHasKey(self::DEFAULT_TEMPLATE[$wanted], $offered);
        }
    }

    public function test_s3b_kit_theme_slugs_have_a_set_that_matches_their_store_types(): void
    {
        $sets = app(VerticalThemeSets::class);
        $this->assertSame('food', $sets->setFor('table'));
        $this->assertSame('food', $sets->setFor('brew'));
        $this->assertSame('retail', $sets->setFor('aisle'));
        $this->assertSame('retail', $sets->setFor('basket'));
        $this->assertSame('service', $sets->setFor('book'));
        foreach (KitThemes::SLUGS as $slug) {
            $this->assertNotNull($sets->setFor($slug));
        }
        // The kit's store-type defaults: cafe -> brew, grocery -> basket, services -> book, restaurant -> table, retail -> aisle.
        $registry = app(VerticalRegistry::class);
        $this->assertSame($sets->setFor('brew'), $registry->themeSet('cafe'));
        $this->assertSame($sets->setFor('basket'), $registry->themeSet('grocery'));
        $this->assertSame($sets->setFor('book'), $registry->themeSet('services'));
        $this->assertSame($sets->setFor('table'), $registry->themeSet('restaurant'));
        $this->assertSame($sets->setFor('aisle'), $registry->themeSet('retail'));
    }

    #[DataProvider('types')]
    public function test_s4_wizard_card_has_radio_hint_icon_heading_and_note(string $type): void
    {
        $html = $this->actingAs($this->owner())->get(route('stores.create'))->assertOk()->getContent();
        $this->assertStringContainsString('value="'.$type.'"', $html);

        // The card of this type: from its radio on (the hint follows within the card).
        $card = substr($html, strpos($html, 'value="'.$type.'"'), 1500);
        $this->assertMatchesRegularExpression('/ui-create__type-hint">\s*\S/', $card, "the $type card has no hint");

        // The four Alpine maps keyed by type (verticalThemeSets, typeLabels, templateHeadings, confirmNotes); hotel has no
        // entry in the last two and opens the menu wording of the default (pre-existing).
        $keyed = preg_match_all('/(?:&quot;|\\\\u0022|")'.$type.'(?:&quot;|\\\\u0022|")\s*:/', $html);
        $this->assertSame($type === 'hotel' ? 2 : 4, $keyed, "a wizard map lacks $type");
    }

    #[DataProvider('types')]
    public function test_s5_creating_a_store_persists_the_type(string $type): void
    {
        $user = $this->owner();
        $sets = app(VerticalThemeSets::class);
        $template = array_key_first($sets->filterFor(app(StorefrontThemeService::class)->available(), $type));

        $this->actingAs($user)->from(route('stores.create'))->post(route('stores.store'), [
            'color' => '#111111',
            'title' => 'New '.$type,
            'description' => 'A '.$type,
            'address' => '1 St',
            'store_template' => $template,
            'store_type' => $type,
            'cover_image' => UploadedFile::fake()->image('cover.jpg', 800, 400),
            'main_image' => UploadedFile::fake()->image('logo.jpg', 500, 500),
        ]);

        $store = Store::where('user_id', $user->id)->where('title', 'New '.$type)->first();
        $this->assertNotNull($store, "a $type store was not created");
        $this->assertSame($type, $store->store_type);
    }

    #[DataProvider('types')]
    public function test_s6_convert_and_duplicate_guard(string $type): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'restaurant');
        $error = app(StoreVerticalConversionService::class)->guardTargetType($store, $owner, $type);

        if ($type === 'hotel') {
            $this->assertNotNull($error);
        } else {
            $this->assertNull($error, "a $type store cannot be a convert/duplicate target: $error");
        }
    }

    #[DataProvider('types')]
    public function test_s7_owner_app_tabs_have_a_work_and_a_catalogue_tab(string $type): void
    {
        $store = $this->store($this->owner(), $type);
        $tabs = OwnerApp::tabsFor($store);

        $this->assertSame(['home', 'orders', 'catalog', 'reports', 'more'], array_column($tabs, 'key'));
        $this->assertNotNull($tabs[1]['path'], "$type has no orders tab path");
        $this->assertNotNull($tabs[2]['path'], "$type has no catalogue tab path");
    }

    #[DataProvider('types')]
    public function test_s8_dashboard_pages_open_for_the_type(string $type): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, $type);
        $registry = app(VerticalRegistry::class);
        $this->actingAs($owner);

        $this->get(route('stores.index'))->assertOk()->assertSee($store->title);

        $catalogue = match ($registry->itemForm($type)) {
            'retail' => route('retail.products.index', $store),
            'service' => route('laundry.services.index', $store),
            default => route('stores.menu', $store),
        };
        $this->get($catalogue)->assertOk();

        $group = (string) $registry->config($type)['group'];
        if ($group === 'goods') {
            $this->get(route('stores.orders.board', ['store_id' => $store->id]))->assertOk();
        } elseif ($group === 'services') {
            $this->get(route('stores.service_board.index', ['store_id' => $store->id]))->assertOk();
        } elseif ($group === 'food') {
            $this->get(route('stores.orders', $store))->assertOk();
        }

        if (! $registry->isFood($type)) {
            $page = $this->get(route('stores.fulfillment.edit', ['store_id' => $store->id]))->assertOk();
            foreach ($registry->fulfillmentModes($type) as $mode) {
                $page->assertSee(app(FulfillmentRegistry::class)->label($mode));
            }
        }
    }

    #[DataProvider('types')]
    public function test_s9_scheduling_covers_every_slot_mode_of_the_type(string $type): void
    {
        $catalog = app(FulfillmentRegistry::class);
        $store = $this->store($this->owner(), $type);
        $this->addToAssertionCount(1);
        foreach ($catalog->modesFor($type) as $mode) {
            if ($catalog->needsSlot($mode)) {
                $this->assertContains($mode, SchedulingService::TYPES, "$mode needs a slot but scheduling does not know it");
                $this->assertContains($mode, app(SchedulingService::class)->enabledTypes((int) $store->id));
            }
        }
    }

    #[DataProvider('types')]
    public function test_s10_fulfilment_settings_offer_what_the_type_declares(string $type): void
    {
        $store = $this->store($this->owner(), $type);
        $declared = app(VerticalRegistry::class)->fulfillmentModes($type);
        $settings = app(FulfillmentSettingsService::class);

        $this->assertEqualsCanonicalizing($declared, array_values((array) $settings->availableModes($store)));
        $this->assertEqualsCanonicalizing($declared, array_values((array) $settings->enabledModes($store)));
    }

    #[DataProvider('types')]
    public function test_s11_theme_studio_knows_the_type(string $type): void
    {
        $candidates = Recipes::candidateVerticals($type);
        $this->assertNotSame([], $candidates);
        foreach ($candidates as $recipeVertical) {
            $this->assertArrayHasKey($recipeVertical, Recipes::DEMO_DATASET, "recipe vertical $recipeVertical has no demo dataset");
            $this->assertArrayHasKey($recipeVertical, StudioThemes::RECIPE_GROUP);
        }
        $this->assertArrayHasKey($type, Recipes::DEMO_DATASET, "$type has no demo dataset for the Theme Studio preview");

        $vars = StoreVars::forDemo($type);
        $this->assertSame($type, $vars['store']->store_type);
    }

    #[DataProvider('types')]
    public function test_s12_the_public_storefront_renders_on_the_default_inherited_template(string $type): void
    {
        if ($type === 'hotel') {
            $this->markTestSkipped('hotel renders through the aurelia super-app (covered by the hotel tests)');
        }
        if ($type === 'pharmacy') {
            $this->markTestSkipped('a pharmacy storefront is closed (404) unless the country allows pharmacy selling (PharmacyMarkets; covered by the pharmacy tests)');
        }
        $owner = $this->owner();
        $store = $this->store($owner, $type);
        $set = app(VerticalRegistry::class)->themeSet($type);
        StoreOption::updateStoreOption($store->id, 'store_template', self::DEFAULT_TEMPLATE[$set] ?? 'matcha');

        $this->get('/'.$store->slug)->assertOk();
    }
}

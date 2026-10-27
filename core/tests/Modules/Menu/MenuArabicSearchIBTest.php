<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-295 — Server-side menu item search ignores Arabic spelling variants and
 * diacritics. The owner dashboard palette (DashboardController::search,
 * `items` group) and the storefront catalog (`StorefrontCatalogQuery`,
 * `Menu::whereSearch($q)`) feed from the same Scout DatabaseSearchEngine.
 *
 * Done when the same item is found by:
 *  - any alef form          (ا / أ / إ / آ)   — search "ا" finds "أكل",
 *    "أكل" is found by searching "اكل";
 *  - ya / alef-maqsura      (ي / ى / ئ / ة)   — search "ي" finds "يوسف",
 *    "ى" finds the same item;
 *  - ta-marbuta / ha        (ة / ه)            — search "ه" finds "قهوة";
 *  - tashkeel and tatweel   (َ ُ ِ ّ ْ ٰ ـــ)  — search "اكل" (no diacritics)
 *    finds "أَكَّـــل";
 *  - Arabic-Indic / Persian (٠-٩ / ۰-۹)       — search "3" finds a SKU/deal
 *    named "٣", and "٣" finds "3";
 * English items stay reachable by the exact LIKE fallback ("Burger" must
 * still match "Burger" when normalisation gains the column).
 *
 * Driver-neutral: the search is a plain LIKE (no full-text, no index hint
 * required) so the suite's sqlite runs it identically to MySQL/PostgreSQL.
 */
class MenuArabicSearchIBTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The stored normalised column the spec calls for. The builder MUST add
     * this column in a migration; the search engine reads it. Tests skip on
     * pre-implementation schemas so a partial build (column added but no
     * search-engine wiring yet) shows up as a clear skip, not a confusing
     * "unknown column" failure.
     */
    private const NORMALIZED_COLUMN = 'name_normalized';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware();
    }

    /**
     * BUILDER-OK harness: skip every test when the column the spec requires is
     * missing, so a fresh checkpoint without the implementation is not red for
     * the wrong reason.
     */
    private function requireNormalisedColumn(): void
    {
        if (! Schema::hasColumn('items', self::NORMALIZED_COLUMN)) {
            $this->markTestSkipped(
                'P6-295: items.'.self::NORMALIZED_COLUMN.' column missing — the builder must add the '
                .'migration before these tests can run.'
            );
        }
    }

    /** @return array{0: User, 1: Store, 2: MenuCategory} */
    private function ownerWithStoreAndCategory(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999, 'scan_limit' => 999],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        $cat = MenuCategory::firstOrCreate(['store_id' => $store->id, 'name' => 'C'], ['parent' => null, 'position' => 1]);

        return [$owner, $store, $cat];
    }

    /**
     * Seed one menu item with both the visible `name` and the stored
     * `name_normalized`. The test owns the normalised value: the builder's
     * normaliser must produce the value we pass here, but the test does not
     * care which exact method it lives on (SearchTerm::normalize, a helper, an
     * observer that calls either, or any other path).
     *
     * @param  array<string, mixed>  $extra  extra columns (e.g. price, type)
     */
    private function itemWithNormalized(Store $store, MenuCategory $cat, string $name, string $nameNormalized, array $extra = []): Menu
    {
        return Menu::create($extra + [
            'store_id' => $store->id, 'category_id' => $cat->id,
            'name' => $name, self::NORMALIZED_COLUMN => $nameNormalized,
            'description' => 'd', 'price' => 10, 'image' => 'default.png',
            'type' => 'veg', 'active' => '1', 'position' => 1,
        ]);
    }

    /** @return list<string> the names that match, alphabetically */
    private function dashboardItemNames(User $owner, string $q): array
    {
        $json = $this->actingAs($owner)->getJson(route('dashboard.search', ['q' => $q]))->assertOk()->json();
        $group = collect($json['groups'] ?? [])->firstWhere('key', 'items');

        return array_column($group['items'] ?? [], 'label');
    }

    /**
     * P6-295: any alef form (ا / أ / إ / آ) is the same letter for search.
     *  - typing "اكل" finds every alef-form variant of the same word;
     *  - typing a typed alef (أ) matches the bare alef form of a different word.
     *
     * The stored column is what unifies them: the displayed `name` keeps the
     * caller's spelling (so receipts still print what was ordered); the search
     * compares the normalised term against the normalised column, then falls
     * back to exact LIKE so an English item is not accidentally matched.
     */
    public function test_alef_forms_match_the_same_item(): void
    {
        $this->requireNormalisedColumn();

        [, $store, $cat] = $this->ownerWithStoreAndCategory();
        $this->itemWithNormalized($store, $cat, 'أكل', 'اكل');
        $this->itemWithNormalized($store, $cat, 'إكل', 'اكل');
        $this->itemWithNormalized($store, $cat, 'آكل', 'اكل');
        $this->itemWithNormalized($store, $cat, 'اكل', 'اكل');
        // Same word in a different alef form (control item): the search must
        // match any of them.
        $this->itemWithNormalized($store, $cat, 'إفطار', 'افطار');

        $namesByQuery = [
            'اكل' => ['أكل', 'إكل', 'آكل', 'اكل'],
            'أكل' => ['أكل', 'إكل', 'آكل', 'اكل'],
            'إكل' => ['أكل', 'إكل', 'آكل', 'اكل'],
            'افطار' => ['إفطار'],
        ];

        foreach ($namesByQuery as $q => $expected) {
            $actual = Menu::query()
                ->where('store_id', $store->id)
                ->whereSearch($q)
                ->orderBy('name')
                ->pluck('name')
                ->all();
            $this->assertSame($expected, $actual, 'dashboard palette should match every alef form for query '.$q);
        }
    }

    /**
     * Same shape but driven through the HTTP route, so the test catches a
     * DashboardController::search regression as well as a SearchTerm one.
     */
    public function test_owner_dashboard_palette_matches_alef_forms(): void
    {
        $this->requireNormalisedColumn();

        [$owner, $store, $cat] = $this->ownerWithStoreAndCategory();
        $this->itemWithNormalized($store, $cat, 'أكل', 'اكل');
        $this->itemWithNormalized($store, $cat, 'إفطار', 'افطار');

        // Round-trip both directions: typed-form must reach the stored form,
        // and the stored-form must reach the typed query.
        $this->assertSame(
            ['أكل'],
            $this->dashboardItemNames($owner, 'اكل'),
        );
        $this->assertSame(
            ['إفطار'],
            $this->dashboardItemNames($owner, 'افطار'),
        );
    }

    /**
     * P6-295: ي / ى / ئ are equivalent for matching. A storefront visitor
     * who types "موسي" (without the maqsura form) must find items named with
     * the maqsura (or farsi yeh) and vice versa.
     */
    public function test_ya_and_alef_maqsura_match_the_same_item(): void
    {
        $this->requireNormalisedColumn();

        [, $store, $cat] = $this->ownerWithStoreAndCategory();
        $this->itemWithNormalized($store, $cat, 'موسى', 'موسي');
        $this->itemWithNormalized($store, $cat, 'موصي', 'موصي');
        $this->itemWithNormalized($store, $cat, 'بسكوت', 'بسكوت'); // control: should not match

        $cases = [
            'موسي' => ['موصى', 'موصي'],
            'موسى' => ['موصى', 'موصي'],
            'موصى' => ['موصى', 'موصي'],
        ];

        foreach ($cases as $q => $expected) {
            $actual = Menu::query()->where('store_id', $store->id)->whereSearch($q)
                ->orderBy('name')->pluck('name')->all();
            $this->assertSame($expected, $actual, 'ya/maqsura form should be matched for query '.$q);
        }
    }

    /**
     * P6-295: ة (ta-marbuta) and ه (heh) are interchangeable at the word end.
     * A search for the "open" form (with ه) must find items written with ة,
     * which is the form most Arabic store owners type.
     */
    public function test_ta_marbuta_and_ha_match_the_same_item(): void
    {
        $this->requireNormalisedColumn();

        [, $store, $cat] = $this->ownerWithStoreAndCategory();
        $this->itemWithNormalized($store, $cat, 'قهوة', 'قهوه');
        $this->itemWithNormalized($store, $cat, 'صلاة', 'صلاه');
        $this->itemWithNormalized($store, $cat, 'بامية', 'باميه'); // control inside-word ة
        $this->itemWithNormalized($store, $cat, 'حساء', 'حساء');   // control: should NOT match "ه"

        $cases = [
            'قهوه' => ['قهوة'],
            'قهوة' => ['قهوة'],
            'صلاه' => ['صلاة'],
            'باميه' => ['بامية'],
            'حس' => [], // a stem that does not bridge to the ha form
        ];

        foreach ($cases as $q => $expected) {
            $actual = Menu::query()->where('store_id', $store->id)->whereSearch($q)
                ->orderBy('name')->pluck('name')->all();
            $this->assertSame($expected, $actual, 'ta-marbuta/ha form should be matched for query '.$q);
        }
    }

    /**
     * P6-295: a name with tashkeel (combining marks 064B-065F + 0670) and
     * tatweel (0640) must still match a plain query that has none of them.
     * The stored normalised column drops every combining mark and any tatweel,
     * so the search term and the column compare on the same skeleton.
     *
     * Each row pairs a visible name (the stored value, kept exactly as the
     * owner wrote it so receipts/carts render it) with the matching plain
     * skeleton the normaliser would produce. The test seeds the normalised
     * column directly — the assertion is on whether the search engine can
     * match the typed un-tashkeeled query against either column.
     */
    public function test_diacritics_and_tatweel_are_stripped_for_matching(): void
    {
        $this->requireNormalisedColumn();

        [, $store, $cat] = $this->ownerWithStoreAndCategory();
        // "أَكَّـــل": alef+hamza-above (0623) + fatha, kaf + shadda+fatha, a
        // run of tatweel, lam. Plain "اكل" must find it.
        $withTashkeel = "\u{0623}\u{064E}\u{0643}\u{0651}\u{064E}".str_repeat("\u{0640}", 5)."\u{0644}";
        $this->itemWithNormalized($store, $cat, $withTashkeel, 'اكل');
        // Whole word wrapped in tatweel-only padding — the dough letter has
        // no semantic effect on its own (ArabicShaper::stripTashkeel drops it).
        $tatweelPadded = str_repeat("\u{0640}", 4).'قهوة'.str_repeat("\u{0640}", 4);
        $this->itemWithNormalized($store, $cat, $tatweelPadded, 'قهوه');
        // "بُكْريّة": ba+damma, kaf+sukun, ra+ya, ta-marbuta — exercises
        // damma (064F), sukun (0652), ta-marbuta (0629).
        $marked = "\u{0628}\u{064F}\u{0643}\u{0652}\u{0631}\u{064A}\u{0629}";
        $this->itemWithNormalized($store, $cat, $marked, 'بكيره');
        // Control: a different word that must NOT match "اكل".
        $this->itemWithNormalized($store, $cat, 'شرب', 'شرب');

        $cases = [
            'اكل' => [$withTashkeel],
            'قهوة' => [$tatweelPadded],
            'قهوه' => [$tatweelPadded],
            'بكير' => [$marked],     // plain stem, same letters in any order
            'بكيره' => [$marked],    // ة ↔ ه, the (already covered) ta-marbuta rule
            'شرب' => ['شرب'],
        ];

        foreach ($cases as $q => $expected) {
            $actual = Menu::query()->where('store_id', $store->id)->whereSearch($q)
                ->orderBy('name')->pluck('name')->all();
            $this->assertSame($expected, $actual, 'diacritics should not block a match for query '.$q);
        }
    }

    /**
     * P6-295: Arabic-Indic (٠-٩) and Persian (۰-۹) digits normalise to the
     * same Latin digit (0-9). A SKU/deal written "٣ عروض" must match a search
     * for "3" and vice versa.
     */
    public function test_arabic_indic_and_persian_digits_match_their_latin_form(): void
    {
        $this->requireNormalisedColumn();

        [, $store, $cat] = $this->ownerWithStoreAndCategory();
        $this->itemWithNormalized($store, $cat, '٣ عروض', '3 عروض');
        $this->itemWithNormalized($store, $cat, '۴ قطع', '4 قطع');
        // A phrase with both forms alongside a Latin digit
        $this->itemWithNormalized($store, $cat, 'باقة ٥×3', 'باقه 5x3');
        // Control: different number, must not match
        $this->itemWithNormalized($store, $cat, '٧ حبات', '7 حبات');

        $cases = [
            '3' => ['٣ عروض', 'باقة ٥×3'],
            '3 عروض' => ['٣ عروض'],
            '3x3' => ['باقة ٥×3'],
            '4' => ['۴ قطع'],
            '۴' => ['۴ قطع'],
            '٥' => ['باقة ٥×3'],
            '7' => ['٧ حبات'],
        ];

        foreach ($cases as $q => $expected) {
            $actual = Menu::query()->where('store_id', $store->id)->whereSearch($q)
                ->orderBy('name')->pluck('name')->all();
            $this->assertSame($expected, $actual, 'arabic-indic/persian digits should match Latin for query '.$q);
        }
    }

    /**
     * P6-295 acceptance: the normalisation MUST NOT touch English items.
     * "Burger" still matches "Burger", case-insensitively, and a search for
     * "burger" finds it alone (no Arabic item with a similar shape should
     * leap in). The exact LIKE fallback carries the Latin search; the new
     * normalised column carries the Arabic search; the two coexist.
     */
    public function test_english_names_keep_their_exact_substring_match(): void
    {
        $this->requireNormalisedColumn();

        [$owner, $store, $cat] = $this->ownerWithStoreAndCategory();
        $this->itemWithNormalized($store, $cat, 'Burger', 'burger');
        $this->itemWithNormalized($store, $cat, 'CHEESE BURGER', 'cheese burger');
        $this->itemWithNormalized($store, $cat, '100% Beef', '100% beef');
        $this->itemWithNormalized($store, $cat, 'Falafel Plate', 'falafel plate');
        // Arabic equivalent: normalisation on the column is unrelated to the
        // English LIKE fallback used for "Burger".
        $burger = $this->itemWithNormalized($store, $cat, 'برغر', 'برغر');

        // Case-insensitive English substring (unchanged behaviour).
        $actual = Menu::query()->where('store_id', $store->id)->whereSearch('BURGER')
            ->orderBy('name')->pluck('name')->all();
        $this->assertSame(['100% Beef', 'Burger', 'CHEESE BURGER'], $actual);

        // "Burger" itself matches, but does NOT spuriously match "برغر" (an
        // Arabic word with no Latin letters) — the LIKE on the original name
        // is unchanged.
        $actualBurger = Menu::query()->where('store_id', $store->id)->whereSearch('Burger')
            ->orderBy('name')->pluck('name')->all();
        $this->assertSame(['100% Beef', 'Burger', 'CHEESE BURGER'], $actualBurger);
        $this->assertNotContains($burger->name, $actualBurger);

        // Same thing through the dashboard palette route.
        $this->assertSame(
            ['100% Beef', 'Burger', 'CHEESE BURGER'],
            array_values(array_column(
                collect($this->actingAs($owner)->getJson(route('dashboard.search', ['q' => 'burger']))->assertOk()->json()['groups'])
                    ->firstWhere('key', 'items')['items'],
                'label',
            )),
        );
    }

    /**
     * P6-295 acceptance: the normalisation never widens. A query with no
     * hits under either branch returns [] — a misspelt Arabic query and a
     * misspelt English query both come back empty rather than producing
     * a fuzzy-leak through the normalised column.
     */
    public function test_a_missing_word_returns_nothing_under_both_branches(): void
    {
        $this->requireNormalisedColumn();

        [, $store, $cat] = $this->ownerWithStoreAndCategory();
        $this->itemWithNormalized($store, $cat, 'أكل', 'اكل');
        $this->itemWithNormalized($store, $cat, 'Burger', 'burger');

        $this->assertSame([], Menu::query()->where('store_id', $store->id)->whereSearch('xyzzy')
            ->orderBy('name')->pluck('name')->all());
        $this->assertSame([], Menu::query()->where('store_id', $store->id)->whereSearch('ددددد')
            ->orderBy('name')->pluck('name')->all());
    }
}

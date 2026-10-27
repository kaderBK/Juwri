<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Views;

use Illuminate\Support\Collection;
use Juwri\Core\Support\RawPriceFormatter;
use Juwri\Core\Support\TrimmedPriceFormatter;
use Juwri\Core\Support\ViewHelpers;
use Tests\TestCase;

/**
 * P6-36d: closures that used to live in storefront theme views now live in ViewHelpers and three invokable formatters.
 * Each case pins the output of the closure it replaced.
 */
class StorefrontViewHelpersTest extends TestCase
{
    public function test_price_formatters_match_the_closures_they_replaced(): void
    {
        $raw = new RawPriceFormatter('$', '1', 2);
        $this->assertSame('$1234.50', $raw(1234.5));
        $this->assertSame('1234.50 kr', (new RawPriceFormatter(' kr', '0', 2))('1234.5'));
        $this->assertSame('0', (new RawPriceFormatter('', '1', 0))(null));

        $trim = new TrimmedPriceFormatter('$', '1', 2);
        $this->assertSame('$16', $trim(16));
        $this->assertSame('$1,234.50', $trim(1234.5));
        $this->assertSame('16 kr', (new TrimmedPriceFormatter(' kr', '0', 2))(15.999));
    }

    public function test_variant_labels(): void
    {
        $menu = (object) ['variantOptions' => collect([(object) ['id' => 3, 'options' => ['S', 'M', 'L']]])];

        $legacy = (object) ['options' => ['3' => 1], 'name' => 'Big'];
        $this->assertSame('M', ViewHelpers::variantLabelLegacy($menu, $legacy));
        $this->assertSame('M', ViewHelpers::variantLabelNamed($menu, $legacy));
        $this->assertSame('M', ViewHelpers::variantLabelAxes($menu, $legacy));

        $empty = (object) ['options' => [], 'name' => 'Big'];
        $this->assertSame(___('Option'), ViewHelpers::variantLabelLegacy($menu, $empty));
        $this->assertSame('Big', ViewHelpers::variantLabelNamed($menu, $empty));

        $axes = (object) ['options' => ['Size' => 'M', 'Color' => 'Red', 'Odd' => '']];
        $this->assertSame('M · Red', ViewHelpers::variantLabelAxes($menu, $axes));

        $this->assertSame('Plain text', ViewHelpers::variantLabelRaw($menu, (object) ['options' => ' Plain text ']));
        $this->assertSame('L', ViewHelpers::variantLabelRaw($menu, (object) ['options' => ['3' => 2, 'x' => 1]]));
    }

    public function test_attribute_brand_stock_and_duration_helpers(): void
    {
        $this->assertSame([], ViewHelpers::attrsOf((object) ['attributes' => 'x']));
        $this->assertSame(['brand' => 'B'], ViewHelpers::attrsOf((object) ['attributes' => ['brand' => 'B']]));
        $this->assertSame('Rel', ViewHelpers::brandOf((object) ['brand' => (object) ['name' => 'Rel'], 'attributes' => ['brand' => 'B']]));
        $this->assertSame('B', ViewHelpers::brandOf((object) ['attributes' => ['brand' => 'B']]));
        $this->assertNull(ViewHelpers::brandOf((object) []));

        $this->assertNull(ViewHelpers::stockBadge(false, 3));
        $this->assertNull(ViewHelpers::stockBadge(true, null));
        $this->assertSame('out', ViewHelpers::stockBadge(true, 0)['type']);
        $this->assertSame('low', ViewHelpers::stockBadge(true, 5)['type']);
        $this->assertSame('in', ViewHelpers::stockBadge(true, 6)['type']);

        $this->assertNull(ViewHelpers::formatDuration(0));
        $this->assertSame(___(':hours h turnaround', ['hours' => 4]), ViewHelpers::formatDuration(4));
        $this->assertSame('1 Main, Town', ViewHelpers::addressLabel((object) ['line' => '1 Main', 'city' => 'Town', 'state' => null, 'zip' => '', 'country' => null]));
    }

    public function test_storefront_item_json_helpers(): void
    {
        $allergies = collect([(object) ['id' => 1, 'title' => 'Nuts', 'image' => 'https://x.test/n.png']]);
        $this->assertSame([['id' => 1, 'title' => 'Nuts', 'img' => 'https://x.test/n.png']], ViewHelpers::allergyList($allergies)->all());
        $this->assertSame([['id' => 1, 'title' => 'Nuts', 'img' => 'https://x.test/n.png']], ViewHelpers::allergyListAsset($allergies)->all());
        $this->assertSame([['id' => 1, 'title' => 'Nuts']], ViewHelpers::allergyListPlain($allergies)->all());

        $this->assertSame([asset('storage/menu/a.jpg')], ViewHelpers::galleryAssets(['a.jpg'])->all());

        $groups = [(object) ['id' => 9, 'title' => 'Sauce', 'required' => 1, 'min_select' => null, 'max_select' => 2, 'options' => ['Hot', 'Mild'], 'option_prices' => [1 => 0.5]]];
        $this->assertSame(json_encode([[
            'id' => 9, 'title' => 'Sauce', 'required' => true, 'min' => 0, 'max' => 2,
            'options' => [['i' => 0, 'label' => 'Hot', 'price' => 0.0], ['i' => 1, 'label' => 'Mild', 'price' => 0.5]],
        ]]), json_encode(ViewHelpers::optionGroups($groups)));

        $reviews = collect(range(1, 8))->map(fn ($i) => (object) ['reviewer_name' => $i === 1 ? '' : 'R'.$i, 'stars' => '4', 'body' => null]);
        $list = ViewHelpers::reviewList($reviews);
        $this->assertCount(6, $list);
        $this->assertSame(['name' => ___('Guest'), 'stars' => 4, 'body' => ''], $list->first());
    }

    public function test_collection_helpers(): void
    {
        $items = collect([
            (object) ['id' => 1, 'image' => 'default.png', 'is_popular' => 1, 'is_daily_special' => 0],
            (object) ['id' => 2, 'image' => 'a.jpg', 'is_popular' => 0, 'is_daily_special' => 1],
            (object) ['id' => 3, 'image' => 'b.jpg', 'is_popular' => 1, 'is_daily_special' => 1],
        ]);
        $this->assertSame(2, ViewHelpers::firstWithImage($items)->id);
        $this->assertSame(1, ViewHelpers::firstWithImage(collect([$items[0]]))->id);
        $this->assertSame([2, 3], ViewHelpers::imageItems($items, 3)->pluck('id')->all());
        $this->assertSame([2], ViewHelpers::imageItems($items, 1)->pluck('id')->all());
        $this->assertSame([1, 3], ViewHelpers::flagged($items, 'is_popular')->pluck('id')->values()->all());
        $this->assertSame([2], ViewHelpers::flagged($items, 'is_daily_special', 1)->pluck('id')->values()->all());

        $langs = [(object) ['code' => 'en', 'name' => null], (object) ['code' => 'fr', 'name' => 'Francais']];
        $out = ViewHelpers::menuLanguages($langs);
        $this->assertSame(['en', 'fr'], $out->pluck('code')->all());
        $this->assertSame(['en', 'Francais'], $out->pluck('name')->all());

        $this->assertSame([1], ViewHelpers::activeBanners([(object) ['id' => 1], (object) ['id' => 2, 'active' => 0]])->pluck('id')->all());
        $this->assertSame(['code' => 'fr'], ViewHelpers::otherLocale([['code' => 'en'], ['code' => 'fr']], 'en'));
        $this->assertNull(ViewHelpers::otherLocale([['code' => 'en']], 'en'));
        $this->assertSame(['a', 'b'], ViewHelpers::sectionIds([['id' => 'a'], 'b']));
        $this->assertTrue(ViewHelpers::hasSoldOutBadge([['kind' => 'new'], ['kind' => 'sold_out']]));
        $this->assertFalse(ViewHelpers::hasSoldOutBadge([]));
    }

    public function test_fulfillment_modes(): void
    {
        $f = new class
        {
            public function modesFor($type): array
            {
                return ['pickup', 'ship'];
            }

            public function label($m): string
            {
                return strtoupper($m);
            }

            public function needsAddress($m): bool
            {
                return $m === 'ship';
            }

            public function needsSlot($m): bool
            {
                return false;
            }
        };
        $modes = ViewHelpers::fulfillmentModes($f, 'restaurant');
        $this->assertInstanceOf(Collection::class, $modes);
        $this->assertSame(['value' => 'ship', 'label' => 'SHIP', 'needsAddress' => true, 'needsSlot' => false], $modes[1]);
    }

    public function test_p636e_catalog_and_option_helpers(): void
    {
        $cats = [['id' => 1, 'count' => 0, 'item_ids' => ['a']], ['id' => 2, 'count' => 3, 'item_ids' => ['b', 'c']]];
        $this->assertSame($cats[1], ViewHelpers::categoryById($cats, '2'));
        $this->assertNull(ViewHelpers::categoryById($cats, '9'));
        $this->assertNull(ViewHelpers::categoryByStrictId($cats, '2'));
        $this->assertSame($cats[0], ViewHelpers::categoryByStrictId($cats, 1));
        $this->assertSame($cats[1], ViewHelpers::categoryWithItem($cats, 'c'));
        $this->assertSame([$cats[1]], ViewHelpers::populatedCategories($cats));

        $opts = [['id' => 'x', 'label' => 'abcd', 'selected' => true], ['id' => 'y', 'label' => 'abcdefghijklmn']];
        $this->assertSame(1, ViewHelpers::selectedCount($opts));
        $this->assertTrue(ViewHelpers::labelsFit([$opts[0]], 4));
        $this->assertFalse(ViewHelpers::labelsFit($opts, 12));
        $this->assertSame($opts[1], ViewHelpers::firstPicked([['id' => 'x'], $opts[1]], ['y']));
        $this->assertSame($opts[0], ViewHelpers::firstSelectedOption($opts));

        $types = [['key' => 'delivery'], ['key' => 'dine_in'], ['key' => 'pickup'], ['key' => 'odd']];
        $this->assertSame([['key' => 'pickup'], ['key' => 'dine_in'], ['key' => 'delivery'], ['key' => 'odd']], ViewHelpers::cafeOrderTypes($types));
        $this->assertSame([['key' => 'pickup']], ViewHelpers::kioskOrderTypes($types, ['pickup']));
        $this->assertTrue(ViewHelpers::hasKey($types, 'odd'));
        $this->assertFalse(ViewHelpers::hasKey($types, 'ship'));
        $this->assertTrue(ViewHelpers::hasKey([['id' => 'address']], 'address', 'id'));

        $badges = [['kind' => 'sale', 'label' => 'S'], ['kind' => 'sold_out', 'label' => 'O']];
        $this->assertSame(['sale' => 'S', 'sold_out' => 'O'], ViewHelpers::badgeLabels($badges)->all());
        $this->assertSame($badges[1], ViewHelpers::badgeOfKind($badges, 'sold_out'));
        $this->assertSame($badges[0], ViewHelpers::badgeNotOfKind($badges, 'sold_out'));
        $this->assertSame([5 => null, 6 => 9], ViewHelpers::wishlistVariants([['item_id' => 5], ['item_id' => 6, 'variant_id' => 9]])->all());
        $this->assertSame('a 1 · b 2', ViewHelpers::nutritionFacts([['label' => 'a', 'value' => 1], ['label' => 'b', 'value' => 2]]));
    }

    public function test_p636e_checkout_and_state_helpers(): void
    {
        $fields = [['id' => 'name'], ['id' => 'mobile'], ['id' => 'city']];
        $this->assertSame([['id' => 'name']], ViewHelpers::contactFields($fields, true, ['name', 'mobile'])->values()->all());
        $this->assertSame([['id' => 'name'], ['id' => 'mobile']], ViewHelpers::contactFields($fields, false, ['name', 'mobile'])->values()->all());
        $this->assertSame([['id' => 'city']], ViewHelpers::addressFields($fields, ['name', 'mobile'])->values()->all());
        $this->assertTrue(ViewHelpers::onlineSelected([['id' => 'cash'], ['id' => 'online', 'selected' => true]]));
        $this->assertFalse(ViewHelpers::onlineSelected([['id' => 'online']]));
        $this->assertSame(1, ViewHelpers::selectedDayKey([['state' => 'a'], ['state' => 'selected']]));
        $this->assertFalse(ViewHelpers::selectedDayKey([['state' => 'a']]));
        $this->assertSame(['kind' => 'weight', 'id' => 2], ViewHelpers::firstOfKind([['kind' => 'a'], ['kind' => 'weight', 'id' => 2]], 'kind', 'weight'));
        $this->assertCount(1, ViewHelpers::withUrl([['url' => 'u'], ['url' => '']], true));
        $this->assertCount(1, ViewHelpers::withUrl([['url' => 'u'], ['url' => '']], false));
        $this->assertCount(1, ViewHelpers::actionsByIcon([['icon' => 'navigation'], ['icon' => 'x']], ['navigation'], true));
        $this->assertCount(1, ViewHelpers::actionsByIcon([['icon' => 'navigation'], ['icon' => 'x']], ['navigation'], false));
        $this->assertTrue(ViewHelpers::quoteNeedsTotal([['kind' => 'line', 'label' => 'l']], 'Total'));
        $this->assertFalse(ViewHelpers::quoteNeedsTotal([['kind' => 'total', 'label' => 'l']], 'Total'));
        $this->assertFalse(ViewHelpers::quoteNeedsTotal([['kind' => 'subtotal', 'label' => 'Total']], 'Total'));
        $this->assertTrue(ViewHelpers::quoteNeedsTotal([['kind' => 'subtotal', 'label' => 'Sub']], 'Total'));

        $items = ['a' => ['id' => 'a', 'sold_out' => true, 'image' => ['src' => 's']], 'b' => ['id' => 'b', 'image' => ['src' => 'bs']]];
        $this->assertSame($items['a'], ViewHelpers::firstSoldOut($items));
        $this->assertSame($items['b'], ViewHelpers::firstAvailable(['a', 'zz', 'b'], $items));
        $this->assertSame('s', ViewHelpers::firstItemImage(['zz', 'a'], $items));

        $card = ViewHelpers::stateCard('muted', 'i', 't', 'T', 'B', [1], ['extra' => 1]);
        $this->assertSame(['tone' => 'muted', 'icon' => 'i', 'tag' => 't', 'title' => 'T', 'body' => 'B', 'actions' => [1], 'extra' => 1], $card);
        $this->assertSame('at_home', ViewHelpers::bookLocation('at_home'));
        $this->assertSame('venue', ViewHelpers::bookLocation('x'));
    }

    public function test_p636e_marketing_admin_and_bio_helpers(): void
    {
        $this->assertSame(
            [['@type' => 'Question', 'name' => 'Q', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'A b']]],
            ViewHelpers::faqLdStripped([['q' => 'Q', 'a' => ' <b>A b</b> ']])
        );
        $this->assertSame('', ViewHelpers::faqLdPlain([[]])[0]['name']);
        $this->assertSame('x', ViewHelpers::faqLdPairs([['x', 'y']])[0]['name']);
        $this->assertSame('y', ViewHelpers::faqLdRequired([['q' => 'x', 'a' => 'y']])[0]['acceptedAnswer']['text']);
        $faq = (object) ['faq_title' => 'T', 'faq_content' => '<p>C</p>'];
        $this->assertSame('C', ViewHelpers::faqLdModels([$faq])[0]['acceptedAnswer']['text']);
        $this->assertSame('Q', ViewHelpers::faqLdModelsFallback([(object) ['question' => 'Q', 'answer' => 'A']])[0]['name']);

        $models = [(object) ['id' => 7, 'name' => 'N', 'code' => 'en']];
        $this->assertSame(['7' => 'N'], ViewHelpers::propOptions($models, 'id', 'name', true));
        $this->assertSame(['en' => 'N'], ViewHelpers::propOptions($models, 'code', 'name'));
        $this->assertSame(['f' => 'N (f)'], ViewHelpers::templateFolderOptions([['folder' => 'f', 'name' => 'N', 'enabled' => false]]));
        $this->assertSame([], ViewHelpers::templateFolderOptions([['folder' => 'f', 'name' => 'N', 'enabled' => false]], true));
        $this->assertSame(['s' => 'N (s)'], ViewHelpers::templateSlugOptions(['s' => ['name' => 'N']], true));
        $this->assertSame(['s' => 'N'], ViewHelpers::templateSlugOptions(['s' => ['name' => 'N']], false));
        $this->assertSame(['a' => 'A'], ViewHelpers::ucfirstOptions(['a'], false));

        $this->assertSame(['x', 'y'], ViewHelpers::cleanList([' x ', '', '0', 'y']));
        $this->assertSame(['0', 'y'], ViewHelpers::trimmedNonEmpty([' 0', '', 'y']));
        $this->assertSame([1 => 'b'], ViewHelpers::tagCollection('a,b')->filter(fn ($t) => $t === 'b')->all());
        $this->assertSame(['de'], ViewHelpers::otherLocales(['en', 'de'], 'en'));
        $this->assertSame('success', ViewHelpers::toneOf('ok'));
        $this->assertSame('warning', ViewHelpers::toneOf('pending'));
        $this->assertSame('neutral', ViewHelpers::toneOf(null));
        $this->assertSame('danger', ViewHelpers::toneOf('bad'));

        $this->assertSame([['name' => 'a']], ViewHelpers::namedApps([['name' => 'a'], ['name' => ' '], 'x']));
        $this->assertSame([['url' => 'u']], ViewHelpers::linksWithUrl([['url' => 'u'], ['url' => ''], 3]));
        $this->assertSame([['q' => 'q', 'a' => 'a']], ViewHelpers::faqWithBoth([['q' => 'q', 'a' => 'a'], ['q' => 'q']]));
        $this->assertSame('9–5, 1–2', ViewHelpers::hoursPairs([[' 9', '5 '], ['1', '2']]));
        $this->assertSame('https://x/y', ViewHelpers::bioMediaUrl(' https://x/y ', null));
        $this->assertSame('u', ViewHelpers::bioMediaUrl(['url' => 'u'], null));
        $this->assertSame('', ViewHelpers::bioMediaUrl(5, null));

        $this->assertTrue(ViewHelpers::allOk([['ok' => true]]));
        $this->assertFalse(ViewHelpers::allOk([['ok' => true], ['ok' => false]]));
        $this->assertSame(5, ViewHelpers::cartQuantity([['items' => [['quantity' => 2], ['quantity' => 3]]]]));
    }
}

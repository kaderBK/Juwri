<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Views;

use Juwri\Core\Support\Views\OwnerOpsViewHelpers;
use Juwri\Core\Support\Views\PublicSiteViewHelpers;
use Juwri\Core\Support\Views\StoreViewHelpers;
use Tests\TestCase;

/**
 * P6-149: closures moved out of ~60 views into the three Views\*ViewHelpers classes. Each case pins the output of the
 * closure it replaced.
 */
class ViewClosureBatchHelpersTest extends TestCase
{
    public function test_owner_ops_tones_and_labels(): void
    {
        $this->assertSame(['success', 'danger', 'warning', 'neutral'], array_map([OwnerOpsViewHelpers::class, 'printJobTone'], ['done', 'failed', 'claimed', 'x']));
        $this->assertSame(['success', 'danger', 'warning', 'neutral'], array_map([OwnerOpsViewHelpers::class, 'campaignStatusVariant'], ['sent', 'failed', 'scheduled', 'draft']));
        $this->assertSame(['warning', 'accent', 'success', 'danger', 'neutral'], array_map([OwnerOpsViewHelpers::class, 'waitlistStatusTone'], ['waiting', 'notified', 'seated', 'left', '?']));
        $this->assertSame(['accent', 'warning', 'neutral'], array_map([OwnerOpsViewHelpers::class, 'staffRoleTone'], ['manager', 'kitchen', 'waiter']));
        $this->assertSame('free text', OwnerOpsViewHelpers::teamRoleLabel('free text'));
        $this->assertNull(OwnerOpsViewHelpers::teamRoleLabel(null));
        $this->assertSame(___('Manager'), OwnerOpsViewHelpers::teamRoleLabel('manager'));
    }

    public function test_owner_ops_options_and_grouping(): void
    {
        $this->assertSame(60, OwnerOpsViewHelpers::opt(['a' => ['b' => null]], 'a.c', 60));
        $this->assertTrue(OwnerOpsViewHelpers::automationSwitch(['x' => 1], 'x'));
        $this->assertFalse(OwnerOpsViewHelpers::automationSwitch(['x' => '0'], 'x'));
        $rooms = collect([(object) ['hk_status' => 'dirty'], (object) ['hk_status' => 'weird'], (object) []]);
        $g = OwnerOpsViewHelpers::groupByHousekeepingStatus($rooms, ['clean' => 1, 'dirty' => 2]);
        $this->assertSame(['dirty', 'clean'], $g->keys()->all());
        $this->assertSame(2, $g['clean']->count());
        $this->assertSame([['id' => 1, 'name' => 'a']], OwnerOpsViewHelpers::staffAsIdNameList(collect([(object) ['id' => '1', 'name' => 'a']])));
    }

    public function test_public_site_helpers(): void
    {
        $this->assertSame([100, 400, 400, 900], PublicSiteViewHelpers::validFontWeights(['900', '50', 400, '400', 1000, 100]));
        $this->assertSame([400], PublicSiteViewHelpers::validFontWeights([5]));
        $this->assertSame(['a', null], PublicSiteViewHelpers::enabledBlockTypes([['type' => 'a'], ['enabled' => false, 'type' => 'b'], ['enabled' => true], 'x']));
        $this->assertSame('abc', PublicSiteViewHelpers::safeCssValue('a;b<c>', 'x'));
        $this->assertSame('#fff', PublicSiteViewHelpers::safeCssValue('', '#fff'));
        $this->assertSame('ccc', PublicSiteViewHelpers::widestString(['a', 'ccc', 'ddd']));
        $this->assertSame(['cls' => 'mk-yes', 'text' => '✓', 'aria' => ___('Included')], PublicSiteViewHelpers::compareCell(true));
        $this->assertSame(['cls' => 'cmp-val', 'text' => '5', 'aria' => null], PublicSiteViewHelpers::compareCell(5));
        $this->assertSame('#aabbcc', PublicSiteViewHelpers::headBrandColor((object) ['primary_color' => '#aabbcc'], null));
        $this->assertNull(PublicSiteViewHelpers::headBrandColor(null, ['theme_color' => 'red']));
        $this->assertSame(['A' => 2, 'x' => 3], PublicSiteViewHelpers::normaliseInsightsRows([['source' => 'A', 'count' => 2], 'x' => 3]));
        $this->assertSame(['link' => 'wa.me link (free)', 'zz' => 'Zz'], PublicSiteViewHelpers::waProviderOptions(collect(['link', 'zz'])));
    }

    public function test_bookings_times_url_keeps_trailing_question_mark_like_the_original(): void
    {
        $url = PublicSiteViewHelpers::bookingsTimesUrl(['staff' => null, 'date' => '2026-01-02'], ['svc' => '3', 'empty' => ''], false, 'shop', null);
        $this->assertStringEndsWith('?date=2026-01-02&svc=3', $url);
        $this->assertStringEndsWith('?', PublicSiteViewHelpers::bookingsTimesUrl([], [], false, 'shop', null));
    }

    public function test_store_helpers(): void
    {
        $this->assertSame(___('Received'), StoreViewHelpers::orderStatusLabel('pending'));
        $this->assertSame('Weird', StoreViewHelpers::orderStatusLabel('weird'));
        $this->assertSame('', StoreViewHelpers::orderStatusLabel(null));
        $this->assertSame(['a' => 1], StoreViewHelpers::arrayFilterNotEmpty(['a' => 1, 'b' => null, 'c' => '']));
        $this->assertTrue(StoreViewHelpers::tsRecommended([], null, null));
        $this->assertTrue(StoreViewHelpers::tsRecommended(['x'], 'x', null));
        $this->assertFalse(StoreViewHelpers::tsRecommended(['y'], 'x', 'z'));
        $this->assertTrue(StoreViewHelpers::branchHasCoords((object) ['latitude' => '1.5', 'longitude' => 2]));
        $this->assertFalse(StoreViewHelpers::branchHasCoords((object) ['latitude' => '', 'longitude' => 2]));
        $this->assertFalse(StoreViewHelpers::branchHasCoords(null));
        $this->assertEqualsWithDelta(1.0, StoreViewHelpers::srgbLuminanceChannel(255.0), 0.0001);
        $offered = StoreViewHelpers::localeSuggestOffered([(object) ['code' => 'EN', 'name' => 'English'], (object) ['code' => 'FR', 'name' => 'Francais', 'direction' => 'ltr']], 'en');
        $this->assertSame([['code' => 'fr', 'name' => 'Francais', 'dir' => 'ltr']], $offered->all());
        $this->assertSame(['x, y', '1'], StoreViewHelpers::rowsToCsv([['x', 'y'], 1]));
        $this->assertSame(['f' => 'N'], StoreViewHelpers::templateNamesMap([['folder' => 'f', 'name' => 'N']]));
    }
}

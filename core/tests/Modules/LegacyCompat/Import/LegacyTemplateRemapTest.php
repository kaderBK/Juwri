<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat\Import;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacySourceFixture as Source;
use Juwri\Modules\LegacyCompat\Import\StoreSettingsImporter;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Tests\TestCase;

/** J64b/J65/BL-037: dropped legacy templates and an empty template import as `crave`; other slugs stay as they are. */
class LegacyTemplateRemapTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'legacy_test_source';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.'.self::SOURCE => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'legacy_compat.enabled' => true,
            'legacy_compat.import.enabled' => true,
            'legacy_compat.cutover.owner_signoff' => true,
            'legacy_compat.import.target_host' => 'localhost',
            'legacy_compat.import.source_timezone' => 'Asia/Riyadh',
            'legacy_compat.import.source_app_key' => '',
            'legacy_compat.import.enabled_gateways' => ['stripe', 'paypal'],
        ]);
        DB::purge(self::SOURCE);
    }

    protected function tearDown(): void
    {
        DB::purge(self::SOURCE);
        parent::tearDown();
    }

    public function test_every_dropped_slug_and_an_empty_template_become_crave_with_a_report_row(): void
    {
        $src = (new Source(DB::connection(self::SOURCE)))->createSchema();
        $src->platformOption('currency_code', 'USD');
        $src->user(1, ['name' => 'Owner A']);

        $slugs = StoreSettingsImporter::DROPPED_TEMPLATES;
        $this->assertCount(15, $slugs);
        $id = 10;
        $remapped = [];
        foreach ($slugs as $slug) {
            $src->store($id, 1, ['slug' => 'store-'.$id]);
            $src->storeOption($id, Source::THEME_OPTION, $slug);
            $remapped[] = $id++;
        }
        $src->store($id, 1, ['slug' => 'store-empty']); // no template row at all
        $emptyId = $id++;
        $src->store($id, 1, ['slug' => 'store-blank']);
        $src->storeOption($id, Source::THEME_OPTION, '');
        $blankId = $id++;
        $src->store($id, 1, ['slug' => 'store-keeper']);
        $src->storeOption($id, Source::THEME_OPTION, 'velora');
        $keeperId = $id;

        $exit = Artisan::call('legacy:import', ['--source' => self::SOURCE]);
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $report = json_decode($output, true);
        $this->assertIsArray($report, $output);
        StoreSettings::flush();

        foreach ($remapped as $storeId) {
            $this->assertSame('crave', StoreSettings::string($storeId, 'store_template'), 'store '.$storeId);
        }
        $this->assertSame('crave', StoreSettings::string($emptyId, 'store_template'));
        $this->assertSame('crave', StoreSettings::string($blankId, 'store_template'));
        $this->assertSame('velora', StoreSettings::string($keeperId, 'store_template'));
        $this->assertSame(15, $report['counters']['stores_template_remapped_to_default']);
        $this->assertSame(2, $report['counters']['stores_template_set_to_default']);
        $row = collect($report['conflicts'])->first(fn ($c, $k) => str_contains((string) $k, 'dropped legacy template'));
        $this->assertSame(15, $row['count']);
    }

    public function test_marketplace_crave_slug_resolves_to_the_crave_theme(): void
    {
        $theme = MarketplaceThemeResolver::resolve('mp:crave@1', gate: false);
        $this->assertNotNull($theme);
        $this->assertSame('crave', $theme->slug);
    }
}

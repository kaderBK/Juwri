<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Language;
use Tests\TestCase;

/**
 * P4-13: a fresh install shipped only en and fr language rows, so a Community owner could not choose Arabic (a main
 * market) or any other locale the product has translations for. A freshly migrated database now carries a row for
 * EVERY locale in lang/ with the right direction: en and fr active as before, the other nine inactive (one click to switch on).
 */
class ShippedLanguagesSeededTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function shippedLocales(): array
    {
        $codes = [];
        foreach (glob(base_path('lang/*/lang.php')) ?: [] as $file) {
            $codes[] = basename(dirname($file));
        }
        sort($codes);

        return $codes;
    }

    public function test_a_fresh_install_has_a_row_for_every_shipped_locale_and_only_en_fr_active(): void
    {
        $shipped = $this->shippedLocales();
        $this->assertSame(['ar', 'de', 'en', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'], $shipped);

        $rows = Language::query()->orderBy('code')->get()->keyBy('code');
        $this->assertSame($shipped, $rows->keys()->all(), 'one row per shipped locale, no duplicates');
        foreach ($rows as $code => $row) {
            $this->assertSame(in_array($code, ['en', 'fr'], true), (bool) $row->active, "{$code} active flag");
        }
    }

    public function test_the_right_to_left_locales_are_marked_rtl(): void
    {
        $dir = Language::query()->pluck('direction', 'code');

        $this->assertSame('rtl', $dir['ar']);
        $this->assertSame('rtl', $dir['ur']);
        foreach (['en', 'fr', 'de', 'es', 'hi', 'pt', 'ru', 'tr', 'zh'] as $code) {
            $this->assertSame('ltr', $dir[$code], $code);
        }
    }

    public function test_the_migration_is_idempotent_and_leaves_an_edited_row_alone(): void
    {
        Language::where('code', 'de')->update(['name' => 'Deutsch', 'active' => 0]);
        Language::where('code', 'zh')->delete();

        $migration = require base_path('app/Core/Database/Migrations/2026_10_07_200000_seed_all_shipped_languages.php');
        $migration->up();
        $migration->up();

        $this->assertSame(1, Language::where('code', 'zh')->count(), 'a missing locale is added once');
        $de = Language::where('code', 'de')->first();
        $this->assertSame('Deutsch', $de->name);
        $this->assertFalse((bool) $de->active, 'an admin edit is never overwritten');
    }
}

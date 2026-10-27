<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Juwri\Core\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * I3: one is_rtl() helper replacing ~10 divergent hardcoded RTL lists. Resolves
 * from the languages table `direction`, with a canonical RTL fallback set so
 * he/fa/ps/… stay RTL even when they are not configured Language rows.
 */
class IsRtlHelperTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_rtl_and_ltr_codes(): void
    {
        // No Language rows → the fallback set decides.
        foreach (['ar', 'fa', 'he', 'ur', 'ps', 'sd', 'dv'] as $code) {
            $this->assertTrue(is_rtl($code), "$code should be RTL");
        }
        foreach (['en', 'de', 'fr', 'es', 'tr'] as $code) {
            $this->assertFalse(is_rtl($code), "$code should be LTR");
        }
    }

    public function test_languages_table_direction_wins_over_fallback(): void
    {
        // A configured row overrides the fallback either way (updateOrCreate:
        // the base dataset may already seed some language codes).
        Language::updateOrCreate(['code' => 'ckb'], ['name' => 'Kurdish', 'direction' => 'rtl', 'active' => 1]);
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);

        $this->assertTrue(is_rtl('ckb'));
        $this->assertFalse(is_rtl('en'));
    }

    public function test_defaults_to_current_locale(): void
    {
        app()->setLocale('ar');
        $this->assertTrue(is_rtl());

        app()->setLocale('en');
        $this->assertFalse(is_rtl());
    }

    public function test_memoized_no_query_per_call_in_a_loop(): void
    {
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);

        \DB::flushQueryLog();
        \DB::enableQueryLog();
        for ($i = 0; $i < 20; $i++) {
            is_rtl('ar');
        }
        $queries = collect(\DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'languages'))->count();
        \DB::disableQueryLog();

        $this->assertLessThanOrEqual(1, $queries, 'is_rtl must memoize — at most one languages query per code');
    }
}

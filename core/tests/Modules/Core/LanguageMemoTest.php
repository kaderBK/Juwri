<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Juwri\Core\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P1 sub-item 3 — current_language() and get_active_languages() are memoized per
 * request (bound to the app instance) and flushed on any Language write, so a legacy
 * storefront render issues one `languages` query no matter how many times it calls them.
 */
class LanguageMemoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
    }

    public function test_current_language_is_queried_once_per_request(): void
    {
        // warm the memo
        current_language();

        $q = 0;
        DB::listen(function ($sql) use (&$q) {
            if (preg_match('/from\s+["`]?[a-z0-9_]*languages["`]?/i', $sql->sql)) {
                $q++;
            }
        });

        current_language();
        current_language();
        current_language();

        $this->assertSame(0, $q, 'repeat current_language() calls must reuse the memo');
    }

    public function test_active_languages_is_queried_once_per_request(): void
    {
        get_active_languages();

        $q = 0;
        DB::listen(function ($sql) use (&$q) {
            if (preg_match('/from\s+["`]?[a-z0-9_]*languages["`]?/i', $sql->sql)) {
                $q++;
            }
        });

        get_active_languages();
        get_active_languages();

        $this->assertSame(0, $q, 'repeat get_active_languages() calls must reuse the memo');
    }

    public function test_writing_a_language_flushes_the_memo(): void
    {
        $first = current_language();
        $this->assertNotNull($first);

        // A language write must invalidate the memo so the next read is fresh.
        Language::where('code', 'en')->first()->update(['name' => 'English (edited)']);

        $q = 0;
        DB::listen(function ($sql) use (&$q) {
            if (preg_match('/from\s+["`]?[a-z0-9_]*languages["`]?/i', $sql->sql)) {
                $q++;
            }
        });

        $again = current_language();
        $this->assertSame(1, $q, 'a language write must bust the memo (next read re-queries)');
        $this->assertSame('English (edited)', $again->name);
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Models\Language;
use Juwri\Core\Support\LocalizedRoutes;
use Tests\TestCase;

/**
 * Found on the Docker stack (P4-17 follow-up): an Octane worker registers its language-prefixed routes once, and only
 * registered the languages active when it booted — Arabic, switched on by the first-run seeders a moment later, had no
 * routes and `/ar/<store>` answered 404 until a restart. Every known language is registered now (shipped `lang/`
 * folders + every `languages` row); EnsureLocalePrefix keeps an inactive one unreachable per request
 * (OctaneLocalizedRoutesTest::test_a_language_switched_off_since_the_worker_started_answers_404).
 */
class LocalizedRoutesAllLanguagesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        LocalizedRoutes::forget();
        parent::tearDown();
    }

    public function test_a_language_inactive_at_boot_still_gets_its_prefixed_routes(): void
    {
        config(['laravellocalization.localeRoutes' => 'all']);
        Language::query()->delete();
        Language::create(['code' => 'en', 'name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::create(['code' => 'xq', 'name' => 'Inactive', 'direction' => 'ltr', 'active' => 0]);

        $codes = LocalizedRoutes::codes();
        $this->assertContains('ar', $codes, 'a shipped language (lang/ar) not active at boot');
        $this->assertNotContains('xq', $codes, 'no database query at route registration');
        $this->assertNotContains('vendor', $codes);
    }

    public function test_without_a_database_the_shipped_languages_are_still_registered(): void
    {
        config(['laravellocalization.localeRoutes' => 'all']);
        Schema::drop('languages');
        $this->assertContains('ar', LocalizedRoutes::codes());
    }
}

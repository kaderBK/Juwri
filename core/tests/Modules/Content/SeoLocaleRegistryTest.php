<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Core\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoLocaleRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_languages_registry_carries_mcamara_metadata(): void
    {
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);

        $langs = get_active_languages();

        $this->assertSame('Arab', $langs['ar']['script']);
        $this->assertSame('العربية', $langs['ar']['native']);
        $this->assertSame('ar_AE', $langs['ar']['regional']);
        $this->assertSame('en_US', $langs['en']['regional']);
    }

    public function test_unknown_locale_gets_safe_fallback_metadata(): void
    {
        Language::updateOrCreate(['code' => 'xx'], ['name' => 'Xish', 'direction' => 'ltr', 'active' => 1]);

        $langs = get_active_languages();

        $this->assertSame('Latn', $langs['xx']['script']);
        $this->assertSame('Xish', $langs['xx']['native']);
        $this->assertSame('xx_XX', $langs['xx']['regional']);
    }

    public function test_inactive_languages_stay_out_of_registry(): void
    {
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'de'], ['name' => 'German', 'direction' => 'ltr', 'active' => 0]);

        $this->assertArrayNotHasKey('de', get_active_languages());
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\TestCase;

/**
 * BIOLINK-RUN A10 (SPEC §3 i18n): owner-authored block content is per-locale.
 * The base value stays in `label`/`config` (default-locale fallback); overrides
 * live in `config._i18n[locale]`. blocksFor() resolves label + config text keys
 * for the current app locale and strips `_i18n`, so Lane C's contract is
 * byte-identical. Proven at the service level AND through the public page (en/ar).
 *
 * Owner-content idiom mirrored: the codebase's `translations` object cast
 * (Menu::translations = {locale:{field:value}}) — same SHAPE, stored inside the
 * block's own config json (LOCKED §3/§7) so no schema change and B8 writes
 * `config._i18n[locale]` directly.
 */
class BioLinkLocaleContentTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): BioLinkService
    {
        return app(BioLinkService::class);
    }

    private function enabledStoreWithArOverride(): Store
    {
        $store = Store::factory()->create();
        config(['settings.admin_allow_biolink' => true]);
        EntitlementWriter::grant($store->user, 'biolink');
        StoreOption::updateStoreOption($store->id, 'bio_settings', json_encode(['enabled' => true]));
        StoreOption::flushOptionMemo();

        BioBlock::factory()->create([
            'store_id' => $store->id,
            'type' => 'announcement',
            'label' => 'Open now',
            'url' => 'https://x.example',
            'config' => [
                'text' => 'We are open',
                '_i18n' => ['ar' => ['label' => 'مفتوح الآن', 'text' => 'نحن مفتوحون']],
            ],
        ]);

        return $store;
    }

    public function test_service_resolves_override_and_falls_back_to_base(): void
    {
        $store = $this->enabledStoreWithArOverride();

        app()->setLocale('en');
        $en = $this->svc()->blocksFor($store->id)[0];
        $this->assertSame('Open now', $en['label']);
        $this->assertSame('We are open', $en['config']['text']);
        $this->assertArrayNotHasKey('_i18n', $en['config']);

        app()->setLocale('ar');
        $ar = $this->svc()->blocksFor($store->id)[0];
        $this->assertSame('مفتوح الآن', $ar['label']);
        $this->assertSame('نحن مفتوحون', $ar['config']['text']);
        $this->assertArrayNotHasKey('_i18n', $ar['config']);
    }

    public function test_empty_override_falls_back_to_base(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id,
            'label' => 'Menu',
            'config' => ['_i18n' => ['ar' => ['label' => '']]], // empty = no override
        ]);

        app()->setLocale('ar');
        $this->assertSame('Menu', $this->svc()->blocksFor($store->id)[0]['label']);
    }

    public function test_public_page_renders_base_under_default_and_override_under_ar(): void
    {
        $store = $this->enabledStoreWithArOverride();

        // The announcement block renders its config `text` (SPEC §3), and the _i18n
        // override carries a translated `text` too — so assert the rendered text to
        // prove per-locale resolution end-to-end through the real Lane C view.
        // English locale → base string (test-env default locale may be ar, so
        // pin en explicitly to prove the base fallback).
        $this->withSession(['locale' => 'en'])->get("/{$store->slug}/bio")
            ->assertSee('We are open')->assertDontSee('نحن مفتوحون');

        // Arabic session → translated string, RTL
        $res = $this->withSession(['locale' => 'ar'])->get("/{$store->slug}/bio");
        $res->assertSee('نحن مفتوحون');
        $res->assertSeeHtml('dir="rtl"');
    }

    public function test_enabled_locales_helper_exposes_active_language_set(): void
    {
        $store = Store::factory()->create();
        $locales = $this->svc()->enabledLocales($store->id);
        $this->assertIsArray($locales);
        // the active-language set always contains at least the default locale
        $this->assertNotEmpty($locales);
    }
}

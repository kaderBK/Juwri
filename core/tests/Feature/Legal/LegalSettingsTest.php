<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Legal;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Support\LegalMarkerScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Juwri\Core\Legal\LegalDocuments;
use Juwri\Core\Legal\LegalPage;
use Juwri\Core\Legal\LegalSettings;
use Juwri\Core\Models\Language;
use Juwri\Core\Settings\PlatformSettings;
use Tests\TestCase;

/**
 * J51b: the admin-editable legal-page facts (legal_fill_* / legal_verify_* / legal_unused_* rows in the
 * platform settings store) resolve the {{FILL}} / {{VERIFY}} markers, drop "Not used" Sub-processor rows,
 * and feed the live status figure — with .env (LEGAL_*) as the fallback.
 */
class LegalSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        foreach (['en' => ['English', 'ltr'], 'ar' => ['Arabic', 'rtl']] as $code => [$name, $dir]) {
            Language::updateOrCreate(['code' => $code], ['name' => $name, 'direction' => $dir, 'active' => 1]);
        }
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        config(['legal.enabled' => true, 'legal.source_path' => base_path('tests/Fixtures/legal-open')]);
    }

    public function test_a_saved_value_appears_on_the_english_and_arabic_pages_and_replaces_the_marker(): void
    {
        LegalSettings::save(['hosting_provider' => 'Acme Cloud'], [], []);

        $en = $this->get('/legal/dpa')->assertOk()->getContent();
        $this->assertStringContainsString('Acme Cloud', $en);
        $this->assertStringNotContainsString('FILL:hosting_provider', $en);

        $ar = $this->get('/legal/dpa?lang=ar')->assertOk()->getContent();
        $this->assertStringContainsString('Acme Cloud', $ar);
        $this->assertStringNotContainsString('FILL:hosting_provider', $ar);
        $this->assertStringContainsString('lang="ar" dir="rtl"', $ar);
    }

    public function test_an_unused_subprocessor_removes_its_row_from_both_languages_without_a_raw_marker(): void
    {
        LegalSettings::save([], [], ['cdn' => '1']);

        $en = $this->get('/legal/subprocessors')->assertOk()->getContent();
        $this->assertStringNotContainsString('Content delivery and network security', $en);
        $this->assertStringNotContainsString('vendor_cdn', $en);
        $this->assertStringNotContainsString('{{FILL', $en);

        $ar = $this->get('/legal/subprocessors?lang=ar')->assertOk()->getContent();
        $this->assertStringNotContainsString('توصيل المحتوى وأمان الشبكة', $ar);
        $this->assertStringNotContainsString('vendor_cdn', $ar);
        $this->assertStringNotContainsString('{{FILL', $ar);
    }

    public function test_groups_cover_every_fill_and_verify_key_exactly_once(): void
    {
        $seen = [];
        $kinds = [];
        foreach (LegalSettings::groups() as $group) {
            $this->assertArrayHasKey('id', $group);
            $this->assertArrayHasKey('title', $group);
            foreach ($group['fields'] as $field) {
                $key = $field['key'];
                $this->assertArrayNotHasKey($key, $seen, "key [{$key}] appears more than once");
                $seen[$key] = true;
                $kinds[$key] = $field['kind'];
            }
        }

        $expected = array_merge(array_keys((array) config('legal.fill')), array_keys((array) config('legal.verify')));
        sort($expected);
        $actual = array_keys($seen);
        sort($actual);
        $this->assertSame($expected, $actual, 'every fill/verify key must appear exactly once');

        foreach (array_keys((array) config('legal.fill')) as $key) {
            $this->assertSame('fill', $kinds[$key], "fill key [{$key}] kind");
        }
        foreach (array_keys((array) config('legal.verify')) as $key) {
            $this->assertSame('verify', $kinds[$key], "verify key [{$key}] kind");
        }
    }

    public function test_status_totals_the_english_markers_and_filled_grows_after_save(): void
    {
        $english = array_values(array_filter(
            (new LegalMarkerScanner)->scan(LegalPage::root()),
            fn (array $e): bool => $e['locale'] === 'en',
        ));

        $before = LegalSettings::status();
        $this->assertSame(count($english), $before['total']);
        $this->assertSame(0, $before['filled'], 'nothing is filled at baseline');
        $this->assertFalse($before['ready']);

        LegalSettings::save(['hosting_provider' => 'Acme Cloud'], [], []);

        $after = LegalSettings::status();
        $this->assertSame(count($english), $after['total']);
        $this->assertGreaterThan($before['filled'], $after['filled']);
        $this->assertArrayHasKey('subprocessors', $after['pages']);
        $this->assertSame($after['filled'], $after['total'] - array_sum(array_column($after['pages'], 'remaining')));
    }

    public function test_legal_check_passes_after_saving_every_fact(): void
    {
        $fill = [];
        foreach (array_keys((array) config('legal.fill')) as $key) {
            $fill[$key] = 'Value '.$key;
        }
        $verify = [];
        foreach (array_keys((array) config('legal.verify')) as $key) {
            $verify[$key] = '1';
        }
        $unused = [];
        foreach (LegalSettings::SUBPROCESSORS as $x) {
            $unused[$x] = '0';
        }

        LegalSettings::save($fill, $verify, $unused);

        $this->assertSame(0, Artisan::call('legal:check'), Artisan::output());
    }

    public function test_no_public_page_renders_a_raw_marker(): void
    {
        foreach (array_keys(LegalDocuments::all()) as $document) {
            foreach (LegalPage::LOCALES as $locale) {
                $url = LegalDocuments::url($document).($locale === 'ar' ? '?lang=ar' : '');
                $html = $this->get($url)->assertOk()->getContent();

                $this->assertStringNotContainsString('{{FILL', $html, "{$document}/{$locale}");
                $this->assertStringNotContainsString('{{VERIFY', $html, "{$document}/{$locale}");
            }
        }
    }

    public function test_a_db_value_overrides_env_and_an_empty_value_falls_back(): void
    {
        config(['legal.fill.hosting_provider' => 'env-provider']);
        config(['legal.verify.ai_no_training' => false]);

        LegalSettings::save(['hosting_provider' => 'db-provider'], ['ai_no_training' => '1'], []);
        $this->assertSame('db-provider', config('legal.fill.hosting_provider'));
        $this->assertTrue(config('legal.verify.ai_no_training'));
        $this->assertSame('db-provider', LegalSettings::effective()['fill']['hosting_provider']);
        $this->assertTrue(LegalSettings::effective()['verify']['ai_no_training']);

        // A fresh request boots from env; the DB value still wins.
        config(['legal.fill.hosting_provider' => 'env-provider']);
        LegalSettings::applyToConfig();
        $this->assertSame('db-provider', config('legal.fill.hosting_provider'));

        // Saving empty forgets the row and the env value stands.
        LegalSettings::save(['hosting_provider' => ''], [], []);
        $this->assertFalse(PlatformSettings::has('legal_fill_hosting_provider'));
        $this->assertSame('env-provider', LegalSettings::effective()['fill']['hosting_provider']);
    }
}

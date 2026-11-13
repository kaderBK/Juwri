<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Mcamara\LaravelLocalization\LaravelLocalization;
use Tests\TestCase;

/**
 * Found by the ZAP scan of the Docker stack (Octane): the localization service memoises the supported locales the
 * first time it is used, and a worker lives across requests, so a language switched on after the worker booted made
 * every page 500 ("Locale 'ar' is not in the list of supported locales"). The per-request boot hands it the list.
 */
class LocalizationSingletonPerRequestTest extends TestCase
{
    public function test_the_per_request_boot_refreshes_the_memoised_locale_list(): void
    {
        config(['settings.include_language_code' => true]);
        $d = (string) config('app.locale');
        $new = $d === 'fr' ? 'de' : 'fr';
        $en = [$d => ['name' => 'Default', 'script' => 'Latn', 'native' => 'Default', 'regional' => $d.'_'.strtoupper($d)]];
        config(['laravellocalization.supportedLocales' => $en]);
        $service = app(LaravelLocalization::class);
        $service->setSupportedLocales($en);
        $this->assertArrayNotHasKey($new, $service->getSupportedLocales(), 'the worker booted with one language');

        // an admin (or the first-run seeders) switches Arabic on; the next request's boot runs
        config(['laravellocalization.supportedLocales' => $en + [$new => ['name' => 'New', 'script' => 'Latn', 'native' => 'New', 'regional' => $new.'_'.strtoupper($new)]]]);
        (new AppServiceProvider(app()))->forRequest();

        $this->assertArrayHasKey($new, app(LaravelLocalization::class)->getSupportedLocales());
        $this->assertStringContainsString('/'.$new, (string) app(LaravelLocalization::class)->getLocalizedURL($new, url('/features')));
    }

    /** Pin: the per-request boot re-reads the active languages (the memo of an earlier request in a worker is stale). */
    public function test_the_per_request_boot_re_reads_the_active_languages(): void
    {
        $src = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));
        $this->assertMatchesRegularExpression("/forgetInstance\\('memo\\.active_languages'\\);\\s*\\\$activeLocales = get_active_languages\\(\\);/", $src);
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Juwri\Core\Http\Middleware\RememberLocale;
use Illuminate\Http\Request;
use Tests\TestCase;

class RememberLocaleTest extends TestCase
{
    public function test_an_explicit_supported_prefix_sets_and_remembers_the_request_locale(): void
    {
        $localization = app('laravellocalization');
        $localization->setSupportedLocales([
            'ar' => ['name' => 'Arabic', 'script' => 'Arab', 'native' => 'العربية', 'regional' => 'ar_AE'],
            'en' => ['name' => 'English', 'script' => 'Latn', 'native' => 'English', 'regional' => 'en_US'],
        ]);
        app()->setLocale('ar');

        $request = Request::create('/en/crave-demo', 'GET');
        $response = (new RememberLocale)->handle(
            $request,
            static fn () => response(app()->getLocale())
        );

        $this->assertSame('en', $response->getContent());
        $this->assertSame('en', app()->getLocale());
        $this->assertSame('en', session('locale'));
    }

    public function test_an_unprefixed_store_slug_keeps_the_configured_default_locale(): void
    {
        $localization = app('laravellocalization');
        $localization->setSupportedLocales([
            'ar' => ['name' => 'Arabic', 'script' => 'Arab', 'native' => 'العربية', 'regional' => 'ar_AE'],
            'en' => ['name' => 'English', 'script' => 'Latn', 'native' => 'English', 'regional' => 'en_US'],
        ]);
        app()->setLocale('ar');

        $request = Request::create('/crave-demo', 'GET');
        $response = (new RememberLocale)->handle(
            $request,
            static fn () => response(app()->getLocale())
        );

        $this->assertSame('ar', $response->getContent());
        $this->assertSame('ar', session('locale'));
    }
}

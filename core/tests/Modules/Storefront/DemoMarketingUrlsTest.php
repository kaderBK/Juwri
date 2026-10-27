<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Services\CountryDefs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Language;
use Juwri\Modules\Content\Services\CompareDefs;
use Juwri\Modules\Content\Services\GuideDefs;
use Juwri\Modules\Verticals\Services\VerticalLandingDefs;
use Tests\TestCase;

/**
 * J62-1: the marketing paths listed in the owner demo checklist (appendix) really answer 200, in English and in
 * Arabic. The list is built from the same definition files the routes whitelist, so a renamed or removed page
 * fails here instead of in the owner's browser. /faq, /testimonials and /blog are switched by platform settings (404
 * on a database where those are off), so the checklist lists them separately.
 */
class DemoMarketingUrlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);

        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** @return list<string> */
    private function paths(): array
    {
        $paths = [
            route('home', [], false), route('pricing', [], false), route('features', [], false), route('themes', [], false),
            route('contact', [], false),
            route('whatsapp.landing', [], false), route('no-commission', [], false),
            route('tools.commission-calculator', [], false), route('tools.qr-generator', [], false),
        ];
        foreach (CountryDefs::slugs() as $slug) {
            $paths[] = route('country.landing', $slug, false);
        }
        foreach (CompareDefs::slugs() as $slug) {
            $paths[] = route('compare.show', $slug, false);
        }
        foreach (GuideDefs::slugs() as $slug) {
            $paths[] = route('guides.show', $slug, false);
        }
        foreach (VerticalLandingDefs::slugs() as $slug) {
            $paths[] = route('industries.show', $slug, false);
        }

        return $paths;
    }

    public function test_every_listed_marketing_path_answers_200_in_english(): void
    {
        $bad = [];
        foreach ($this->paths() as $path) {
            $status = $this->get($path)->getStatusCode();
            if ($status !== 200) {
                $bad[] = $path.' => '.$status;
            }
        }

        $this->assertSame([], $bad, 'marketing paths that do not answer 200');
    }

    public function test_the_home_and_pricing_pages_answer_in_arabic(): void
    {
        app()->setLocale('ar');

        $this->get(route('home', [], false))->assertOk();
        $this->get(route('pricing', [], false))->assertOk();
    }
}

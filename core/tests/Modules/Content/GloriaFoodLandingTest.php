<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GloriaFoodLandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\Installed::class);
        $this->shareLayoutGlobals();
    }

    public function test_landing_page_renders_with_eol_and_cta(): void
    {
        $res = $this->get(route('switch.gloriafood'))->assertOk();
        $res->assertSee('April 30, 2027');
        $res->assertSeeHtml('migrate=gloriafood'); // signup CTA carries the funnel param
        $res->assertSee('GloriaFood');
        // WI-16h: rendered on the kit marketing frame, FAQ JSON-LD kept.
        $res->assertSeeHtml('assets/owner-ui/');
        $res->assertSeeHtml('"@type":"FAQPage"');
    }

    public function test_route_registered_before_slug_catchall(): void
    {
        // If the {slug} catch-all shadowed it, this would 404 or resolve as a restaurant.
        $this->get('/switch-from-gloriafood')->assertOk();
    }
}

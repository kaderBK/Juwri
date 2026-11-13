<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Services\AppSessionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * stores P5-1: the /developers/apps build docs render, reflect the live scope /
 * event / TTL references, and link out to the real surfaces.
 */
class DeveloperAppsDocsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    public function test_docs_render_with_reflected_references(): void
    {
        // The doc prose is now translatable (___()); the default test locale is ar,
        // so pin English to assert the English copy + reflected identifiers.
        $res = $this->withSession(['locale' => 'en'])->get(route('developers.apps'))->assertOk();

        $res->assertSee('Build an app');
        $res->assertSee('App developer docs');

        // Scopes reflected from Passport::scopes()
        foreach (Passport::scopes() as $scope) {
            $res->assertSee($scope->id);
            $res->assertSee($scope->description);
        }

        // Webhook events + session TTL + usage endpoint
        $res->assertSee('order.paid');
        $res->assertSee('order.status_changed');
        $res->assertSee((string) AppSessionToken::TTL_SECONDS);
        $res->assertSeeHtml(url('/api/app/usage'));
    }

    public function test_docs_link_to_real_surfaces(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        $res = $this->get(route('developers.apps'))->assertOk();
        $res->assertSeeHtml(route('developers'));
        $res->assertSeeHtml(route('developers.themes'));
        $res->assertSeeHtml(url('/partner/apps/create'));
    }

    public function test_hub_links_to_app_docs(): void
    {
        $this->get(route('developers'))->assertOk()->assertSeeHtml(route('developers.apps'));
    }

    public function test_llms_txt_lists_app_docs(): void
    {
        $this->get(route('llms'))->assertOk()->assertSeeHtml(url('/developers/apps'));
    }

    public function test_documented_usage_endpoint_exists_and_needs_auth(): void
    {
        // The sample posts to /api/v1/app/usage — prove it is a real, guarded route
        // (not 404). Unauthenticated → 401/403, never 404.
        $status = $this->postJson('/api/app/usage', ['quantity' => 1])->status();
        $this->assertContains($status, [401, 403]);
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Modules\Content\Http\Controllers\HelpCenterController;
use Tests\TestCase;

/**
 * P6-09 V-15: the phone-agent feature exposes an owner setup guide — a public
 * Help Center page (English + Arabic), the phone-agent docs sources, the
 * dashboard context mapping, and the settings screen link to the guide.
 */
class PhoneAgentGuideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    private function allowLocale(string $code): void
    {
        config(['laravellocalization.supportedLocales' => array_merge(
            (array) config('laravellocalization.supportedLocales', []),
            [$code => ['name' => $code, 'script' => 'Arab', 'native' => $code, 'regional' => $code.'_'.strtoupper($code)]],
        )]);
    }

    public function test_help_center_serves_the_phone_agent_guide_in_english(): void
    {
        $res = $this->get('/help-center/phone-agent');

        $res->assertOk();
        $this->assertStringContainsString('phone', strtolower($res->getContent()));
    }

    public function test_help_center_serves_the_arabic_phone_agent_guide(): void
    {
        $this->allowLocale('ar');
        app()->setLocale('ar');

        $res = $this->get('/help-center/phone-agent');

        $res->assertOk();
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $res->getContent());
    }

    public function test_phone_agent_docs_sources_exist_with_the_expected_numbers(): void
    {
        $en = resource_path('docs/users/phone-agent.md');
        $ar = resource_path('docs/users/ar/phone-agent.md');

        $this->assertFileExists($en);
        $this->assertFileExists($ar);

        foreach ([$en, $ar] as $path) {
            $body = (string) file_get_contents($path);
            $this->assertStringContainsString('149', $body, $path);
            $this->assertStringContainsString('600', $body, $path);
        }

        $this->assertStringContainsString('559', (string) file_get_contents($ar));
    }

    public function test_context_map_routes_phone_agent_dashboard_to_the_guide_slug(): void
    {
        $map = (new \ReflectionClass(HelpCenterController::class))->getConstant('CONTEXT_MAP');

        $this->assertArrayHasKey('stores.phoneagent.*', $map);
        $this->assertSame('phone-agent', $map['stores.phoneagent.*']);
    }

    public function test_phone_agent_settings_screen_links_the_setup_guide(): void
    {
        $view = resource_path('views/owner/phone-agent/index.blade.php');

        $this->assertFileExists($view);
        $this->assertStringContainsString("help.show', 'phone-agent'", (string) file_get_contents($view));
    }
}

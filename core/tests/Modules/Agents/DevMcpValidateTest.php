<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dev MCP P4: validate_theme — the marketplace's own rules over the wire.
 */
class DevMcpValidateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\Installed::class);
    }

    private function validate(array $arguments)
    {
        return $this->postJson('/mcp/dev', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'validate_theme', 'arguments' => $arguments],
        ])->assertOk();
    }

    private function validateResult(array $arguments): array
    {
        return json_decode($this->validate($arguments)->json('result.content.0.text'), true);
    }

    private function starterFiles(): array
    {
        $base = resource_path('liquid-themes/starter/1');
        $files = [];
        foreach (['theme.json', 'templates/index.json', 'sections/hero.liquid', 'sections/menu-grid.liquid', 'snippets/item-card.liquid', 'config/settings_schema.json', 'locales/en.json', 'assets/css/style.css'] as $rel) {
            if (is_file("{$base}/{$rel}")) {
                $files[$rel] = (string) file_get_contents("{$base}/{$rel}");
            }
        }

        return $files;
    }

    public function test_the_starter_theme_validates_clean_in_package_mode(): void
    {
        $out = $this->validateResult(['files' => $this->starterFiles(), 'mode' => 'package']);

        $this->assertSame([], $out['errors']);
        $this->assertTrue($out['passed']);
    }

    public function test_an_unknown_filter_is_named_in_the_errors(): void
    {
        $out = $this->validateResult(['files' => ['sections/x.liquid' => '{{ item.name | image_url }}']]);

        $this->assertFalse($out['passed']);
        $rules = array_column($out['errors'], 'rule');
        $this->assertContains('unknown-filter', $rules);
        $this->assertStringContainsString("'image_url'", json_encode($out['errors']));
    }

    public function test_dangerous_markup_and_missing_snippets_are_reported_together(): void
    {
        $out = $this->validateResult(['files' => [
            'sections/x.liquid' => "<script>x</script>{% render 'nope' %}",
        ]]);

        $rules = array_column($out['errors'], 'rule');
        $this->assertContains('markup', $rules);
        $this->assertContains('missing-snippet', $rules);
    }

    public function test_package_mode_requires_manifest_and_entrypoint(): void
    {
        $out = $this->validateResult(['files' => ['sections/x.liquid' => 'hello'], 'mode' => 'package']);

        $messages = json_encode($out['errors']);
        $this->assertStringContainsString('theme.json', $messages);
        $this->assertStringContainsString('entrypoint', $messages);
    }

    public function test_rogue_php_files_are_rejected_by_layout_rules(): void
    {
        $out = $this->validateResult(['files' => ['evil.php' => '<?php ?>']]);

        $this->assertFalse($out['passed']);
        $this->assertContains('layout', array_column($out['errors'], 'rule'));
    }

    public function test_input_caps_return_clean_json_rpc_errors(): void
    {
        // Oversized single file.
        $this->validate(['files' => ['sections/a.liquid' => str_repeat('x', 210 * 1024)]])
            ->assertJsonPath('error.code', -32602);

        // Too many files.
        $files = [];
        for ($i = 0; $i < 41; $i++) {
            $files["snippets/s{$i}.liquid"] = 'x';
        }
        $this->validate(['files' => $files])->assertJsonPath('error.code', -32602);

        // files required.
        $this->validate([])->assertJsonPath('error.code', -32602);
    }
}

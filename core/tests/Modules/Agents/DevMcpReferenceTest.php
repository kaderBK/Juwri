<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Controllers\DeveloperDocsController;
use App\Http\Middleware\Installed;
use App\Services\ThemeCustomizerService;
use Juwri\Modules\Storefront\Storefront\Tags\CommerceTag;
use Juwri\Modules\Storefront\Storefront\Tags\SchemaTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dev MCP P2-5: drift pins. The whole value of the dev MCP is "the reference
 * can never lie about the engine" — these tests make that a suite guarantee:
 * reflection-vs-tool for drops/filters/tags, and behavior probes pinning the
 * documented setting types to ThemeCustomizerService's real mapping/validation.
 */
class DevMcpReferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Installed::class);
    }

    private function toolText(string $tool, array $arguments = []): string
    {
        return $this->postJson('/mcp/dev', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ])->assertOk()->json('result.content.0.text');
    }

    public function test_every_whitelisted_filter_appears_in_the_reference_tool(): void
    {
        $filters = DeveloperDocsController::filterReference();
        $this->assertNotEmpty($filters);

        $text = $this->toolText('get_liquid_reference', ['kind' => 'filters']);
        foreach ($filters as $filter) {
            $this->assertStringContainsString("`{$filter}`", $text, "filter {$filter} missing from tool output");
        }
    }

    public function test_every_drop_and_its_properties_appear_in_the_reference_tool(): void
    {
        $drops = DeveloperDocsController::dropReference();
        $this->assertNotEmpty($drops);

        $text = $this->toolText('get_liquid_reference', ['kind' => 'drops']);
        foreach ($drops as $drop => $props) {
            $this->assertStringContainsString("### {$drop}", $text, "drop {$drop} missing");
            foreach ($props as $p) {
                $this->assertStringContainsString("`{$p['name']}`", $text, "{$drop}.{$p['name']} missing");
            }
        }
    }

    public function test_every_registered_tag_appears_in_the_reference_tool(): void
    {
        $text = $this->toolText('get_liquid_reference', ['kind' => 'tags']);

        // Names come from the tag classes the environment factory registers.
        foreach ([SchemaTag::class, CommerceTag::class] as $tagClass) {
            $this->assertStringContainsString('{% '.$tagClass::tagName().' %}', $text);
        }
    }

    public function test_generated_corpus_docs_are_current(): void
    {
        $this->artisan('docs:build-reference', ['--check' => true])->assertExitCode(0);
    }

    public function test_documented_setting_types_match_the_customizer_mapping(): void
    {
        // The schema-settings doc promises these types; pin each to the
        // service's REAL uiField mapping so the doc can't drift.
        $expectations = [
            'text' => 'text',
            'textarea' => 'textarea', // Theme Studio B0.8: its own multi-line control
            'color' => 'color',
            'checkbox' => 'toggle',
            'toggle' => 'toggle',
            'select' => 'select',
            'image' => 'text',   // documented: string, text input today
            // Theme Studio B0.8 types keep their own control.
            'range' => 'range',
            'number' => 'number',
            'image_picker' => 'image_picker',
            'font_pair' => 'font_pair',
            'color_token' => 'color_token',
            'richtext' => 'richtext',
            'url' => 'url',
            'category' => 'category',
            'item_list' => 'item_list',
            'branch' => 'branch',
            'made_up' => 'text',   // documented: unknown types fall back to text
        ];

        $uiField = new \ReflectionMethod(ThemeCustomizerService::class, 'uiField');

        foreach ($expectations as $schemaType => $uiType) {
            $field = $uiField->invoke(null, ['id' => 'x', 'type' => $schemaType, 'options' => ['a']]);
            $this->assertSame($uiType, $field['type'], "uiField mapping for '{$schemaType}' drifted");
        }
    }

    public function test_documented_validation_rules_match_the_customizer(): void
    {
        $schema = ['settings' => [['group' => 'g', 'fields' => [
            ['id' => 'c', 'type' => 'color'],
            ['id' => 't', 'type' => 'toggle'],
            ['id' => 's', 'type' => 'select', 'options' => ['a', 'b']],
            ['id' => 'x', 'type' => 'text'],
        ]]]];

        // Documented-valid values pass…
        $this->assertSame([], ThemeCustomizerService::validate($schema, [
            'c' => '#3fd463', 't' => true, 's' => 'a', 'x' => 'hello',
        ]));
        // …hex-8 colors too (doc says 3–8 hex digits).
        $this->assertSame([], ThemeCustomizerService::validate($schema, ['c' => '#3fd463ff']));

        // Documented-invalid values fail.
        $errors = ThemeCustomizerService::validate($schema, [
            'c' => 'red', 't' => 'yes', 's' => 'z', 'x' => 42,
        ]);
        $this->assertSame(['c', 't', 's', 'x'], array_keys($errors));
    }

    public function test_schema_doc_type_sections_cover_exactly_the_documented_types(): void
    {
        $full = $this->toolText('get_section_schema');
        foreach (['text', 'textarea', 'color', 'checkbox / toggle', 'select', 'image', 'range', 'number',
            'image_picker', 'font_pair', 'color_token', 'richtext', 'url', 'category / item_list / branch'] as $heading) {
            $this->assertStringContainsString("## {$heading}", $full);
        }

        // And the per-type extraction honors both toggle aliases.
        $this->assertStringContainsString('boolean', $this->toolText('get_section_schema', ['setting_type' => 'checkbox']));
        $this->assertStringContainsString('boolean', $this->toolText('get_section_schema', ['setting_type' => 'toggle']));
        // B0.8: the picker trio shares one section, reachable by each name.
        $this->assertStringContainsString('store', $this->toolText('get_section_schema', ['setting_type' => 'item_list']));
        $this->assertStringContainsString('token name', $this->toolText('get_section_schema', ['setting_type' => 'color_token']));
    }
}

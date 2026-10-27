<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Juwri\Modules\Storefront\Storefront\MarketplaceTheme;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Juwri\Modules\Storefront\Storefront\ThemeLinter;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Dev MCP theme-authoring gap (Class 1): `validate_theme`'s hard gates (parse,
 * filters, snippets, schema shape) prove a theme is SAFE and well-formed —
 * they say nothing about whether it actually WORKS as a storefront. A theme
 * that renders a static menu with real markup but zero `{% commerce %}`,
 * zero `data-mb-add` and zero `data-mb-open` lints 100% clean without these
 * rules. ThemeLinter::featureWiringWarnings() closes that gap as ADVISORY
 * warnings only — never a hard rejection, since e.g. a deliberately
 * read-only "browse the menu" theme is a legitimate design, not a bug.
 */
class ThemeLinterFeatureWiringTest extends TestCase
{
    private const BASE = 'app/marketplace-themes/featurewiringtest';

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path(self::BASE));
        parent::tearDown();
    }

    /** @param  array<string,string>  $files  rel path => content */
    private function writeTheme(array $files): MarketplaceTheme
    {
        $dir = storage_path(self::BASE.'/1');
        File::deleteDirectory(storage_path(self::BASE));
        foreach ($files as $rel => $content) {
            File::ensureDirectoryExists(dirname($dir.'/'.$rel));
            File::put($dir.'/'.$rel, $content);
        }

        return MarketplaceThemeResolver::resolve('mp:featurewiringtest@1', gate: false);
    }

    /** @return list<string> */
    private function warningRules(array $report): array
    {
        return array_values(array_unique(array_column($report['warnings'], 'rule')));
    }

    public function test_a_commerce_less_deep_link_less_theme_produces_all_four_warnings(): void
    {
        $theme = $this->writeTheme([
            'templates/index.json' => json_encode(['sections' => ['a' => ['type' => 'menu']], 'order' => ['a']]),
            'sections/menu.liquid' => <<<'LIQ'
                {% if flags.allow_order %}
                <div class="menu">
                  {% for item in menu.items %}
                    <div class="card" data-mb-item="{{ item.id }}">
                      <h3>{{ item.name | escape }}</h3>
                      <span class="price">{{ item.price }}</span>
                      <button type="button">Order now</button>
                    </div>
                  {% endfor %}
                </div>
                {% endif %}
                LIQ,
        ]);

        $report = (new ThemeLinter)->lint($theme);

        // Advisory only — must NEVER fail the lint or add a hard error.
        $this->assertTrue($report['passed'], 'feature-wiring gaps must never fail the lint: '.json_encode($report['errors']));
        $this->assertSame([], $report['errors']);

        $rules = $this->warningRules($report);
        $this->assertContains('commerce-tag-missing', $rules, 'order UI with no {% commerce %} must warn');
        $this->assertContains('item-deep-link-missing', $rules, 'item cards with no data-mb-open must warn');
        $this->assertContains('cart-add-missing', $rules, 'item cards with no data-mb-add must warn');
        $this->assertContains('price-missing-money', $rules, 'raw item.price with no | money must warn');
    }

    public function test_the_crave_reference_theme_produces_none_of_the_new_warnings(): void
    {
        $crave = MarketplaceThemeResolver::resolve('mp:crave@1', gate: false);
        $this->assertNotNull($crave, 'the crave/1 reference theme must resolve');

        $report = (new ThemeLinter)->lint($crave);

        $this->assertTrue($report['passed']);
        $rules = $this->warningRules($report);
        $this->assertNotContains('commerce-tag-missing', $rules);
        $this->assertNotContains('item-deep-link-missing', $rules);
        $this->assertNotContains('cart-add-missing', $rules);
        $this->assertNotContains('price-missing-money', $rules);
    }

    /**
     * `validate_theme` (the MCP tool authors actually call) runs `lintFiles()`
     * over an in-memory map, not `lint()` over a resolved theme on disk — pin
     * the same warnings through that path too.
     */
    public function test_lint_files_reports_the_same_warnings_for_the_mcp_validate_theme_path(): void
    {
        $files = [
            'templates/index.json' => json_encode(['sections' => ['a' => ['type' => 'menu']], 'order' => ['a']]),
            'sections/menu.liquid' => <<<'LIQ'
                {% if flags.allow_order %}
                {% for item in menu.items %}
                  <div data-mb-item="{{ item.id }}"><span>{{ item.price }}</span></div>
                {% endfor %}
                {% endif %}
                LIQ,
        ];

        $report = (new ThemeLinter)->lintFiles($files);

        $rules = $this->warningRules($report);
        $this->assertContains('commerce-tag-missing', $rules);
        $this->assertContains('item-deep-link-missing', $rules);
        $this->assertContains('cart-add-missing', $rules);
        $this->assertContains('price-missing-money', $rules);
    }

    public function test_a_read_only_menu_with_no_order_affordance_does_not_warn_about_commerce(): void
    {
        // Ordering deliberately off / no cart UI anywhere is a legitimate
        // design (a "browse the menu" theme) — it must not be told to wire
        // {% commerce %} just because it lists items.
        $theme = $this->writeTheme([
            'templates/index.json' => json_encode(['sections' => ['a' => ['type' => 'menu']], 'order' => ['a']]),
            'sections/menu.liquid' => <<<'LIQ'
                <div class="menu">
                  {% for category in menu.categories %}
                    <h2>{{ category.name | escape }}</h2>
                  {% endfor %}
                </div>
                LIQ,
        ]);

        $report = (new ThemeLinter)->lint($theme);
        $this->assertNotContains('commerce-tag-missing', $this->warningRules($report));
    }

    public function test_data_mb_open_cart_does_not_satisfy_the_item_deep_link_check(): void
    {
        // data-mb-open-cart (a cart-open button) must not be mistaken for
        // data-mb-open (the per-item deep link) — they are different controls.
        $theme = $this->writeTheme([
            'templates/index.json' => json_encode(['sections' => ['a' => ['type' => 'menu']], 'order' => ['a']]),
            'sections/menu.liquid' => <<<'LIQ'
                {% for item in menu.items %}
                  <div data-mb-item="{{ item.id }}">
                    <span>{{ item.price | money }}</span>
                    <button data-mb-add="{{ item.id }}">+</button>
                  </div>
                {% endfor %}
                <button data-mb-open-cart>Cart</button>
                {% commerce %}
                LIQ,
        ]);

        $report = (new ThemeLinter)->lint($theme);
        $this->assertContains('item-deep-link-missing', $this->warningRules($report));
    }
}

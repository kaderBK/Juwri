<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Juwri\Modules\Storefront\Services\Rendering\LiquidEnvironmentFactory;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Services\Rendering\RenderClient;
use Juwri\Modules\Storefront\Services\Rendering\RenderFailedException;
use Tests\TestCase;

/**
 * P2-2b: the closed filter/tag whitelist. A theme may use only these filters
 * (money/money_code/t/media_url/theme_asset) + the standard safe set; anything
 * else errors at upload. `t` is read-only (no key pollution), `media_url` is
 * server-scoped + traversal-safe, `theme_asset` rejects traversal, and
 * `{% schema %}` is parsed then stripped.
 */
class LiquidThemeFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function renderer(): LiquidRenderer
    {
        return new LiquidRenderer(new LiquidEnvironmentFactory, new RenderClient);
    }

    public function test_money_filters_wrap_the_price_formatters(): void
    {
        config(['settings.currency_sign' => '$', 'settings.currency_pos' => 1, 'settings.currency_code' => 'USD']);

        $this->assertSame(price_symbol_format(5.0), $this->renderer()->render('{{ 5 | money }}'));
        $this->assertSame(price_code_format(5.0), $this->renderer()->render('{{ 5 | money_code }}'));
    }

    public function test_t_filter_translates_a_known_key(): void
    {
        App::setLocale('en');
        // 'new_menu' exists in lang/en/lang.php.
        $expected = (string) trans('lang.new_menu', [], 'en');
        $this->assertSame($expected, $this->renderer()->render("{{ 'new_menu' | t }}"));
    }

    public function test_t_filter_is_read_only_and_never_creates_a_key(): void
    {
        $probe = 'p2b_readonly_probe_key';
        $this->assertFalse(Lang::has('lang.'.$probe));

        $file = base_path('lang/en/lang.php');
        $before = File::get($file);

        // Unknown key → returns the key string unchanged, writes nothing.
        $out = $this->renderer()->render("{{ '$probe' | t }}");

        $this->assertSame($probe, $out);
        $this->assertSame($before, File::get($file), 't filter must not write to language files');
    }

    public function test_media_url_is_server_scoped_and_rejects_traversal(): void
    {
        $registers = ['store_id' => 7];

        $this->assertSame(
            media_url('storage/menu/', 'burger.png', 7),
            $this->renderer()->render("{{ 'burger.png' | media_url: 'menu' }}", [], 'live', $registers)
        );
        // Whitelisted token maps to the logo prefix.
        $this->assertSame(
            media_url('storage/store/logo/', 'logo.png', 7),
            $this->renderer()->render("{{ 'logo.png' | media_url: 'logo' }}", [], 'live', $registers)
        );
        // Traversal in the filename → blank, never a path escape.
        $this->assertSame('', $this->renderer()->render("{{ '../../.env' | media_url: 'menu' }}", [], 'live', $registers));
    }

    public function test_theme_asset_resolves_by_lookup_map_and_rejects_traversal(): void
    {
        $registers = ['theme_asset_map' => ['css/style.css' => '/assets/mp/crave/1/style.abc123.css']];

        $this->assertSame(
            '/assets/mp/crave/1/style.abc123.css',
            $this->renderer()->render("{{ 'css/style.css' | theme_asset }}", [], 'live', $registers)
        );
        // A key that isn't a real asset → blank (pure lookup, never a path).
        $this->assertSame('', $this->renderer()->render("{{ '../secret' | theme_asset }}", [], 'live', $registers));
        // No map, base register set → safe join of a validated relative path.
        $this->assertSame(
            'https://cdn.test/style.css',
            $this->renderer()->render("{{ 'style.css' | theme_asset }}", [], 'live', ['theme_asset_base' => 'https://cdn.test'])
        );
    }

    public function test_unknown_filter_is_rejected_at_upload(): void
    {
        $this->expectException(RenderFailedException::class);
        $this->renderer()->render("{{ 'x' | no_such_filter }}", [], 'upload');
    }

    public function test_schema_tag_is_parsed_and_stripped(): void
    {
        $out = $this->renderer()->render('A{% schema %}{"name":"Crave","settings":[]}{% endschema %}B');
        $this->assertSame('AB', $out);
    }

    public function test_schema_tag_rejects_invalid_json_at_upload(): void
    {
        $this->expectException(RenderFailedException::class);
        $this->renderer()->render('{% schema %}{not valid json{% endschema %}', [], 'upload');
    }
}

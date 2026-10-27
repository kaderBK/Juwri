<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P5-1: structured integrations — strict ID validation (the security
 * boundary), official snippets emitted on the storefront with escaped IDs,
 * available WITHOUT the custom-code grant.
 */
class StructuredIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);

        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function makeOwnerAndRestaurant(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => [
                'restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999,
                'category_limit' => 999, 'allow_ordering' => 1, 'table_qr' => 0,
                'allow_pro' => '0', 'allow_advanced' => '0', 'allow_on_free' => '0',
                'allow_on_try' => '0', 'allow_on_main' => '0', 'allow_viewonly' => '0',
                'allow_custom' => '0', 'allow_onlybio' => '0',
            ],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'S', 'description' => 'D',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'crave');

        return [$owner, $store];
    }

    public function test_valid_ids_save_and_injection_shapes_are_rejected(): void
    {
        [$owner, $store] = $this->makeOwnerAndRestaurant();

        // Official shapes pass.
        $this->actingAs($owner)->postJson('integrations-structured/'.$store->id, [
            'ga_id' => 'G-ABC123XYZ', 'meta_pixel_id' => '1234567890', 'font_family' => 'Plus Jakarta Sans',
        ])->assertOk();

        // Injection attempts fail validation — the whole point.
        foreach ([
            ['ga_id' => 'G-1"><script>alert(1)</script>'],
            ['ga_id' => 'javascript:alert(1)'],
            ['meta_pixel_id' => '123<script>'],
            ['font_family' => 'Roboto"></link><script>x()</script>'],
        ] as $bad) {
            $this->actingAs($owner)->postJson('integrations-structured/'.$store->id, $bad)
                ->assertStatus(422);
        }

        // Cross-tenant blocked.
        $other = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->actingAs($other)->postJson('integrations-structured/'.$store->id, ['ga_id' => 'G-XXXXXX'])
            ->assertNotFound();
    }

    public function test_storefront_emits_official_snippets_without_the_custom_code_grant(): void
    {
        [$owner, $store] = $this->makeOwnerAndRestaurant();
        $this->assertEmpty($store->custom_code_enabled, 'no custom-code grant needed');

        $this->actingAs($owner)->postJson('integrations-structured/'.$store->id, [
            'ga_id' => 'G-TEST999', 'meta_pixel_id' => '987654321', 'font_family' => 'Plus Jakarta Sans',
        ])->assertOk();
        auth()->logout();

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertStringContainsString('googletagmanager.com/gtag/js?id=G-TEST999', $html);
        $this->assertStringContainsString('"G-TEST999"', $html); // @json-escaped config id
        $this->assertStringContainsString("fbq('init', \"987654321\")", $html);
        $this->assertStringContainsString('fonts.googleapis.com/css2?family=Plus+Jakarta+Sans', $html);
    }

    public function test_storefront_emits_nothing_when_unset(): void
    {
        [, $store] = $this->makeOwnerAndRestaurant();

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertStringNotContainsString('googletagmanager.com/gtag', $html);
        $this->assertStringNotContainsString('fbevents.js', $html);
    }

    /**
     * D1 (google-suite): a GTM- container id must load the GTM container
     * (gtm.js) + the no-JS ns.html iframe — NOT the gtag/js snippet, which the
     * controller RULES accept but the partial used to (wrongly) emit for every
     * id shape, so GTM containers silently did nothing.
     */
    public function test_gtm_container_id_renders_gtm_not_gtag(): void
    {
        [$owner, $store] = $this->makeOwnerAndRestaurant();

        $this->actingAs($owner)->postJson('integrations-structured/'.$store->id, [
            'ga_id' => 'GTM-TEST99',
        ])->assertOk();
        auth()->logout();

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        // GTM container loader, inert until marketing consent (P1-23); the no-JS iframe fallback is gone (it
        // would load without consent).
        $this->assertStringContainsString('googletagmanager.com/gtm.js', $html);
        $this->assertMatchesRegularExpression('~<script type="text/plain" data-cookie-consent="marketing"[^>]*>\s*\(function\(w,d,s,l,i\)~', $html);
        $this->assertStringNotContainsString('googletagmanager.com/ns.html', $html);
        $this->assertStringContainsString('"GTM-TEST99"', $html); // @json-escaped IIFE arg
        // The gtag path must NOT fire for a GTM container.
        $this->assertStringNotContainsString('gtag/js?id=GTM-TEST99', $html);
        $this->assertStringNotContainsString("gtag('config'", $html);
    }

    /**
     * D1 regression guard: a plain G- analytics id still renders the gtag
     * snippet and never the GTM container loader.
     */
    public function test_ga_id_still_renders_gtag_not_gtm(): void
    {
        [$owner, $store] = $this->makeOwnerAndRestaurant();

        $this->actingAs($owner)->postJson('integrations-structured/'.$store->id, [
            'ga_id' => 'G-TEST999',
        ])->assertOk();
        auth()->logout();

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertStringContainsString('gtag/js?id=G-TEST999', $html);
        $this->assertStringContainsString("gtag('config'", $html);
        $this->assertStringNotContainsString('googletagmanager.com/gtm.js', $html);
        $this->assertStringNotContainsString('ns.html?id=', $html);
    }

    /**
     * D3: a browser GET renders the HTML integrations dashboard page (form with
     * the GA/GTM, pixel, font and map-toggle fields), not raw JSON.
     */
    public function test_integrations_page_renders_html_form_for_owner(): void
    {
        [$owner, $store] = $this->makeOwnerAndRestaurant();

        $res = $this->actingAs($owner)->get(route('integrations.structured.show', $store->id))->assertOk();
        $res->assertSeeHtml('name="ga_id"');
        $res->assertSeeHtml('name="meta_pixel_id"');
        $res->assertSeeHtml('name="store_map_embed"');
        $res->assertSeeHtml(route('integrations.structured.save', $store->id));
    }

    /** D3: the JSON contract is preserved for XHR/API consumers (now incl. the map flag). */
    public function test_show_returns_json_for_xhr(): void
    {
        [$owner, $store] = $this->makeOwnerAndRestaurant();

        $this->actingAs($owner)
            ->getJson(route('integrations.structured.show', $store->id))
            ->assertOk()
            ->assertJsonStructure(['ga_id', 'meta_pixel_id', 'font_family', 'store_map_embed'])
            ->assertJsonPath('store_map_embed', '0');
    }

    /**
     * D3: a plain HTML form POST round-trips (redirect + flash, not JSON) and
     * persists the map toggle + ids; toggling back to 0 also persists.
     */
    public function test_form_post_round_trips_map_toggle(): void
    {
        [$owner, $store] = $this->makeOwnerAndRestaurant();

        // Enable the map + set a GTM id via a browser form post (no JSON header).
        $this->actingAs($owner)
            ->post('integrations-structured/'.$store->id, ['ga_id' => 'GTM-ABC123', 'store_map_embed' => '1'])
            ->assertRedirect(route('integrations.structured.show', $store->id))
            ->assertSessionHas('success');

        $this->assertSame('1', (string) StoreOption::getStoreOption($store->id, 'store_map_embed'));
        $this->assertSame('GTM-ABC123', StoreOption::getStoreOption($store->id, 'integration_ga_id'));

        // JSON view reflects it.
        $this->actingAs($owner)
            ->getJson(route('integrations.structured.show', $store->id))
            ->assertOk()
            ->assertJsonPath('store_map_embed', '1');

        // Toggle back off.
        $this->actingAs($owner)
            ->post('integrations-structured/'.$store->id, ['store_map_embed' => '0'])
            ->assertRedirect();

        $this->assertSame('0', (string) StoreOption::getStoreOption($store->id, 'store_map_embed'));
    }
}

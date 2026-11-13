<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\LoyaltyProgram;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Models\StoreReview;
use App\Storefront\StorefrontExtras;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\Rendering\LiquidEnvironmentFactory;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Services\Rendering\RenderClient;
use Juwri\Modules\Storefront\Services\Rendering\RenderFailedException;
use Juwri\Modules\Storefront\Storefront\Tags\WidgetTag;
use Juwri\Modules\Storefront\Storefront\ThemeContext;
use Keepsuit\Liquid\Exceptions\SyntaxException;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Theme Studio B0.7: `{% widget 'name' %}` renders an allow-listed platform
 * Blade partial from a server-set register — never from theme variables — and
 * only when the store's feature switch (the same gate Blade uses) is on.
 */
class StudioWidgetTagTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'app/marketplace-themes/widgettest';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path(self::BASE));
        parent::tearDown();
    }

    private function store(array $options = []): Store
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
            'address' => '1 Main St', 'phone' => '+966 50 123 4567', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($options as $key => $value) {
            StoreOption::updateStoreOption($store->id, $key, $value);
        }

        return $store;
    }

    /** The render-site vars StoreController builds (incl. B0.6 extras). */
    private function vars(Store $store, array $extra = []): array
    {
        $o = (object) store_options($store->id);
        $flags = ['on_table' => false];
        $vars = [
            'store' => $store, 'postOptions' => $o, 'flags' => $flags,
            'branches' => Branch::where('store_id', $store->id)->orderBy('position')->get(),
            'activeBranch' => null, 'customer' => null, 'table' => null,
        ] + $extra;
        $vars['extras'] = StorefrontExtras::for($store, $o, $flags);

        return $vars;
    }

    private function render(string $source, array $vars, ?array $registers = null, string $mode = 'live', array $globals = []): string
    {
        $ctx = app(ThemeContext::class);

        return new LiquidRenderer(new LiquidEnvironmentFactory, new RenderClient)->render(
            $source,
            $globals + $ctx->build($vars),
            $mode,
            $registers ?? $ctx->registers($vars),
        );
    }

    public function test_allow_list(): void
    {
        $this->assertSame(
            ['reservation', 'waitlist', 'catering', 'loyalty', 'store-reviews', 'branch-switcher'],
            WidgetTag::names(),
        );
        foreach (WidgetTag::names() as $name) {
            $this->assertTrue(view()->exists('store_templates._partials.widgets.'.$name), $name);
        }
        $this->assertNotContains('_base', WidgetTag::names(), 'shared chrome is not requestable');
    }

    public function test_unknown_widget_is_a_strict_parse_error_and_empty_live(): void
    {
        $strict = (new LiquidEnvironmentFactory)->upload();
        foreach (["{% widget 'nope' %}", "{% widget '_base' %}", '{% widget name %}', "{% widget 'reservation', color: 'red' %}"] as $src) {
            try {
                $strict->parseString($src);
                $this->fail('strict parse accepted: '.$src);
            } catch (SyntaxException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        $store = $this->store(['enable_reservations' => '1']);
        $this->assertSame('a|b', $this->render("a|{% widget 'nope' %}b", $this->vars($store)));

        $this->expectException(RenderFailedException::class);
        $this->render("{% widget 'nope' %}", $this->vars($store), null, 'upload');
    }

    public function test_feature_off_renders_nothing_and_on_renders_the_widget(): void
    {
        $off = $this->store();
        $this->assertSame('[]', $this->render("[{% widget 'reservation' %}{% widget 'waitlist' %}{% widget 'branch-switcher' %}{% widget 'store-reviews' %}{% widget 'loyalty' %}{% widget 'catering' %}]", $this->vars($off)));

        $on = $this->store(['enable_reservations' => '1', 'enable_waitlist' => '1']);
        $html = $this->render("{% widget 'reservation' %}{% widget 'waitlist', label: 'Queue up' %}", $this->vars($on));

        $this->assertStringContainsString('id="mbw-reservation-dialog"', $html);
        $this->assertStringContainsString('action="'.route('publicView.reservation.book', $on->slug).'"', $html);
        $this->assertStringContainsString('action="'.route('publicView.waitlist.join', $on->slug).'"', $html);
        $this->assertStringContainsString('Queue up', $html);
        $this->assertStringContainsString('name="_token"', $html, 'CSRF field');
        $this->assertSame(1, substr_count($html, 'data-mbw-base'), 'shared chrome written once');
    }

    public function test_widget_context_cannot_be_forged_from_liquid(): void
    {
        $store = $this->store(); // every feature off
        $vars = $this->vars($store);
        $forged = ['store' => $store, 'features' => ['reservations' => true]];

        // A theme variable / assign named like the register does nothing.
        $out = $this->render(
            "{% assign widget_context = 'x' %}{% widget 'reservation' %}",
            $vars, null, 'live', ['widget_context' => $forged],
        );
        $this->assertStringNotContainsString('mbw-reservation', $out);

        // No register at all (e.g. a render site that never set it) → nothing.
        $this->assertSame('', $this->render("{% widget 'reservation' %}", $vars, ['store_id' => $store->id]));

        // The features GLOBAL is theme-visible data, not the gate the tag reads.
        $on = $this->store(['enable_reservations' => '1']);
        $onVars = $this->vars($on);
        $registers = app(ThemeContext::class)->registers($vars); // store with it OFF
        $this->assertSame('', $this->render("{% widget 'reservation' %}", $onVars, $registers));
    }

    public function test_scripts_and_styles_carry_the_csp_nonce_and_repeat_includes_dedupe(): void
    {
        $store = $this->store(['enable_reservations' => '1']);
        LoyaltyProgram::create(['store_id' => $store->id, 'type' => 'points', 'reward_threshold' => 50, 'reward_description' => 'Free tea', 'active' => true]);
        $vars = $this->vars($store, ['customer' => null]);
        $vars['extras']['features']['loyalty'] = true;
        $html = $this->render("{% widget 'reservation' %}{% widget 'reservation' %}{% widget 'loyalty' %}", $vars);

        $nonce = csp_nonce();
        $this->assertNotSame('', $nonce);
        preg_match_all('/<(script|style)\b[^>]*>/i', $html, $m);
        $this->assertNotEmpty($m[0]);
        foreach ($m[0] as $tag) {
            $this->assertStringContainsString('nonce="'.$nonce.'"', $tag);
        }
        $this->assertSame(1, substr_count($html, 'id="mbw-reservation-dialog"'), 'one dialog per page');
        $this->assertSame(2, substr_count($html, 'data-mbw-open="reservation"'), 'every trigger kept');
        $this->assertStringContainsString('if (window.mbWidgets) { return; }', $html, 'JS is idempotent');
        $this->assertStringNotContainsString(' onclick=', $html);

        // Guest loyalty = sign-in prompt on the commerce runtime's account hook.
        $this->assertStringContainsString('Free tea', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*data-mb-account[^>]*>/', $html);
    }

    public function test_branch_switcher_and_store_reviews_self_gate_on_their_data(): void
    {
        $store = $this->store(['show_store_reviews' => '1']);
        $this->assertSame('', $this->render("{% widget 'branch-switcher' %}{% widget 'store-reviews' %}", $this->vars($store)),
            'one branch / no approved reviews → nothing');

        Branch::create(['store_id' => $store->id, 'name' => 'Downtown', 'active' => true, 'position' => 1]);
        Branch::create(['store_id' => $store->id, 'name' => 'Harbour', 'active' => true, 'position' => 2]);
        StoreReview::create(['store_id' => $store->id, 'reviewer_name' => 'Mona', 'stars' => 4, 'body' => 'Lovely <b>tea</b>', 'status' => 'approved']);
        StoreReview::create(['store_id' => $store->id, 'reviewer_name' => 'Hidden', 'stars' => 1, 'status' => 'pending']);

        $html = $this->render("{% widget 'branch-switcher' %}{% widget 'store-reviews' %}", $this->vars($store));
        $this->assertStringContainsString('Downtown', $html);
        $this->assertStringContainsString('branch_id=', $html);
        $this->assertStringContainsString('Mona', $html);
        $this->assertStringContainsString('Lovely &lt;b&gt;tea&lt;/b&gt;', $html, 'review body escaped');
        $this->assertStringNotContainsString('Hidden', $html, 'approved only');
    }

    public function test_liquid_theme_page_renders_widgets_through_public_view(): void
    {
        config(['settings.admin_allow_catering' => true]);
        $dir = storage_path(self::BASE.'/1');
        File::ensureDirectoryExists($dir.'/templates');
        File::put($dir.'/templates/index.liquid',
            "<main data-probe>{% widget 'reservation' %}{% widget 'waitlist' %}{% widget 'catering' %}{% widget 'nope' %}</main>");

        $store = $this->store([
            'store_template' => 'mp:widgettest@1',
            'enable_reservations' => '1', 'enable_waitlist' => '1', 'catering_enabled' => '1',
        ]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 5, 'active' => '1']);

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertStringContainsString('<main data-probe>', $html, 'Liquid theme rendered (not the Blade fallback)');
        $this->assertStringContainsString('id="mbw-reservation-dialog"', $html);
        $this->assertStringContainsString('id="mbw-waitlist-dialog"', $html);
        $this->assertStringContainsString('id="mbw-catering-dialog"', $html);
        $this->assertStringContainsString('action="'.route('publicView.catering.request', $store->slug).'"', $html);
        preg_match('/<style nonce="([^"]+)" data-mbw-base>/', $html, $m);
        $this->assertNotEmpty($m[1] ?? null, 'widget chrome is nonce\'d');

        // Posting the booking re-opens the dialog with the success flash.
        $this->from(route('publicView', $store->slug))
            ->post(route('publicView.reservation.book', $store->slug), [
                '_mbw' => 'reservation', 'name' => 'Sam', 'party_size' => 2,
                'reserved_at' => now()->addDay()->format('Y-m-d\TH:i'),
            ])->assertRedirect(route('publicView', $store->slug));
        $after = $this->get(route('publicView', $store->slug))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<dialog[^>]*id="mbw-reservation-dialog"[^>]*data-mbw-autoopen/', $after);
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform\Admin;

use App\Support\PlatformSecrets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Policies\Admin\PanelAbilities;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Feature\Admin\InteractsWithAdminPanel;
use Tests\TestCase;

/**
 * WI-08 characterisation: the platform settings screen (one form per
 * section, each marked by a hidden field) and the admin dashboard.
 * Written against the pre-rewrite controllers; the rewrite must keep it green.
 * Platform integrations (Credential rows) are covered by their own tests.
 */
class SettingsAdminContractTest extends TestCase
{
    use InteractsWithAdminPanel;
    use RefreshDatabase;

    private string $envDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdminPanel();

        // Some sections also write .env keys: point the app at a scratch copy.
        $this->envDir = sys_get_temp_dir().'/wi08-settings-env-'.getmypid().'-'.uniqid();
        @mkdir($this->envDir);
        file_put_contents($this->envDir.'/.env', "APP_NAME=test\n");
        $this->app->useEnvironmentPath($this->envDir);
    }

    protected function tearDown(): void
    {
        @unlink($this->envDir.'/.env');
        @rmdir($this->envDir);
        parent::tearDown();
    }

    private function option(string $name): mixed
    {
        return PlatformSecrets::decrypt(PlatformSettings::stored($name));
    }

    private function save(array $data): TestResponse
    {
        return $this->actingAs($this->admin())->panelPost(route('admin.settings.update'), $data);
    }

    public function test_settings_admin_only(): void
    {
        $this->assertAdminOnly('get', route('admin.settings.index'));
        $this->assertAdminOnly('post', route('admin.settings.update'), ['general_setting' => 1, 'site_title' => 'Hijack']);
        $this->assertAdminOnly('get', route('admin.dashboard'));
        $this->assertAdminOnly('get', route('admin.dashboard.chart-data'));
        $this->assertNotSame('Hijack', $this->option('site_title'));
    }

    public function test_settings_page(): void
    {
        $this->actingAs($this->admin())->get(route('admin.settings.index'))->assertOk()
            ->assertViewIs('admin.settings.index')->assertViewHas('credentials');
    }

    public function test_general_and_theme_sections(): void
    {
        $this->assertPanelFails($this->save(['general_setting' => 1, 'site_title' => '']));
        $this->assertPanelFails($this->save(['general_setting' => 1, 'site_title' => 'S', 'privacy_link' => 'not a url']));
        $this->assertPanelFails($this->save(['general_setting' => 1, 'site_title' => 'S', 'whatsapp_handoff_mode' => 'fax']));
        $this->assertPanelFails($this->save(['general_setting' => 1, 'site_title' => 'S', 'storefront_order_pipeline_stores' => '1;DROP']));

        $this->assertPanelOk($this->save([
            'general_setting' => 1, 'site_title' => 'My Menus', 'privacy_link' => 'https://example.com/privacy',
            'storefront_order_pipeline' => 'unified', 'storefront_order_pipeline_stores' => '1, 2', 'juwri_debug' => 1,
        ]));
        $this->assertSame('My Menus', $this->option('site_title'));
        $this->assertSame('https://example.com/privacy', $this->option('privacy_link'));
        $this->assertSame('unified', $this->option('storefront_order_pipeline'));
        $this->assertNotSame('1', (string) $this->option('juwri_debug'), 'debug is never writable from the web');
        $this->assertNull($this->option('general_setting'));

        $this->assertPanelFails($this->save(['theme_setting' => 1, 'theme_color' => 'red']));
        $this->assertPanelFails($this->save(['theme_setting' => 1, 'theme_color' => '#112233', 'contact_email' => 'nope']));
        $this->assertPanelOk($this->save(['theme_setting' => 1, 'theme_color' => '#112233', 'contact_email' => 'hello@example.com']));
        $this->assertSame(['#112233', 'hello@example.com'], [$this->option('theme_color'), $this->option('contact_email')]);
    }

    public function test_international_smtp_and_wallet_sections(): void
    {
        $this->assertPanelFails($this->save(['international_setting' => 1, 'currency_code' => 'usd', 'currency_sign' => '$', 'currency_pos' => 1, 'timezone' => 'UTC']));
        $this->assertPanelFails($this->save(['international_setting' => 1, 'currency_code' => 'USD', 'currency_sign' => '$', 'currency_pos' => 3, 'timezone' => 'UTC']));
        $this->assertPanelFails($this->save(['international_setting' => 1, 'currency_code' => 'USD', 'currency_sign' => '$', 'currency_pos' => 1, 'timezone' => 'Mars/Base']));
        $this->assertPanelOk($this->save(['international_setting' => 1, 'currency_code' => 'SAR', 'currency_sign' => "\u{20C0}", 'currency_pos' => 0, 'timezone' => 'Asia/Riyadh']));
        $this->assertSame(['SAR', "\u{20C1}", '0', 'Asia/Riyadh'], [$this->option('currency_code'), $this->option('currency_sign'), (string) $this->option('currency_pos'), $this->option('timezone')]);

        $smtp = ['smtp_settings' => 1, 'admin_email' => 'admin@example.com', 'smtp_from_email' => 'noreply@example.com', 'smtp_from_name' => 'Menus', 'smtp_mailer' => 'smtp', 'smtp_secure' => 'tls', 'smtp_host' => 'mail.example.com', 'smtp_password' => 'test-pass-1'];
        $this->assertPanelFails($this->save(['smtp_mailer' => 'pigeon'] + $smtp));
        $this->assertPanelFails($this->save(['smtp_secure' => 'none'] + $smtp));
        $this->assertPanelOk($this->save($smtp));
        $this->assertSame(['mail.example.com', 'test-pass-1'], [$this->option('smtp_host'), $this->option('smtp_password')]);

        Mail::fake();
        // P6-176: sendTestMail short-circuits when config('mail.default') is 'log'
        // (the Log / Array mailers accept everything and would silently say
        // "Sent Successfully" while sending nothing). Pin the test to 'array'.
        if (config('mail.default') === 'log') {
            config(['mail.default' => 'array']);
        }
        $this->assertPanelFails($this->save(['smtp_test' => 1, 'email' => 'x']));
        $this->assertPanelOk($this->save(['smtp_test' => 1, 'email' => 'to@example.com']));

        $wallet = ['wallet_topup_settings' => 1, 'wallet_topup_enabled' => 1, 'wallet_topup_min' => 5, 'wallet_topup_max' => 500, 'wallet_topup_presets' => '10, 25,50'];
        $this->assertPanelFails($this->save(['wallet_topup_max' => 1] + $wallet));
        $this->assertPanelFails($this->save(['wallet_topup_presets' => '10;25'] + $wallet));
        $this->assertPanelFails($this->save(['wallet_topup_enabled' => 2] + $wallet));
        $this->assertPanelOk($this->save($wallet));
        $this->assertSame(['1', '5', '500', '10, 25,50'], [(string) $this->option('wallet_topup_enabled'), (string) $this->option('wallet_topup_min'), (string) $this->option('wallet_topup_max'), $this->option('wallet_topup_presets')]);
    }

    public function test_free_form_sections(): void
    {
        $this->assertPanelOk($this->save(['billing_settings' => 1, 'invoice_admin_name' => 'Menus Ltd', 'invoice_nr_prefix' => 'INV-']));
        $this->assertSame(['Menus Ltd', 'INV-'], [$this->option('invoice_admin_name'), $this->option('invoice_nr_prefix')]);

        $this->assertPanelOk($this->save(['blog_settings' => 1, 'blog_page_limit' => 9, 'blog_comment_approval' => 1]));
        $this->assertSame('9', (string) $this->option('blog_page_limit'));

        $this->assertPanelOk($this->save(['testimonial_settings' => 1, 'testimonial_enable' => 1]));
        $this->assertSame('1', (string) $this->option('testimonial_enable'));

        $this->assertPanelOk($this->save(['custom_code_setting' => 1, 'external_code' => '<script>track()</script>']));
        $this->assertSame('<script>track()</script>', $this->option('external_code'));

        $this->assertPanelOk($this->save(['social_logins_settings' => 1, 'facebook_app_id' => 'fb-id', 'facebook_app_secret' => 'fb-secret', 'google_app_id' => 'g-id', 'google_app_secret' => 'g-secret']));
        $this->assertSame('fb-id', $this->option('facebook_app_id'));
        $env = (string) file_get_contents($this->envDir.'/.env');
        $this->assertStringContainsString('FACEBOOK_CLIENT_ID="fb-id"', $env);
        $this->assertStringContainsString('GOOGLE_CLIENT_SECRET="g-secret"', $env);

        $this->assertPanelOk($this->save(['addons_settings' => 1, 'recaptcha_public_key' => 'site-key', 'recaptcha_private_key' => 'secret-key', 'tawk_to' => ['chat_link' => 'https://tawk.example/x']]));
        $this->assertSame('site-key', $this->option('recaptcha_public_key'));
        $this->assertSame(['chat_link' => 'https://tawk.example/x'], json_decode((string) $this->option('tawk_to'), true));
        $this->assertStringContainsString('NOCAPTCHA_SITEKEY="site-key"', (string) file_get_contents($this->envDir.'/.env'));
    }

    public function test_logo_section_keeps_existing_files(): void
    {
        config(['settings.site_logo' => 'logo-old.png', 'settings.site_favicon' => 'fav-old.png']);
        $this->assertPanelFails($this->save(['logo_setting' => 1, 'site_logo' => UploadedFile::fake()->create('logo.pdf', 5, 'application/pdf')]));
        $this->assertPanelFails($this->save(['logo_setting' => 1, 'brand_tile_style' => 'neon-disco']));

        $this->assertPanelOk($this->save(['logo_setting' => 1, 'brand_tile_style' => 'off']));
        $this->assertSame(['logo-old.png', 'fav-old.png', 'off'], [$this->option('site_logo'), $this->option('site_favicon'), $this->option('brand_tile_style')]);
    }

    public function test_clear_cache(): void
    {
        $this->assertPanelOk($this->save(['clear_cache' => 1]));
    }

    // ── Dashboard ──────────────────────────────────────────────────────────

    public function test_dashboard(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        $admin = $this->admin();
        $owner = $this->plainUser();
        Store::factory()->create(['user_id' => $owner->id, 'title' => 'Corner Cafe']);
        Transaction::forceCreate(['user_id' => $owner->id, 'status' => 'success', 'amount' => 10, 'product_name' => 'P', 'product_id' => 0]);

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertViewIs('admin.dashboard.index')
            ->assertViewHas('recentTransactions', fn ($t) => $t->count() === 1)
            ->assertViewHas('recentRestaurants', fn ($r) => $r->pluck('title')->contains('Corner Cafe'))
            ->assertViewHas('charts')->assertViewHas('topUsageTenants')->assertViewHas('merchantRevenue')->assertViewHas('analyticsTeaser');

        $this->actingAs($admin)->getJson(route('admin.dashboard.chart-data', ['days' => 7]))->assertOk();
    }

    // ── Rewrite (WI-08f) ───────────────────────────────────────────────────

    public function test_rewrite_sections_cannot_write_other_screens_options(): void
    {
        PlatformSettings::set('stripe_secret_key', 'sk_test_kept');
        PlatformSettings::set('active_plugins', '[]');
        $freePlan = $this->option('free_membership_plan');

        $this->assertPanelOk($this->save([
            'blog_settings' => 1, 'blog_page_limit' => 6,
            'stripe_secret_key' => 'sk_test_hijack', 'active_plugins' => '["evil"]',
            'free_membership_plan' => '{}', 'email_sub_signup_confirm' => 'x', 'bad key!' => 'x',
        ]));
        $this->assertSame('6', (string) $this->option('blog_page_limit'));
        $this->assertSame('sk_test_kept', $this->option('stripe_secret_key'));
        $this->assertSame('[]', $this->option('active_plugins'));
        $this->assertSame($freePlan, $this->option('free_membership_plan'));
        $this->assertNull($this->option('bad key!'));
        $this->assertNull($this->option('blog_settings'), 'section markers are not stored');

        $this->assertPanelFails($this->save(['nothing_known' => 1]));
    }

    public function test_rewrite_env_values_stay_on_one_line(): void
    {
        $this->assertPanelOk($this->save(['addons_settings' => 1, 'recaptcha_public_key' => "k\nAPP_DEBUG=true", 'recaptcha_private_key' => 's']));
        $env = (string) file_get_contents($this->envDir.'/.env');
        $this->assertStringNotContainsString("\nAPP_DEBUG=true", $env);
    }

    public function test_rewrite_gates(): void
    {
        foreach ([PanelAbilities::SETTINGS, PanelAbilities::DASHBOARD] as $ability) {
            $this->assertTrue($this->admin()->can($ability));
            $this->assertFalse($this->plainUser()->can($ability));
        }
    }
}

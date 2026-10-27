<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Juwri\Core\Providers\SettingsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Settings\PlatformSettings;
use Tests\TestCase;

/**
 * H3: MAIL_* / DEFAULT_LANGUAGE / APP_TIMEZONE moved from runtime .env writes to the
 * options store; SettingsServiceProvider applies them onto Laravel's runtime config.
 */
class SettingsEnvMigrationTest extends TestCase
{
    use RefreshDatabase;

    private string $origTz;

    /** @var array{0: string|false, 1: mixed, 2: mixed} APP_INSTALLED in getenv / $_ENV / $_SERVER before the test */
    private array $origInstalled;

    protected function setUp(): void
    {
        parent::setUp();
        $this->origTz = date_default_timezone_get();
        $this->origInstalled = [getenv('APP_INSTALLED'), $_ENV['APP_INSTALLED'] ?? null, $_SERVER['APP_INSTALLED'] ?? null];
    }

    protected function tearDown(): void
    {
        // These tests mutate global PHP state (default timezone); restore it so a later
        // test that reads the clock isn't affected by ordering.
        date_default_timezone_set($this->origTz);
        /* P1-08b follow-up: bootWithOptions() sets APP_INSTALLED=1 process-wide. Left behind, every later test's app
           boot counted as installed; on a persistent MySQL/PG test DB that loads the seeded options
           (include_language_code=1 → hreflang for the seeded 'fr' → "Locale 'fr' is not in the list of supported
           locales" 500s, timezone Asia/Kolkata) — the serial-run failure class. Restore what phpunit.xml set. */
        [$env, $envArr, $server] = $this->origInstalled;
        $env === false ? putenv('APP_INSTALLED') : putenv('APP_INSTALLED='.$env);
        if ($envArr === null) {
            unset($_ENV['APP_INSTALLED']);
        } else {
            $_ENV['APP_INSTALLED'] = $envArr;
        }
        if ($server === null) {
            unset($_SERVER['APP_INSTALLED']);
        } else {
            $_SERVER['APP_INSTALLED'] = $server;
        }
        parent::tearDown();
    }

    private function bootWithOptions(array $options): void
    {
        foreach ($options as $name => $value) {
            PlatformSettings::set($name, $value);
        }
        // site_title is dereferenced unconditionally by the provider.
        PlatformSettings::set('site_title', 'Test Site');

        putenv('APP_INSTALLED=1');
        $_ENV['APP_INSTALLED'] = '1';
        // S1: the settings/installer gate now reads config('app.installed')
        // (frozen at boot so it survives config:cache) via app_is_installed(),
        // not a runtime env('APP_INSTALLED') read — signal "installed" that way.
        config(['app.installed' => true]);

        new SettingsServiceProvider($this->app)->boot(request());
    }

    public function test_mail_settings_apply_to_runtime_config(): void
    {
        $this->bootWithOptions([
            'smtp_mailer'     => 'smtp',
            'smtp_host'       => 'mail.example.com',
            'smtp_port'       => '2525',
            'smtp_username'   => 'postbox',
            'smtp_password'   => 'sekret',
            'smtp_secure'     => 'ssl',
            'smtp_from_email' => 'hello@example.com',
            'smtp_from_name'  => 'Example Diner',
        ]);

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('mail.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame('2525', config('mail.mailers.smtp.port'));
        $this->assertSame('postbox', config('mail.mailers.smtp.username'));
        $this->assertSame('sekret', config('mail.mailers.smtp.password'));
        $this->assertSame('ssl', config('mail.mailers.smtp.encryption'));
        $this->assertSame('hello@example.com', config('mail.from.address'));
        $this->assertSame('Example Diner', config('mail.from.name'));
    }

    public function test_locale_and_timezone_apply_to_runtime_config(): void
    {
        $this->bootWithOptions(['lang' => 'ar', 'timezone' => 'Asia/Riyadh']);

        $this->assertSame('ar', config('app.locale'));
        $this->assertSame('ar', app()->getLocale());
        $this->assertSame('Asia/Riyadh', config('app.timezone'));
        $this->assertSame('Asia/Riyadh', date_default_timezone_get());
    }

    /** A missing/empty option leaves the existing (.env-backed) config untouched. */
    public function test_absent_options_do_not_override(): void
    {
        config(['mail.mailers.smtp.host' => 'env-default.example']);
        $this->bootWithOptions(['smtp_host' => '']); // empty → skipped

        $this->assertSame('env-default.example', config('mail.mailers.smtp.host'));
    }

    /** P6-65b: the seeded smtp_mailer="log" must not override MAIL_MAILER in production; elsewhere it still applies. */
    public function test_seeded_log_mailer_does_not_override_env_mailer_in_production(): void
    {
        config(['mail.default' => 'smtp']);
        $this->app['env'] = 'production';
        $this->bootWithOptions(['smtp_mailer' => 'log']);
        $this->assertSame('smtp', config('mail.default'));

        config(['mail.default' => 'smtp']);
        $this->app['env'] = 'local';
        $this->bootWithOptions(['smtp_mailer' => 'log']);
        $this->assertSame('log', config('mail.default'));
    }
}

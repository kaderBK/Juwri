<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Providers;

use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Juwri\Core\Legal\LegalSettings;
use Juwri\Core\Octane\BeginRequest;
use Juwri\Core\Octane\RequestAware;
use Juwri\Core\Settings\PlatformSettings;

/**
 * Loads the platform settings (platform_settings table, P1-07) into config on every boot:
 * `settings.<option>` for each row (JSON values decoded), `app.name` from
 * site_title, the mail/locale/timezone options that used to be mirrored into
 * .env (H3) and the marketplace brand names (T5-6).
 *
 * Skipped before installation, and while `config:cache` / `optimize` run:
 * decrypted credentials must never be written to bootstrap/cache/config.php
 * (they are read from the database on every boot anyway).
 */
class SettingsServiceProvider extends ServiceProvider implements RequestAware
{
    /**
     * Also called by {@see BeginRequest} at the start of every Octane request: a long-lived worker
     * boots once, so the platform settings must be projected into the request's config again or an admin's change would
     * only show after a worker restart.
     */
    public function boot(): void
    {
        $this->forRequest();
    }

    public function forRequest(): void
    {
        if ($this->isCachingConfig() || ! app_is_installed()) {
            return;
        }

        try {
            $this->applyOptions($this->options());
        } catch (\Exception $e) {
            // Options table unreachable (fresh deploy, maintenance): boot on file config.
        }
    }

    /**
     * Every platform setting in its legacy string form, credentials decrypted. Cached across
     * requests by PlatformSettings (encrypted); every write there forgets the cache.
     */
    /** @return Collection<string, mixed> */
    private function options(): Collection
    {
        return collect(PlatformSettings::legacyMap());
    }

    /** @param  Collection<string, mixed>  $options */
    private function applyOptions(Collection $options): void
    {
        $values = [];
        foreach ($options as $name => $raw) {
            $values['settings.'.$name] = Str::isJson($raw) ? json_decode($raw) : $raw;
        }
        if (isset($options['site_title'])) {
            $values['app.name'] = $options['site_title'];
        }
        config($values);

        // J51b: the legal-page owner facts (legal_fill_* / legal_verify_* / legal_unused_*) override the
        // LEGAL_* env values, projected into config on every request/Octane request.
        LegalSettings::applyToConfig();

        $this->applyEnvBackedSettings($options);

        // T5-6 (fusion, D6): a saved marketplace brand overrides the env default.
        foreach (['mk_brand_name' => 'marketplace.name', 'mk_brand_name_ar' => 'marketplace.name_ar'] as $option => $configKey) {
            if (! empty($options[$option])) {
                config([$configKey => $options[$option]]);
            }
        }
    }

    private function isCachingConfig(): bool
    {
        if (! $this->app->runningInConsole()) {
            return false;
        }

        return in_array($_SERVER['argv'][1] ?? '', ['config:cache', 'optimize'], true);
    }

    /**
     * H3: map the DB-stored settings (previously mirrored into .env via set_env)
     * onto Laravel's runtime config. Each override is applied only when a non-empty
     * value is present, so an install that still relies on .env keeps working.
     */
    private function applyEnvBackedSettings($settings): void
    {
        $get = fn (string $k) => isset($settings[$k]) && $settings[$k] !== '' ? $settings[$k] : null;

        // Mail transport (smtp) — the SettingsController stores these option names.
        $mailMap = [
            'smtp_mailer' => 'mail.default',
            'smtp_host' => 'mail.mailers.smtp.host',
            'smtp_port' => 'mail.mailers.smtp.port',
            'smtp_username' => 'mail.mailers.smtp.username',
            'smtp_password' => 'mail.mailers.smtp.password',
            'smtp_secure' => 'mail.mailers.smtp.encryption',
            'smtp_from_email' => 'mail.from.address',
            'smtp_from_name' => 'mail.from.name',
        ];
        foreach ($mailMap as $option => $configKey) {
            // P6-65b: the platform_settings seed stores smtp_mailer="log". In production that default must not silently
            // override MAIL_MAILER (mail would be written to the log and never sent; ops:preflight mail_mailer flags it).
            if ($option === 'smtp_mailer' && $get($option) === 'log' && $this->app->environment('production')) {
                continue;
            }
            if (($v = $get($option)) !== null) {
                config([$configKey => $v]);
            }
        }

        // Laravel 12 ignores mail.mailers.smtp.encryption: only the scheme decides (smtps = implicit TLS,
        // otherwise port 465 is the only trigger). Make the owner's "SSL" choice real on any port.
        if (($enc = $get('smtp_secure')) !== null) {
            config(['mail.mailers.smtp.scheme' => $enc === 'ssl' ? 'smtps' : null]);
        }

        // Locale + timezone.
        if (($lang = $get('lang')) !== null) {
            config(['app.locale' => $lang]);
            app()->setLocale($lang); // the CURRENT application: in an Octane worker that is the request's clone, not the base
        }
        if (($tz = $get('timezone')) !== null) {
            config(['app.timezone' => $tz]);
            @date_default_timezone_set($tz);
        }
    }
}

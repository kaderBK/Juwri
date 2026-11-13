<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers\Admin;

use App\Support\Mail\SmtpFailureReason;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Juwri\Core\Http\Controllers\Admin\Concerns\AnswersAdminPanel;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Http\Requests\Admin\SettingsRequest;
use Juwri\Core\Models\Credential;
use Juwri\Core\Policies\Admin\PanelAbilities;
use Juwri\Core\Settings\PlatformSettings;

/**
 * Admin › Settings: platform options, one form per section, plus the
 * platform-default integration credentials (AI, SMS, storage).
 */
class SettingsController extends Controller
{
    use AnswersAdminPanel;

    /** Uploadable site images: field => resize spec (null = keep size). */
    private const IMAGES = [
        'site_favicon' => null,
        'site_logo' => null,
        'site_logo_footer' => null,
        'site_admin_logo' => null,
        'social_share_image' => '600x315',
        'brand_tile_image' => null,
    ];

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            Gate::authorize(PanelAbilities::SETTINGS);

            return $next($request);
        });
    }

    public function index(): View
    {
        return view('admin.settings.index', ['credentials' => $this->platformCredentials()]);
    }

    /** Save one settings section (or run its action) and answer the panel. */
    public function update(SettingsRequest $request): JsonResponse
    {
        $section = $request->section();
        if ($section === null) {
            return $this->panelFailed(___('Error in updating, please try again.'));
        }

        if ($section === 'smtp_test') {
            return $this->sendTestMail($request, (string) $request->validated('email'));
        }

        if ($section === 'clear_cache') {
            Artisan::call('optimize:clear');
            remove_file(storage_path('logs/laravel.log'));

            return $this->panelDone(___('Cache Cleared Successfully'));
        }

        $options = $request->options();

        if ($section === 'logo_setting') {
            $options = $this->withImages($request, $options);
        }

        if ($section === 'international_setting' && isset($options['currency_sign'])) {
            // S4: store the SAR riyal sign with the right codepoint (U+20C1).
            $options['currency_sign'] = normalize_currency_sign((string) $options['currency_sign'], (string) ($options['currency_code'] ?? ''));
        }

        foreach ($options as $name => $value) {
            PlatformSettings::set($name, $value);
        }

        // Social sign-in and reCAPTCHA keys are also read from .env by their packages.
        if ($section === 'social_logins_settings') {
            $this->mirrorToEnv($options, [
                'FACEBOOK_CLIENT_ID' => 'facebook_app_id', 'FACEBOOK_CLIENT_SECRET' => 'facebook_app_secret',
                'GOOGLE_CLIENT_ID' => 'google_app_id', 'GOOGLE_CLIENT_SECRET' => 'google_app_secret',
            ]);
        }
        if ($section === 'addons_settings') {
            $this->mirrorToEnv($options, ['NOCAPTCHA_SITEKEY' => 'recaptcha_public_key', 'NOCAPTCHA_SECRET' => 'recaptcha_private_key']);
        }

        return $this->panelDone(___('Updated Successfully'));
    }

    /**
     * New uploads replace the stored file; a field left empty keeps the
     * current image. Uploading a tile background also switches it on.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function withImages(SettingsRequest $request, array $options): array
    {
        foreach (self::IMAGES as $field => $size) {
            $current = config('settings.'.$field);
            $upload = $request->file($field);
            $options[$field] = $upload !== null
                ? image_upload($upload, 'storage/logo/', $size, null, $current)
                : $current;

            if ($field === 'brand_tile_image' && $upload !== null) {
                $options['brand_tile_style'] = 'custom';
            }
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, string>  $map  env key => option name
     */
    private function mirrorToEnv(array $options, array $map): void
    {
        foreach ($map as $env => $option) {
            if (array_key_exists($option, $options)) {
                // One line per key: line breaks never reach the env file.
                set_env($env, str_replace(["\r", "\n"], '', (string) $options[$option]));
            }
        }
    }

    private function sendTestMail(Request $request, string $to): JsonResponse
    {
        // P6-176: rate-limit the "Send test e-mail" action inside the method so a
        // shared throttle key works with the existing Gate-middleware admin check.
        // Six attempts per five minutes, per user (or per IP for unauthenticated
        // probes — the constructor's Gate still blocks them at HTTP 403, this is
        // a second line of defence against accidental spam from a real admin).
        $rlKey = 'smtp-test:'.($request->user()->id ?? $request->ip());
        if (RateLimiter::tooManyAttempts($rlKey, 6)) {
            return $this->panelFailed(___('Too many test e-mails. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($rlKey)]));
        }
        RateLimiter::hit($rlKey, 300);

        // The Log / Array mailers accept anything, so "success" tells the owner
        // nothing. Force a useful panel answer instead of sending and silently
        // pretending it worked.
        if (config('mail.default') === 'log') {
            return $this->panelFailed(___('The mailer is set to Log, so nothing is sent. Choose SMTP, save, then try again.'));
        }

        try {
            Mail::raw('Hi, This is a test mail to '.$to, fn ($message) => $message->to($to)->subject('Test e-mail from '.config('settings.site_title')));
        } catch (\Throwable $e) {
            report($e);
            $reason = SmtpFailureReason::classify(
                $e,
                (string) config('mail.mailers.smtp.host'),
                (int) config('mail.mailers.smtp.port'),
            );
            Log::warning('smtp test failed', [
                'code' => $reason['code'],
                'host' => (string) config('mail.mailers.smtp.host'),
                'port' => (int) config('mail.mailers.smtp.port'),
            ]);

            return $this->panelFailed($reason['message']);
        }

        return $this->panelDone(___('Sent Successfully'));
    }

    /** @return Collection<string, Credential> */
    private function platformCredentials()
    {
        return Credential::query()->whereNull('store_id')->get()
            ->keyBy(fn (Credential $item) => $item->feature.'_'.$item->provider);
    }

    /**
     * Show admin platform default integrations / API keys
     */
    public function integrations(Request $request): View
    {
        return view('admin.settings.integrations', ['credentials' => $this->platformCredentials()]);
    }

    /**
     * Save admin platform default integrations / API keys
     */
    public function updateIntegrations(Request $request): RedirectResponse
    {
        $feature = $request->input('feature');
        $provider = $request->input('provider');
        $secrets = $request->input('secrets', []);
        $active = $request->boolean('active', true);

        // Strip out masked keys
        $existing = Credential::whereNull('store_id')
            ->where('feature', $feature)
            ->where('provider', $provider)
            ->first();

        if ($existing) {
            $existingSecrets = $existing->secrets;
            foreach ($secrets as $key => $value) {
                if (str_contains($value, '••••')) {
                    $secrets[$key] = $existingSecrets[$key] ?? '';
                }
            }
        }

        Credential::updateOrCreate(
            [
                'store_id' => null,
                'feature' => $feature,
                'provider' => $provider,
            ],
            [
                'secrets' => $secrets,
                'active' => $active,
            ]
        );

        app_alert_success(___('Platform integration updated successfully'));

        return back();
    }

    /**
     * Test platform default integration connection
     */
    public function testIntegration(Request $request): JsonResponse
    {
        $feature = $request->input('feature');
        $provider = $request->input('provider');
        $secrets = $request->input('secrets', []);

        // Check for masked values and populate from DB if needed
        $existing = Credential::whereNull('store_id')
            ->where('feature', $feature)
            ->where('provider', $provider)
            ->first();

        if ($existing) {
            $existingSecrets = $existing->secrets;
            foreach ($secrets as $key => $value) {
                if (str_contains($value, '••••')) {
                    $secrets[$key] = $existingSecrets[$key] ?? '';
                }
            }
        }

        // Run the test depending on provider
        $success = false;
        $message = ___('Connection failed');

        try {
            if ($feature === 'ai') {
                if ($provider === 'openai') {
                    $apiKey = $secrets['api_key'] ?? null;
                    if ($apiKey) {
                        $client = Http::withToken($apiKey)
                            ->post('https://api.openai.com/v1/chat/completions', [
                                'model' => 'gpt-3.5-turbo',
                                'messages' => [['role' => 'user', 'content' => 'ping']],
                                'max_tokens' => 5,
                            ]);
                        if ($client->successful()) {
                            $success = true;
                            $message = ___('OpenAI Connection Successful!');
                        } else {
                            $message = $client->json()['error']['message'] ?? ___('OpenAI Connection Failed');
                        }
                    }
                } elseif ($provider === 'anthropic') {
                    $apiKey = $secrets['api_key'] ?? null;
                    if ($apiKey) {
                        $client = Http::withHeaders([
                            'x-api-key' => $apiKey,
                            'anthropic-version' => '2023-06-01',
                            'content-type' => 'application/json',
                        ])->post('https://api.anthropic.com/v1/messages', [
                            'model' => 'claude-3-haiku-20240307',
                            'messages' => [['role' => 'user', 'content' => 'ping']],
                            'max_tokens' => 5,
                        ]);
                        if ($client->successful()) {
                            $success = true;
                            $message = ___('Anthropic Connection Successful!');
                        } else {
                            $message = $client->json()['error']['message'] ?? ___('Anthropic Connection Failed');
                        }
                    }
                } elseif ($provider === 'gemini') {
                    $apiKey = $secrets['api_key'] ?? null;
                    if ($apiKey) {
                        $client = Http::post("https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key={$apiKey}", [
                            'contents' => [['parts' => [['text' => 'ping']]]],
                        ]);
                        if ($client->successful()) {
                            $success = true;
                            $message = ___('Gemini Connection Successful!');
                        } else {
                            $message = $client->json()['error']['message'] ?? ___('Gemini Connection Failed');
                        }
                    }
                }
            } elseif ($feature === 'sms') {
                if ($provider === 'twilio') {
                    $sid = $secrets['sid'] ?? null;
                    $token = $secrets['auth_token'] ?? null;
                    if ($sid && $token) {
                        $client = Http::withBasicAuth($sid, $token)
                            ->get("https://api.twilio.com/2010-04-01/Accounts/{$sid}.json");
                        if ($client->successful()) {
                            $success = true;
                            $message = ___('Twilio Connection Successful!');
                        } else {
                            $message = $client->json()['message'] ?? ___('Twilio Connection Failed');
                        }
                    }
                }
            } elseif ($feature === 'storage') {
                if ($provider === 's3') {
                    $key = $secrets['key'] ?? null;
                    $secret = $secrets['secret'] ?? null;
                    $region = $secrets['region'] ?? null;
                    $bucket = $secrets['bucket'] ?? null;
                    if ($key && $secret && $region && $bucket) {
                        $success = true;
                        $message = ___('S3 Configuration Validated');
                    }
                }
            }
        } catch (\Exception $e) {
            $message = $e->getMessage();
        }

        return response()->json([
            'success' => $success,
            'message' => $message,
        ]);
    }
}

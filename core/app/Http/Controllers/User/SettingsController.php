<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers\User;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Country;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Juwri\Modules\Ai\Contracts\AiProviderChain;
use Juwri\Modules\Ai\Models\AiUsage;
use Juwri\Modules\Auth\Contracts\EmailChangeUndos;
use Juwri\Modules\Auth\Events\CredentialsChanged;
use Juwri\Modules\Auth\Models\EmailChangeUndo;
use Juwri\Modules\Auth\Services\Social\AppleClientSecret;

/** Owner account screen (profile, billing details) and the integrations / API keys screen. */
class SettingsController extends Controller
{
    /** Fields of the billing-details form stored as user options. */
    private const BILLING_FIELDS = [
        'billing_details_type', 'billing_name', 'billing_address', 'billing_city',
        'billing_state', 'billing_zipcode', 'billing_country', 'billing_tax_id',
        // The two e-mail preferences sit on the same form (read by
        // SendWeeklyDigest / SendAnomalyAlerts).
        'weekly_digest_email', 'anomaly_alerts_email',
    ];

    public function __construct()
    {
        $this->activeTheme = active_theme();
    }

    public function index(Request $request): View
    {
        $owner = $request->user();

        return view('owner.settings.account', ['user' => $owner, 'user_options' => user_options($owner->id)]);
    }

    /**
     * Save username, email, avatar, password and message language.
     */
    public function editProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Changing the account email or password is an account-takeover-grade action:
        // a stolen/borrowed owner session could silently move the login identity to an
        // attacker address (only the *new* address gets the verify link) and then drive
        // forgot-password for persistent takeover, or lock the owner out with a new
        // password. Re-authenticate with the current password for exactly those two
        // changes — the same bar the app already sets for minting an API token and
        // disabling 2FA. Cosmetic edits (username/avatar/locale) stay frictionless.
        $emailChanging = (string) $request->input('email') !== (string) $user->email;
        $passwordChanging = $request->filled('new_password');
        $needsReauth = $emailChanging || $passwordChanging;

        $validator = Validator::make($request->all(), [
            'avatar' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
            'username' => ['required', 'alpha_dash', 'min:2', 'max:16', 'unique:users,username,'.$user->id],
            'email' => ['required', 'string', 'email', 'max:100', 'unique:users,email,'.$user->id, app(EmailChangeUndos::class)->reservedEmailRule((int) $user->id)],
            'new_password' => ['nullable', 'string', Password::defaults(), 'confirmed'],
            // I2: transactional-messaging language.
            'preferred_locale' => ['nullable', 'string', 'max:10', 'exists:languages,code'],
        ] + ($needsReauth ? ['current_password' => ['required', 'string']] : []));

        $validator->after(function ($v) use ($request, $user, $needsReauth) {
            if ($needsReauth && ! Hash::check((string) $request->input('current_password'), (string) $user->password)) {
                $v->errors()->add('current_password', ___('Your current password is incorrect.'));
            }
        });
        $validator->validate();

        $previousEmail = $user->email;

        $user->forceFill([
            'username' => $request->input('username'),
            'email' => $request->input('email'),
            'image' => $this->avatarFor($user, $request),
            // I2: blank means "use the platform default".
            'preferred_locale' => $request->filled('preferred_locale') ? $request->input('preferred_locale') : null,
        ]);

        if ($passwordChanging) {
            $user->password = Hash::make($request->input('new_password'));
            Auth::logoutOtherDevices($request->input('new_password'));
        }

        $user->save();

        if ($passwordChanging) {
            // P6-75: sign out owner-app devices too; inside the app the user may keep this one.
            $keep = $request->boolean('keep_this_device') ? ((int) $request->session()->get('owner_app_token_id')) ?: null : null;
            event(new CredentialsChanged($user, CredentialsChanged::PASSWORD_CHANGED, $keep));
        }

        if ($emailChanging) {
            $this->confirmNewEmail($user, $previousEmail);
        }

        app_alert_success(___('User details updated successfully'));

        return back();
    }

    /** Store the billing-detail fields as user options and copy the country onto the account. */
    public function editBilling(Request $request): RedirectResponse
    {
        $request->validateWithBag('billing', [
            'billing_details_type' => ['required'],
            'billing_name' => ['required'],
            'billing_address' => ['required'],
            'billing_city' => ['required'],
            'billing_state' => ['required'],
            'billing_zipcode' => ['required'],
            'billing_country' => ['required', 'string', 'size:2', 'alpha:ascii', 'exists:countries,code'],
        ]);

        $owner = $request->user();
        // Only the billing fields: any other posted key must never become a user option
        // (e.g. package_trial_done, wallet_auto_renew, default_store_id).
        foreach ($request->only(self::BILLING_FIELDS) as $field => $value) {
            UserOption::updateUserOption($owner->id, $field, $value);
        }

        $code = strtoupper((string) $request->input('billing_country'));
        $owner->update([
            'country_code' => $code,
            'country' => Country::query()->where('code', $code)->value('name'),
        ]);

        app_alert_success(___('Updated Successfully'));

        return back();
    }

    /** The uploaded avatar's stored file name (replacing the previous upload), or the current image. */
    private function avatarFor(User $user, Request $request): mixed
    {
        if (! $request->has('avatar')) {
            return $user->image;
        }

        $previous = $user->image === 'default_user.png' ? null : $user->image;

        return image_upload($request->file('avatar'), 'storage/profile/', '150x150', null, $previous);
    }

    /**
     * A changed email must be verified again (the link goes to the new address),
     * and the previous address is told, so a silent takeover is visible to the
     * real owner.
     */
    private function confirmNewEmail(User $user, ?string $previousEmail): void
    {
        $user->forceFill(['email_verified_at' => null])->save();
        $user->sendEmailVerificationNotification();

        try {
            // BL-036 A1a: the old address also gets a single-use 7-day "This wasn't me — undo" link.
            $undos = app(EmailChangeUndos::class);
            $undoUrl = $undos->tryIssue($user, (string) $previousEmail, (string) $user->email, EmailChangeUndo::SOURCE_SELF, null, (int) $user->id);
            Mail::raw(
                $undos->selfChangeNotice($undoUrl),
                fn ($message) => $message->to($previousEmail)->subject(___('Your account email was changed'))
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Show integrations / API keys
     */
    public function integrations(Request $request)
    {
        $user = $request->user();
        $stores = $user->stores; // Retrieve all restaurants owned by the user

        // Get selected restaurant id or default to the first one
        $selectedRestaurantId = $request->get('store_id') ?: ($stores->first()->id ?? null);

        // Load active or existing credentials for the selected restaurant
        $credentials = [];
        if ($selectedRestaurantId) {
            // Verify ownership
            $store = $stores->where('id', $selectedRestaurantId)->first();
            if (! $store) {
                abort(403);
            }
            $credentials = Credential::where('store_id', $selectedRestaurantId)->get()->keyBy(function ($item) {
                return $item->feature.'_'.$item->provider;
            });
        }

        // features-4 A6: read-only AI transparency — the resolved fallback chain
        // (merchant BYOK → reseller → platform → env) plus the last model that
        // actually ran for this owner (from ai_usage, keyed by user_id).
        $factory = app(AiProviderChain::class);
        $aiChain = $factory->describeChain($selectedRestaurantId ?: null);

        $aiLastUsed = AiUsage::where('user_id', $user->id)
            ->latest('id')
            ->first();

        return view('owner.integrations.index', [
            'user' => $user,
            'stores' => $stores,
            'selectedRestaurantId' => $selectedRestaurantId,
            'credentials' => $credentials,
            'aiChain' => $aiChain,
            'aiLastUsed' => $aiLastUsed,
        ]);
    }

    /**
     * Save integrations / API keys
     */
    public function updateIntegrations(Request $request)
    {
        $user = $request->user();
        $storeId = $request->input('store_id');

        // Verify ownership
        $store = $user->stores()->where('id', $storeId)->firstOrFail();

        $feature = $request->input('feature');
        $provider = $request->input('provider');
        $secrets = $request->input('secrets', []);
        $active = $request->boolean('active', true);

        // Strip out masked keys (so if they send back ••••, we do not overwrite the existing value with bullets)
        $existing = Credential::where('store_id', $storeId)
            ->where('feature', $feature)
            ->where('provider', $provider)
            ->first();

        if ($existing) {
            $existingSecrets = $existing->secrets;
            foreach ($secrets as $key => $value) {
                // If value is masked, retain the existing secret key
                if (str_contains($value, '••••')) {
                    $secrets[$key] = $existingSecrets[$key] ?? '';
                }
            }
        }

        Credential::updateOrCreate(
            [
                'store_id' => $storeId,
                'feature' => $feature,
                'provider' => $provider,
            ],
            [
                'secrets' => $secrets,
                'active' => $active,
            ]
        );

        app_alert_success(___('Integration updated successfully'));

        return back();
    }

    /**
     * Test Integration connection
     */
    public function testIntegration(Request $request)
    {
        $feature = $request->input('feature');
        $provider = $request->input('provider');
        $secrets = $request->input('secrets', []);
        $storeId = $request->input('store_id');

        // Verify ownership before touching this store's stored secrets (as updateIntegrations
        // does). Without it, a client could pass another tenant's store_id, have their
        // masked (••••) key substituted with the victim's real stored secret below, and use
        // this endpoint as a live credential-validity oracle that also burns the victim's
        // third-party API quota.
        $request->user()->stores()->where('id', $storeId)->firstOrFail();

        // Check for masked values and populate from DB if needed
        $existing = Credential::where('store_id', $storeId)
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
            if ($feature === 'social') {
                // FEATURES-5 A2 — validate key SHAPE only (no live OAuth round-trip).
                if ($provider === 'apple') {
                    $ok = ! empty($secrets['client_id']) && ! empty($secrets['team_id'])
                        && ! empty($secrets['key_id']) && ! empty($secrets['private_key']);
                    if ($ok) {
                        // Confirm the .p8 actually signs an ES256 JWT.
                        $ok = AppleClientSecret::generate($secrets) !== null;
                    }
                    $success = $ok;
                    $message = $ok ? ___('Apple keys look valid.') : ___('Apple configuration is incomplete or the private key is invalid.');
                } else {
                    $ok = ! empty($secrets['client_id']) && ! empty($secrets['client_secret']);
                    $success = $ok;
                    $message = $ok ? ___('Configuration looks valid.') : ___('Client ID and client secret are required.');
                }
            } elseif ($feature === 'ai') {
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
                        $model = config('ai.providers.gemini.model', 'gemini-2.5-flash');
                        $client = Http::post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", [
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

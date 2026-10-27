<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers\Api\V1;

use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\AccountDeletion;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Juwri\Core\Support\OwnerApp;
use Juwri\Core\Support\SafeRedirect;
use Juwri\Modules\Auth\Services\TwoFactorService;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Messaging\Contracts\MobilePush;
use Juwri\Modules\Messaging\Models\PushSubscription;
use Juwri\Modules\Messaging\Services\MobilePush\MobilePushService;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

/**
 * Owner mobile app (Capacitor shell) API — rev/mobile-app/IMPLEMENTATION-SPEC.md.
 *
 * The shell renders the live dashboard in a WebView. This controller is the
 * small native layer around it:
 *
 *  - config        GET  /api/v1/app/config          public: version gate + auth links
 *  - login         POST /api/v1/app/auth/login      public: credentials (+2FA) → app token
 *  - me            GET  /api/v1/app/me              app token: user, stores, tabs, More sheet
 *  - session       POST /api/v1/app/session         app token: one-time URL that logs the WebView in
 *  - handoff       GET  /api/v1/app/handoff/{nonce} browser: consumes that URL, sets the web session
 *  - registerDevice / unregisterDevice              app token: native push token for a store
 *  - logout        POST /api/v1/app/auth/logout     app token: revoke this install
 *
 * Security model:
 *  - App tokens are `api_tokens` rows with kind=app (EnsureOwnerAppToken). Only
 *    they can be exchanged for a web session; integration tokens cannot.
 *  - Login applies the web login's rules: username OR email, blocked accounts
 *    refused, admins refused, TOTP 2FA enforced (code or recovery code).
 *  - The hand-off URL is single-use (Cache::pull), expires in 60s and can only
 *    redirect to a relative same-site path.
 */
class OwnerAppController extends Controller
{
    private const HANDOFF_PREFIX = 'owner_app_handoff:';

    /** Abilities an app token carries on the owner REST API (future native screens). */
    public const TOKEN_ABILITIES = [
        'restaurants:read',
        'menu:write',
        'orders:write',
        'customers:read',
        'analytics:read',
    ];

    // ── public ────────────────────────────────────────────────────────────────

    /** Version gate + the links the native login screen shows. No auth. */
    /** Hotwire Native path configuration for the app shell (spec §3.4 / S1). */
    public function pathConfiguration(string $platform): JsonResponse
    {
        abort_unless(in_array($platform, ['ios', 'android'], true), 404);

        return response()->json(OwnerApp::pathConfiguration($platform), 200, [
            'Cache-Control' => 'public, max-age=300',
        ], JSON_UNESCAPED_SLASHES);
    }

    public function config(Request $request): JsonResponse
    {
        $version = (string) $request->query('version', '');
        $platform = OwnerApp::platform($request->query('platform'));

        $versions = [];
        foreach ($platform ? [$platform] : OwnerApp::PLATFORMS as $p) {
            $versions[$p] = OwnerApp::versionInfo($p, $version !== '' ? $version : null);
        }

        return response()->json([
            'api_version' => 1,
            'enabled' => (bool) config('mobile_app.enabled', true),
            'app_id' => (string) config('mobile_app.app_id'),
            'versions' => $versions,
            'links' => [
                'login' => OwnerApp::path('login'),
                'register' => OwnerApp::path('register'),
                'forgot_password' => OwnerApp::path('password.request'),
            ],
            'handoff_ttl_seconds' => (int) config('mobile_app.handoff_ttl_seconds', 60),
        ]);
    }

    /** Credentials (+ TOTP) → app token. Mirrors LoginController's rules. */
    public function login(Request $request): JsonResponse
    {
        if (! config('mobile_app.enabled', true)) {
            return ApiError::response(403, 'app_disabled', 'The mobile app is not available.');
        }

        $data = $request->validate([
            'username' => ['required', 'string', 'max:191'], // email or username, like the web form
            'password' => ['required', 'string', 'max:191'],
            'device_id' => ['required', 'string', 'max:64'],
            'platform' => ['required', 'in:'.implode(',', OwnerApp::PLATFORMS)],
            'device_name' => ['nullable', 'string', 'max:80'],
            'app_version' => ['nullable', 'string', 'max:24'],
            'otp' => ['nullable', 'string', 'max:32'],
        ]);

        $login = trim($data['username']);
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        // Same user provider the web guard uses — no session is touched here.
        $provider = Auth::createUserProvider(config('auth.guards.web.provider', 'users'));
        $user = $provider?->retrieveByCredentials([$field => $login]);

        if (! $user instanceof User || ! $provider->validateCredentials($user, ['password' => $data['password']])) {
            return ApiError::response(422, 'invalid_credentials', 'These credentials do not match our records.');
        }

        if ((int) $user->status === 0) {
            // Owner-requested deletion in its grace period: tell the app, so it can say
            // "closed, restore from the email" instead of "blocked" (restore is web-only).
            if ($pending = AccountDeletion::pendingFor((int) $user->id)) {
                return ApiError::response(403, 'account_deletion_pending', 'This account was closed and will be permanently deleted on '.$pending->purge_after->toDateString().'. Use the restore link that was emailed to you.');
            }

            return ApiError::response(403, 'account_blocked', 'Your account is blocked.');
        }
        if ($user->isAdmin()) {
            return ApiError::response(403, 'admin_not_supported', 'Admin accounts cannot use the owner app.');
        }

        if ($user->hasTwoFactorEnabled()) {
            $otp = trim((string) ($data['otp'] ?? ''));
            if ($otp === '') {
                return ApiError::response(401, 'two_factor_required', 'Enter the code from your authenticator app.');
            }

            $twoFactor = app(TwoFactorService::class);
            if (! $twoFactor->verifyCode($user, $otp) && ! $twoFactor->useRecoveryCode($user, $otp)) {
                return ApiError::response(422, 'invalid_code', 'That code is not valid.');
            }
        }

        // One token per install: a re-login on the same device replaces its token.
        $replaced = ApiToken::where('user_id', $user->id)
            ->where('kind', 'app')
            ->where('device_id', $data['device_id']);
        foreach ($replaced->pluck('id') as $oldId) {
            app(MobilePush::class)->revokeDevices(['api_token_id' => (int) $oldId]);
        }
        $replaced->delete();

        $raw = Str::random(64);
        $token = ApiToken::create([
            'user_id' => $user->id,
            'name' => Str::limit('Mobile app · '.(($data['device_name'] ?? null) ?: ucfirst($data['platform'])), 80, ''),
            'token' => hash('sha256', $raw),
            'abilities' => self::TOKEN_ABILITIES,
            'kind' => 'app',
            'device_id' => $data['device_id'],
            'platform' => $data['platform'],
            'expires_at' => now()->addDays(max(1, (int) config('mobile_app.token_ttl_days', 180))),
        ]);

        $stores = $this->stores($user);

        return response()->json([
            'token' => $raw,
            'token_type' => 'Bearer',
            'expires_at' => $token->expires_at?->toIso8601String(),
            'user' => $this->userPayload($user),
            'stores' => $stores,
            'default_store_id' => $this->defaultStoreId($user, $stores),
        ]);
    }

    /**
     * Consume a one-time hand-off URL: log the WebView into the dashboard and
     * land it on the requested path. Any failure lands on the web login page,
     * which the shell treats as "session lost — run the exchange again".
     */
    public function handoff(Request $request, string $nonce): RedirectResponse
    {
        $payload = Cache::pull(self::HANDOFF_PREFIX.hash('sha256', $nonce));

        $token = is_array($payload) ? ApiToken::find($payload['token_id'] ?? 0) : null;
        $user = $token?->user;

        if (! $token
            || ($token->kind ?? 'api') !== 'app'
            || $token->isExpired()
            || ! $user instanceof User
            || (int) $user->id !== (int) ($payload['user_id'] ?? 0)
            || (int) $user->status === 0
            || $user->isAdmin()) {
            return redirect()->to(OwnerApp::path('login') ?? '/login');
        }

        // A staff login left in this WebView would otherwise shadow the owner:
        // dashboard_store_ids() prefers the staff session.
        $request->session()->forget('staff_session_id');
        Auth::guard('staff')->logout();

        Auth::guard('web')->login($user, true);
        $request->session()->regenerate();
        // P6-75: which device token this WebView session belongs to ("keep this device signed in").
        $request->session()->put('owner_app_token_id', (int) $token->id);

        $storeId = (int) ($payload['store_id'] ?? 0);
        if ($storeId && in_array($storeId, owner_store_ids((int) $user->id), true)) {
            $request->session()->put('dashboard_store_id', $storeId);
        }

        return redirect()->to($this->localized((string) ($payload['to'] ?? '/dashboard'), $user));
    }

    // ── app token ─────────────────────────────────────────────────────────────

    /** Who am I, which stores, and how should the shell lay out its tabs. */
    public function me(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $stores = $this->stores($user);
        $storeId = $this->resolveStoreId($request, $user, $stores);
        $store = $storeId ? Store::find($storeId) : null;

        return response()->json([
            'user' => $this->userPayload($user),
            'stores' => $stores,
            'default_store_id' => $this->defaultStoreId($user, $stores),
            'store' => $store ? [
                'id' => (int) $store->id,
                'push_enabled' => MobilePushService::enabled((int) $store->id),
            ] : null,
            'tabs' => $store ? OwnerApp::tabsFor($store) : [],
            'more' => $store ? OwnerApp::moreFor($store) : [],
            // An owner with no store yet goes straight to store creation.
            'onboarding_path' => $store ? null : OwnerApp::path('stores.create'),
            'token_expires_at' => $this->token($request)->expires_at?->toIso8601String(),
        ]);
    }

    /** Mint a one-time URL that logs the WebView into the dashboard. */
    public function session(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $token = $this->token($request);

        $data = $request->validate([
            'to' => ['nullable', 'string', 'max:2048'],
            'store_id' => ['nullable', 'integer'],
        ]);

        $to = '/dashboard';
        if (! empty($data['to'])) {
            $safe = SafeRedirect::resolve($data['to']);
            if ($safe === null || ! str_starts_with($safe, '/') || str_starts_with($safe, '/api/v1/app/')) {
                throw ValidationException::withMessages(['to' => 'The destination must be a relative dashboard path.']);
            }
            $to = $safe;
        }

        $storeId = isset($data['store_id']) ? (int) $data['store_id'] : null;
        if ($storeId && ! in_array($storeId, owner_store_ids((int) $user->id), true)) {
            return ApiError::response(404, 'not_found', 'Store not found.');
        }

        $ttl = max(10, (int) config('mobile_app.handoff_ttl_seconds', 60));
        $nonce = Str::random(64);
        Cache::put(self::HANDOFF_PREFIX.hash('sha256', $nonce), [
            'token_id' => (int) $token->id,
            'user_id' => (int) $user->id,
            'to' => $to,
            'store_id' => $storeId,
        ], $ttl);

        // Sliding expiry: an owner who opens the app keeps their login.
        $token->forceFill([
            'expires_at' => now()->addDays(max(1, (int) config('mobile_app.token_ttl_days', 180))),
        ])->saveQuietly();

        return response()->json([
            'url' => route('api.v1.app.handoff', ['nonce' => $nonce]),
            'expires_in' => $ttl,
        ]);
    }

    /** Register this install's push token for one of the owner's stores. */
    public function registerDevice(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'in:'.implode(',', OwnerApp::PLATFORMS)],
            'device_name' => ['nullable', 'string', 'max:80'],
            'app_version' => ['nullable', 'string', 'max:24'],
            'os_version' => ['nullable', 'string', 'max:24'],
        ]);

        $storeId = (int) $data['store_id'];
        if (! in_array($storeId, owner_store_ids((int) $user->id), true)) {
            return ApiError::response(404, 'not_found', 'Store not found.');
        }

        try {
            $sub = app(MobilePush::class)->registerDevice(
                $storeId,
                $data['token'],
                OwnerApp::pushPlatform($data['platform']),
                [
                    'device_name' => $data['device_name'] ?? null,
                    'app_version' => $data['app_version'] ?? null,
                    'os_version' => $data['os_version'] ?? null,
                    // P6-269: tie the device to this sign-in so logout removes it.
                    'api_token_id' => (int) $this->token($request)->id,
                ],
                null,
                (int) $user->id
            );
        } catch (ValidationException) {
            return ApiError::response(403, 'feature_disabled', 'Push notifications are not available for this store.');
        }

        return response()->json([
            'success' => true,
            'device' => ['id' => (int) $sub->id, 'name' => $sub->displayName(), 'store_id' => $storeId],
        ]);
    }

    /** Forget this install's push token (e.g. the owner turned notifications off). */
    public function unregisterDevice(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);

        $removed = PushSubscription::where('endpoint_hash', hash('sha256', $data['token']))
            ->where('user_id', $user->id)
            ->delete();

        return response()->json(['success' => true, 'removed' => $removed > 0]);
    }

    /** Revoke this install: its app token and (optionally) its push token. */
    public function logout(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $token = $this->token($request);

        $pushToken = (string) $request->input('push_token', '');
        if ($pushToken !== '') {
            PushSubscription::where('endpoint_hash', hash('sha256', $pushToken))
                ->where('user_id', $user->id)
                ->delete();
        }

        // P6-269: the device's push token goes with its app token, push_token sent or not.
        app(MobilePush::class)->revokeDevices(['api_token_id' => (int) $token->id]);
        $token->delete();

        return response()->json(['success' => true]);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function token(Request $request): ApiToken
    {
        return $request->attributes->get('_api_token');
    }

    private function user(Request $request): User
    {
        return $this->token($request)->user;
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'username' => (string) $user->username,
        ] + OwnerApp::localeFor($user);
    }

    /** @return list<array<string,mixed>> the stores this owner controls */
    private function stores(User $user): array
    {
        return Store::where('user_id', $user->id)
            ->orderBy('id')
            ->get(['id', 'title', 'slug', 'store_type', 'main_image'])
            ->map(fn (Store $p) => [
                'id' => (int) $p->id,
                'title' => (string) $p->title,
                'slug' => (string) $p->slug,
                'store_type' => (string) ($p->store_type ?: 'restaurant'),
                'group' => (string) ($p->verticalConfig()['group'] ?? 'food'),
                'logo_url' => $p->main_image ? asset('storage/store/logo/'.$p->main_image) : null,
                'currency' => store_currency((int) $p->id),
            ])
            ->all();
    }

    /** @param list<array<string, mixed>> $stores */
    private function defaultStoreId(User $user, array $stores): ?int
    {
        $ids = array_column($stores, 'id');
        $saved = (int) UserOption::getUserOption((int) $user->id, 'default_store_id');

        return in_array($saved, $ids, true) ? $saved : ($ids[0] ?? null);
    }

    /** @param list<array<string, mixed>> $stores */
    private function resolveStoreId(Request $request, User $user, array $stores): ?int
    {
        $asked = (int) $request->query('store_id', 0);

        return $asked && in_array($asked, array_column($stores, 'id'), true)
            ? $asked
            : $this->defaultStoreId($user, $stores);
    }

    /** Land on the path in the owner's language (the dashboard is locale-prefixed). */
    private function localized(string $path, User $user): string
    {
        $locale = (string) ($user->preferred_locale ?: '');

        try {
            if ($locale !== '' && in_array($locale, LaravelLocalization::getSupportedLanguagesKeys(), true)) {
                $url = LaravelLocalization::getLocalizedURL($locale, url($path));
                if (is_string($url) && $url !== '') {
                    return $url;
                }
            }
        } catch (\Throwable) {
            // fall through to the plain path
        }

        return $path;
    }
}

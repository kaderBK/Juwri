<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Http\Controllers\User;

use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Auth\Contracts\SocialCredentials;
use Laravel\Socialite\Facades\Socialite;

/**
 * FEATURES-5 Track A — Customer social sign-in (Google / Facebook / Apple).
 *
 * OAuth redirect URIs are GLOBAL, fixed callbacks (one per provider) so a single
 * OAuth app works for every restaurant. The originating restaurant is carried in
 * an encrypted `state` param (tamper-proof) rather than in the callback path.
 * Per-restaurant BYOK keys are injected into config at runtime before building
 * the Socialite driver (SocialCredentials).
 *
 * Does NOT touch the owner/admin Google login (Auth\SocialLoginController).
 */
class CustomerSocialAuthController extends Controller
{
    /** Holds sha256(nonce) for Apple's cross-site form_post callback (login-CSRF). */
    private const APPLE_NONCE_COOKIE = 'customer_social_apple_nonce';

    public function __construct(private SocialCredentials $resolver) {}

    /**
     * Kick off the OAuth dance for a restaurant + provider.
     */
    public function redirect(Request $request, $store, string $provider)
    {
        $store = $this->resolveRestaurant($store);

        abort_if(! in_array($provider, SocialCredentials::PROVIDERS, true), 404);
        abort_if(! $this->resolver->isEnabled($provider, $store->id), 404);

        $callback = $this->callbackUrl($provider);
        abort_if(! $this->resolver->configure($provider, $store->id, $callback), 404);

        // Login-CSRF defense: bind a single-use nonce to the initiating session and
        // verify it in the callback, so an attacker can't feed a captured `state` +
        // their own auth `code` to a victim's browser and silently log the victim
        // into the attacker's account. SameSite=lax sends the session cookie only on
        // a top-level GET to the platform host, so the OAuth leg must run on the
        // platform host — a custom-domain storefront hops there first, and the final
        // result is still delivered back to the store's own origin by the bounce.
        // Apple returns via a cross-site form_post (POST) where the SameSite=Lax
        // session cookie is not sent, so its nonce is bound to a dedicated
        // SameSite=None; Secure; HttpOnly cookie scoped to the Apple callback path
        // instead (a cross-site top-level POST does carry SameSite=None cookies).
        $platformHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if (strtolower($request->getHost()) !== $platformHost) {
            return redirect()->away(rtrim((string) config('app.url'), '/')
                .route('customer.social.redirect', [$store->id, $provider], false));
        }

        $nonce = Str::random(40);
        $nonceCookie = null;
        if ($provider === 'apple') {
            $nonceCookie = cookie(self::APPLE_NONCE_COOKIE, hash('sha256', $nonce), 15, $this->appleNoncePath(), null, true, true, false, 'none');
        } else {
            $request->session()->put('customer_social_nonce', hash('sha256', $nonce));
        }
        $state = Crypt::encryptString(json_encode(['r' => $store->id, 'p' => $provider, 't' => time(), 'n' => $nonce]));

        $driver = Socialite::driver($provider)->stateless()->redirectUrl($callback);

        if ($provider === 'apple') {
            // Apple needs name + email scopes and returns via form_post.
            $driver->scopes(['name', 'email']);
        }

        $response = $driver->with(['state' => $state])->redirect();
        if ($nonceCookie !== null) {
            $response->headers->setCookie($nonceCookie);
        }

        return $response;
    }

    /**
     * Global OAuth callback (GET for Google/Facebook, POST form_post for Apple).
     */
    public function callback(Request $request, string $provider)
    {
        abort_if(! in_array($provider, SocialCredentials::PROVIDERS, true), 404);

        $storeId = $this->restaurantFromState($request->input('state'));
        if (! $storeId) {
            return $this->fail($request, null, ___('Sign-in session expired, please try again.'));
        }

        // Login-CSRF: the state's nonce must match the one bound to THIS browser's
        // session at redirect() (single-use) — for Apple, to this browser's
        // SameSite=None nonce cookie (see redirect()).
        $nonceOk = $provider === 'apple' ? $this->consumeAppleNonce($request) : $this->consumeStateNonce($request);
        if (! $nonceOk) {
            return $this->fail($request, null, ___('Sign-in session expired, please try again.'));
        }

        $store = Store::find($storeId);
        if (! $store) {
            return $this->fail($request, null, ___('Restaurant not found.'));
        }

        if (! $this->resolver->isEnabled($provider, $store->id)) {
            abort(404);
        }

        $callback = $this->callbackUrl($provider);
        if (! $this->resolver->configure($provider, $store->id, $callback)) {
            abort(404);
        }

        try {
            $socialUser = Socialite::driver($provider)->stateless()->redirectUrl($callback)->user();
        } catch (\Throwable $e) {
            Log::warning('Customer social callback failed', [
                'provider' => $provider,
                'store' => $store->id,
                'error' => $e->getMessage(),
            ]);

            return $this->fail($request, $store, ___('Could not sign you in, please try again.'));
        }

        $customer = $this->findOrCreateCustomer($store, $provider, $socialUser, $request);

        // A6 — reseller/custom-domain bounce. The OAuth redirect_uri is a FIXED
        // platform-host callback (GCP-registered; see callbackUrl()), so a customer
        // who started on the store's custom domain lands here on the PLATFORM host
        // and a session set here would not reach that domain. When the store's
        // canonical origin differs from the platform host, mint a single-use,
        // server-side-burned login token and bounce the browser to that origin,
        // where bounce() performs the actual login. The origin is derived from the
        // store itself (effective_public_url) — never user input — so there is no
        // open-redirect surface.
        $origin = effective_public_url($store);
        if ($this->isForeignOrigin($origin)) {
            return $this->issueBounce($store, $customer, $origin);
        }

        Auth::guard('customer')->login($customer);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return redirect()->route('customer.account', $store->slug ?? $store->id);
    }

    /**
     * A6 — consume a one-time bounce token on the store's own origin and log the
     * customer in there. Single-use: the cache key is burned on first read. The
     * request host must match the store's canonical origin (foreign-origin replay
     * refused).
     */
    public function bounce(Request $request): RedirectResponse
    {
        $token = (string) $request->query('token', '');
        if ($token === '') {
            return $this->fail($request, null, ___('Sign-in link is invalid.'));
        }

        // Cache::pull atomically reads AND deletes → single use.
        $entry = Cache::pull($this->bounceKey($token));
        if (! is_array($entry) || empty($entry['cid']) || empty($entry['rid'])) {
            return $this->fail($request, null, ___('This sign-in link has expired, please try again.'));
        }

        $store = Store::find($entry['rid']);
        $customer = Customer::where('id', $entry['cid'])
            ->where('store_id', $entry['rid'])
            ->first();
        if (! $store || ! $customer) {
            return $this->fail($request, $store, ___('Could not sign you in, please try again.'));
        }

        // Foreign-origin guard: this host must be the store's canonical origin.
        $originHost = strtolower((string) parse_url(effective_public_url($store), PHP_URL_HOST));
        if ($originHost === '' || $originHost !== strtolower($request->getHost())) {
            return $this->fail($request, $store, ___('This sign-in link is not valid for this address.'));
        }

        Auth::guard('customer')->login($customer);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return redirect()->route('customer.account', $store->slug ?? $store->id);
    }

    /** Whether the given origin URL is on a host other than the platform host. */
    protected function isForeignOrigin(string $origin): bool
    {
        $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
        $platformHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return $originHost !== '' && $originHost !== $platformHost;
    }

    /** Mint a single-use bounce token and redirect the browser to the origin. */
    protected function issueBounce(Store $store, Customer $customer, string $origin): RedirectResponse
    {
        $token = Str::random(64);
        Cache::put($this->bounceKey($token), [
            'cid' => $customer->id,
            'rid' => $store->id,
        ], now()->addSeconds(120));

        $target = rtrim($origin, '/').'/auth/social/customer/bounce?token='.$token;

        return redirect()->away($target);
    }

    /** Cache key for a bounce token — HASHED so a cache dump cannot be replayed. */
    protected function bounceKey(string $token): string
    {
        return 'social_bounce:'.hash('sha256', $token);
    }

    /**
     * Account linking: match by (provider, uid) first, then by verified email
     * within the same restaurant → link; else create a fresh customer.
     */
    protected function findOrCreateCustomer(Store $store, string $provider, $socialUser, Request $request): Customer
    {
        $uid = (string) $socialUser->getId();
        $email = $socialUser->getEmail();
        $name = $this->resolveName($provider, $socialUser, $request);
        $avatar = method_exists($socialUser, 'getAvatar') ? $socialUser->getAvatar() : null;

        // Whether the PROVIDER itself vouches the email is verified. A bare email
        // match is an account-takeover vector (anyone can create a Google account
        // claiming a victim's address as the display email), so email linking and
        // the email_verified_at stamp both require this bit.
        $emailVerified = $email ? $this->providerEmailVerified($provider, $socialUser) : false;

        // 1) Existing link by provider + uid (identity proven by the provider UID).
        $customer = Customer::where('store_id', $store->id)
            ->where('oauth_provider', $provider)
            ->where('oauth_uid', $uid)
            ->first();

        // 2) Link to an existing email-based account in the same restaurant — ONLY
        //    when the provider VERIFIED the email. Unverified/Facebook → skip to
        //    create a fresh, separate account instead of hijacking one.
        if (! $customer && $email && $emailVerified) {
            $customer = Customer::where('store_id', $store->id)
                ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $email))])
                ->first();
        }

        if ($customer) {
            $customer->forceFill(array_filter([
                'oauth_provider' => $provider,
                'oauth_uid' => $uid,
                'avatar_url' => $avatar ?: $customer->avatar_url,
                'email' => $customer->email ?: $email,
                'name' => $customer->name ?: $name,
            ], fn ($v) => $v !== null && $v !== ''));
            if (! $customer->email_verified_at && $email && $emailVerified) {
                $customer->email_verified_at = now();
            }
            $customer->save();

            return $customer;
        }

        // 3) Create a fresh account. An UNVERIFIED email that another customer in
        //    this restaurant already holds must NOT be claimed here — linking would
        //    be takeover and re-storing it collides with the (store_id, email)
        //    unique index — so the new account is created without that email.
        $emailForCreate = $email;
        if ($email && ! $emailVerified) {
            $taken = Customer::where('store_id', $store->id)
                ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $email))])
                ->exists();
            if ($taken) {
                $emailForCreate = null;
            }
        }

        return Customer::create([
            'store_id' => $store->id,
            'name' => $name ?: ___('Guest'),
            'email' => $emailForCreate,
            'oauth_provider' => $provider,
            'oauth_uid' => $uid,
            'avatar_url' => $avatar,
            'email_verified_at' => ($emailForCreate && $emailVerified) ? now() : null,
            'source' => 'social_'.$provider,
        ]);
    }

    /**
     * Whether the OAuth provider asserts the account's email is verified. Google
     * (and Apple) carry an `email_verified` claim in the raw user payload
     * (Socialite exposes it on the public `$user->user` array). Facebook has no
     * such claim → always treated as unverified. Missing/false → unverified.
     */
    protected function providerEmailVerified(string $provider, mixed $socialUser): bool
    {
        if ($provider === 'facebook') {
            return false;
        }

        $raw = (is_object($socialUser) && property_exists($socialUser, 'user') && is_array($socialUser->user))
            ? $socialUser->user
            : [];
        $claim = $raw['email_verified'] ?? null;

        return $claim === true || $claim === 1 || $claim === '1' || $claim === 'true';
    }

    /**
     * Apple returns the name only on the FIRST authorization, in a `user` JSON
     * POST field. Persist it immediately; fall back to Socialite's name.
     */
    protected function resolveName(string $provider, $socialUser, Request $request): ?string
    {
        if ($provider === 'apple' && $request->filled('user')) {
            $decoded = json_decode($request->input('user'), true);
            $first = $decoded['name']['firstName'] ?? '';
            $last = $decoded['name']['lastName'] ?? '';
            $full = trim($first.' '.$last);
            if ($full !== '') {
                return $full;
            }
        }

        return $socialUser->getName() ?: $socialUser->getNickname();
    }

    /**
     * Verify and CONSUME the single-use login-CSRF nonce: the `n` embedded in the
     * encrypted state must hash to the value bound to this session at redirect().
     * Pull removes it so a state can't be replayed. Returns false when absent or
     * mismatched (an attacker-initiated flow has no matching session nonce).
     */
    protected function consumeStateNonce(Request $request): bool
    {
        $expected = $request->session()->pull('customer_social_nonce');
        if (! $expected) {
            return false;
        }

        return $this->stateNonceMatches($request, (string) $expected);
    }

    /**
     * Apple variant of consumeStateNonce(): the expected hash lives in the
     * SameSite=None nonce cookie set at redirect(). The cookie is cleared on every
     * attempt so a state can't be replayed; a cross-site post from an attacker's
     * page carries the victim's cookie (or none), never the attacker's nonce.
     */
    protected function consumeAppleNonce(Request $request): bool
    {
        $expected = (string) $request->cookie(self::APPLE_NONCE_COOKIE, '');
        Cookie::queue(Cookie::forget(self::APPLE_NONCE_COOKIE, $this->appleNoncePath()));

        return $expected !== '' && $this->stateNonceMatches($request, $expected);
    }

    /** Whether the `n` inside the encrypted state hashes to the expected value. */
    protected function stateNonceMatches(Request $request, string $expected): bool
    {
        try {
            $data = json_decode(Crypt::decryptString((string) $request->input('state')), true);
        } catch (\Throwable $e) {
            return false;
        }
        $nonce = is_array($data) ? (string) ($data['n'] ?? '') : '';

        return $nonce !== '' && hash_equals($expected, hash('sha256', $nonce));
    }

    /** Cookie path for the Apple nonce: only the Apple callback ever receives it. */
    protected function appleNoncePath(): string
    {
        return route('customer.social.callback', 'apple', false);
    }

    protected function restaurantFromState($state): ?int
    {
        if (! $state) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($state), true);
        } catch (\Throwable $e) {
            return null;
        }

        // 15-minute window against replay.
        if (! isset($data['r']) || ! isset($data['t']) || (time() - (int) $data['t']) > 900) {
            return null;
        }

        return (int) $data['r'];
    }

    protected function resolveRestaurant($store): Store
    {
        return Store::idOrSlug($store)->firstOrFail();
    }

    /**
     * The OAuth redirect_uri. Google requires an EXACT match against the client's
     * registered URIs, which are platform-host only — so this is always forced to
     * the platform host (config('app.url')) even when the request arrived on a
     * store's custom domain. A6 bounces the customer back to their origin after.
     */
    protected function callbackUrl(string $provider): string
    {
        $path = route('customer.social.callback', $provider, false); // leading-slash path

        return rtrim((string) config('app.url'), '/').$path;
    }

    protected function fail(Request $request, ?Store $store, string $message): RedirectResponse
    {
        app_alert_error($message);
        if ($store) {
            return redirect()->route('publicView', $store->slug ?? $store->id);
        }

        return redirect('/');
    }
}

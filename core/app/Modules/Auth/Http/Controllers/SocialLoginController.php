<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Http\Controllers;

use App\Providers\RouteServiceProvider;
use Cviebrock\EloquentSluggable\Services\SlugService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Billing\Subscriptions;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Mail\UserDetails;
use Juwri\Core\Models\User;
use Juwri\Core\Support\SafeRedirect;
use Juwri\Modules\Auth\Contracts\EmailChangeUndos;
use Laravel\Socialite\Contracts\User as SocialUser;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * Owner sign-in / sign-up with Google or Facebook (Socialite), plus linking
 * and unlinking a provider on the signed-in owner's account.
 *
 * Identity rules: a returning identity is matched on provider + provider
 * uid. An email match is only trusted when the provider itself vouches for
 * the address (Google's `email_verified` claim; Facebook never does), and
 * then only to attach the uid to an account that has none — otherwise a
 * provider account with someone else's address could take the account over.
 */
class SocialLoginController extends Controller
{
    /** Providers an owner account can store (users.oauth_provider enum). */
    private const OWNER_PROVIDERS = ['google', 'facebook'];

    /** Send the visitor to the provider's consent screen. */
    public function redirect(Request $request, string $provider): SymfonyRedirect
    {
        abort_unless($this->providerEnabled($provider), 404);

        // A same-site ?redirect survives the OAuth round trip in the session.
        SafeRedirect::stashIntended($request->query('redirect'));

        return Socialite::driver($provider)->redirect();
    }

    /**
     * Start linking a provider to the CURRENT owner account. The session flag
     * makes the shared callback link instead of signing in. Route is auth-only.
     */
    public function connectRedirect(Request $request, string $provider): SymfonyRedirect
    {
        abort_unless(in_array($provider, self::OWNER_PROVIDERS, true), 404);
        abort_unless($this->providerEnabled($provider), 404);

        $request->session()->put('social_connect_provider', $provider);

        return Socialite::driver($provider)->redirect();
    }

    /**
     * Unlink the provider from the current owner account. Owners always keep
     * their email + password (and password reset), so this cannot lock them out.
     */
    public function disconnect(Request $request, string $provider): RedirectResponse
    {
        $owner = Auth::user();
        abort_unless($owner instanceof User, 403);

        if ($owner->oauth_provider === $provider) {
            $owner->forceFill(['oauth_provider' => null, 'oauth_uid' => null])->save();
            app_alert_success(___('Disconnected your :provider account.', ['provider' => ucfirst($provider)]));
        }

        return redirect()->route('settings');
    }

    /** Provider callback: link, sign in, or create the owner account. */
    public function callback(Request $request, string $provider): RedirectResponse|JsonResponse
    {
        try {
            abort_unless($this->providerEnabled($provider), 404);

            $identity = Socialite::driver($provider)->user();

            if ($request->session()->pull('social_connect_provider') === $provider && Auth::check()) {
                return $this->linkToCurrentUser($provider, $identity);
            }

            // An address someone registered with a password but never
            // confirmed is not handed to the provider identity: the
            // registrant could be anyone, and would keep a way into the
            // account (pre-account hijack). The owner signs in with the
            // password or confirms the address first.
            if ($this->unconfirmedAccountHoldsEmail($provider, $identity)) {
                app_alert_error(___('That account already exists — please sign in'));

                return redirect()->route('login');
            }

            $owner = $this->findOwner($provider, $identity);

            if (! $owner) {
                if (! config('settings.enable_user_registration', 1)) {
                    app_alert_error(___('Registration is currently disabled.'));

                    return redirect()->route('login');
                }
                // Upstream c623b24e3 (reseller-site 1.2): on a reseller's domain new accounts come only
                // from that reseller's signup (linked to the reseller), never from here.
                if (app(ResellerContext::class)->forRequest()) {
                    app_alert_error(___('No account found. Please sign up first.'));

                    return redirect()->route('login');
                }

                // BL-036: an address awaiting a "This wasn't me — undo" stays reserved for its account.
                if (app(EmailChangeUndos::class)->isReserved((string) $identity->getEmail())) {
                    app_alert_error(___('This email address is not available right now. Please use another one.'));

                    return redirect()->route('login');
                }

                $owner = $this->createOwner($provider, $identity);
            }

            // WI-15-sec: the provider proves the identity (first factor) only.
            // A blocked account is not signed in; a two-factor account goes
            // through the challenge, as after a password.
            if (! $owner->wasRecentlyCreated && (int) $owner->status === 0) {
                app_alert_error(___('Your account is blocked'));

                return redirect()->route('login');
            }
            if ($challenge = TwoFactorController::divertToChallenge($request, $owner)) {
                return $challenge;
            }

            Auth::login($owner);

            return redirect()->intended(RouteServiceProvider::USER);
        } catch (Exception $e) {
            report($e);
            app_alert_error(___('Sign-in with this provider failed. Please try again.'));

            return redirect()->route('login');
        }
    }

    private function providerEnabled(string $provider): bool
    {
        return (bool) config('settings.'.$provider.'_login');
    }

    private function findOwner(string $provider, SocialUser $identity): ?User
    {
        $uid = (string) $identity->getId();

        $owner = User::where('oauth_provider', $provider)->where('oauth_uid', $uid)->first();

        if ($owner || ! $identity->getEmail() || ! $this->providerVerifiedEmail($provider, $identity)) {
            return $owner;
        }

        $owner = User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $identity->getEmail()))])->first();

        if ($owner && ! $owner->oauth_uid) {
            $owner->forceFill(['oauth_provider' => $provider, 'oauth_uid' => $uid])->save();
        }

        return $owner;
    }

    /**
     * No account carries this provider identity yet, and the provider-vouched
     * address belongs to an account whose owner never confirmed it.
     */
    private function unconfirmedAccountHoldsEmail(string $provider, SocialUser $identity): bool
    {
        $email = $identity->getEmail();
        if (! $email || ! $this->providerVerifiedEmail($provider, $identity)) {
            return false;
        }
        if (User::where('oauth_provider', $provider)->where('oauth_uid', (string) $identity->getId())->exists()) {
            return false;
        }

        return User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $email))])->whereNull('email_verified_at')->exists();
    }

    private function createOwner(string $provider, SocialUser $identity): User
    {
        $location = user_ip_info()->location;
        $email = (string) $identity->getEmail();

        // Username from the email's local part, made unique, underscores only.
        $username = str_replace('-', '_', SlugService::createSlug(User::class, 'username', Str::before($email, '@')));

        $owner = User::create([
            'name' => $identity->getName(),
            'username' => $username,
            'email' => $email,
            // Random password: the owner signs in through the provider or sets
            // one with a password reset.
            'password' => Hash::make(Str::random(32)),
            'group_id' => config('settings.default_user_plan'),
            'country' => $location->country,
            'country_code' => User::isoCountryCode($location->country_code), // geo absent → null, not 'Other'
            'oauth_uid' => $identity->getId(),
            'oauth_provider' => $provider,
        ]);

        // Only vouch for the address when the provider did; otherwise the
        // owner confirms it through the normal verification mail.
        if ($this->providerVerifiedEmail($provider, $identity)) {
            $owner->markEmailAsVerified();
        }

        app(Subscriptions::class)->startFromRegistration($owner);
        $owner->sendMail(new UserDetails($owner));

        create_notification(
            ___(':user_name has registered', ['user_name' => $owner->name]),
            'new_user',
            route('admin.users.edit', $owner->id)
        );

        return $owner;
    }

    /**
     * Bind a provider identity to the signed-in owner. Refused when the
     * identity already belongs to a different account.
     */
    private function linkToCurrentUser(string $provider, SocialUser $identity): RedirectResponse
    {
        /** @var User $owner */
        $owner = Auth::user();
        $uid = (string) $identity->getId();

        $takenElsewhere = User::where('oauth_provider', $provider)
            ->where('oauth_uid', $uid)
            ->where('id', '!=', $owner->id)
            ->exists();

        if ($takenElsewhere) {
            app_alert_error(___('That :provider account is already linked to another user.', ['provider' => ucfirst($provider)]));

            return redirect()->route('settings');
        }

        $owner->forceFill(['oauth_provider' => $provider, 'oauth_uid' => $uid])->save();
        app_alert_success(___('Your :provider account is now connected.', ['provider' => ucfirst($provider)]));

        return redirect()->route('settings');
    }

    /**
     * Whether the provider asserts the email address is verified: Google's
     * raw `email_verified` claim. Facebook sends no such claim.
     */
    private function providerVerifiedEmail(string $provider, SocialUser $identity): bool
    {
        if ($provider === 'facebook') {
            return false;
        }

        $raw = property_exists($identity, 'user') && is_array($identity->user) ? $identity->user : [];
        $claim = $raw['email_verified'] ?? null;

        return in_array($claim, [true, 1, '1', 'true'], true);
    }
}

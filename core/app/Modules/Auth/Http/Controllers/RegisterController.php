<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Http\Controllers;

use App\Models\Referral;
use App\Models\ReferralConversion;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Juwri\Core\Contracts\Billing\Subscriptions;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Legal\CookieConsent;
use Juwri\Core\Legal\LegalAcceptances;
use Juwri\Core\Legal\LegalDocuments;
use Juwri\Core\Mail\UserDetails;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\UserSettings;
use Juwri\Core\Support\SafeRedirect;
use Juwri\Modules\Auth\Contracts\EmailChangeUndos;

/**
 * Owner self-sign-up. The route group is closed by `checkRegistrationDisabled`
 * when the platform turns sign-ups off; `throttle:auth-register` limits
 * attempts. A new owner is signed in straight away and must still confirm
 * their email address through the verification mail.
 */
class RegisterController extends Controller
{
    public function __construct()
    {
        $this->middleware('guest');
    }

    public function showRegistrationForm(Request $request): View
    {
        $this->rememberReferral($request);

        // A same-site ?redirect becomes the post-sign-up destination.
        SafeRedirect::stashIntended($request->query('redirect'));

        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        // P1-23: where JUWRI LTD's legal pages are published, the Terms / DPA / AUP box and the business (not
        // consumer) confirmation are required and each accepted version is recorded (Terms §14.13). The product
        // news opt-in is never pre-ticked and never required.
        $legal = LegalDocuments::publishedHere();
        Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'min:2', 'max:40'],
            'username' => $this->usernameRules(),
            'email' => $this->emailRules(),
            'password' => ['required', 'string', Password::defaults()],
            'agree_for_term' => $legal ? ['required', 'accepted'] : ['sometimes', 'required'],
            'business_confirm' => $legal ? ['required', 'accepted'] : ['nullable'],
            'marketing_optin' => ['nullable', 'boolean'],
        ] + validate_recaptcha(), [
            'agree_for_term.required' => __('site.signup.accept_required'),
            'agree_for_term.accepted' => __('site.signup.accept_required'),
            'business_confirm.required' => __('site.signup.business_required'),
            'business_confirm.accepted' => __('site.signup.business_required'),
        ])->validate();

        $location = user_ip_info()->location;

        $user = User::create([
            'name' => $request->input('name') ?? $request->input('username'),
            'username' => $request->input('username'),
            'email' => $request->input('email'),
            'password' => Hash::make($request->input('password')),
            'group_id' => config('settings.default_user_plan'),
            'country' => $location->country,
            'country_code' => User::isoCountryCode($location->country_code), // geo absent → null, not 'Other'
        ]);

        LegalAcceptances::record($user, 'signup', $request);
        if ($request->boolean('marketing_optin')) {
            UserSettings::setMany((int) $user->id, ['marketing_optin' => true, 'marketing_optin_at' => now()->toIso8601String()]);
        }

        // Mirror the default plan into the subscriptions table; group_id above
        // stays the source of truth for now.
        app(Subscriptions::class)->startFromRegistration($user);

        $user->sendMail(new UserDetails($user));
        $user->sendEmailVerificationNotification();

        create_notification(
            ___(':user_name has registered', ['user_name' => $user->name]),
            'new_user',
            route('admin.users.edit', $user->id)
        );

        // Every owner gets their own referral code; a remembered incoming code
        // is recorded as a pending conversion for the referrer.
        Referral::forUser($user);
        $this->creditReferrer($request, $user);

        ab_convert('signup_cta');
        ab_convert('hero_headline');

        Auth::guard()->login($user);

        return redirect()->intended(RouteServiceProvider::USER);
    }

    /**
     * Live field check for the sign-up form. Answers with a small HTML
     * fragment the form script shows next to the field.
     */
    public function checkAvailability(Request $request): Response
    {
        $rules = array_intersect_key([
            'name' => ['required', 'string', 'min:2', 'max:40'],
            'username' => $this->usernameRules(),
            'email' => $this->emailRules(),
            'password' => ['required', 'string', Password::defaults()],
        ], $request->all());

        $validator = Validator::make($request->all(), $rules);

        $html = $validator->fails()
            ? "<span class='status-not-available'> ".$validator->errors()->first().'</span>'
            : "<span class='status-available'>".__('Success').'</span>';

        return response($html);
    }

    /** @return list<string> */
    private function usernameRules(): array
    {
        return ['required', 'alpha_dash', 'min:2', 'max:16', 'unique:users'];
    }

    /** @return list<string|\Closure> */
    private function emailRules(): array
    {
        // BL-036: an address awaiting a "This wasn't me — undo" stays reserved for its account.
        return ['required', 'string', 'email', 'max:100', 'unique:users', app(EmailChangeUndos::class)->reservedEmailRule()];
    }

    /** Keep a valid ?ref code in a cookie and count the click. */
    private function rememberReferral(Request $request): void
    {
        $code = strtoupper((string) $request->query('ref', ''));

        if (! preg_match('/^[A-Z0-9]{6,20}$/', $code) || ! Referral::where('code', $code)->exists()) {
            return;
        }

        // P1-23: a cookie (registry lifetime) only with marketing consent; otherwise the code lives in the session (this visit).
        CookieConsent::rememberAttribution($request, 'ref_code', $code);
        Referral::where('code', $code)->increment('clicks');
    }

    private function creditReferrer(Request $request, User $user): void
    {
        $code = CookieConsent::attribution($request, 'ref_code');

        if (! $code) {
            return;
        }

        $referral = Referral::where('code', $code)->first();

        // Never credit an account for referring itself.
        if ($referral && $referral->user_id !== $user->id) {
            ReferralConversion::firstOrCreate(
                ['new_user_id' => $user->id],
                [
                    'referral_id' => $referral->id,
                    'status' => 'pending',
                    'reward_type' => 'credit',
                    'reward_amount' => 10.00, // reviewed by an admin before payout
                ]
            );
        }

        CookieConsent::forgetAttribution($request, 'ref_code');
    }
}

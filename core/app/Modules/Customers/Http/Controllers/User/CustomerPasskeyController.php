<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Http\Controllers\User;

use App\Models\Customer;
use App\Services\OrderClaimService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Scopes\AccountScope;
use Juwri\Core\Models\Store;
use Juwri\Modules\Auth\Contracts\PasskeyCeremonies;
use Juwri\Modules\Customers\Models\CustomerPasskey;
use Juwri\Modules\Customers\Services\Passkeys\CustomerPasskeys;
use Juwri\Modules\Customers\Services\Passkeys\CustomerRelyingParty;
use Juwri\Modules\Customers\Support\CustomerSignIn;
use Juwri\Modules\Messaging\Contracts\SmsOtp;
use Juwri\Modules\Messaging\Data\OtpScope;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\Exception\CounterException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * P1-19 (customer passkeys): storefront customers sign in to ONE store with a passkey, and add / remove passkeys
 * on their account page. Next to the password and phone-code sign-in, which are unchanged.
 *
 *  - Relying party from the host serving the store page (CustomerRelyingParty, J28); other hosts 404.
 *  - Store scope: a sign-in accepts only a passkey registered for the store whose page it is made on, and opens
 *    the same customer-guard session the password sign-in opens (session renewed, guest orders claimed).
 *  - Server-side single-use challenges with a TTL, bound to the store (sign-in) or the customer (enrolment);
 *    user verification required; a sign counter that did not increase is refused and logged.
 *  - Rate limits: customer-passkey limiter on every endpoint + a per-IP lockout after repeated failed sign-ins.
 *  - Adding a passkey re-proves the password when the customer has one. Customers without a password (phone code,
 *    social account) need a fresh proof (review fix — a stolen session must not become persistent access): a
 *    sign-in less than 10 minutes old, or a new code sent to their phone (CustomerSignIn).
 */
class CustomerPasskeyController extends Controller
{
    private const CONFIRMED_AT = 'customer_passkey.confirmed_at';

    public function __construct(private readonly PasskeyCeremonies $ceremonies) {}

    // ── Sign-in ───────────────────────────────────────────────────────────────

    public function loginOptions(Request $request, string $store): JsonResponse
    {
        $store = $this->storeFor($request, $store);

        $options = CustomerPasskeys::requestOptions();
        $this->ceremonies->putChallenge($request, $this->loginCeremony($store), $options, 'store:'.$store->id);

        return new JsonResponse(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function login(Request $request, string $store): JsonResponse
    {
        $store = $this->storeFor($request, $store);

        $failures = 'customer-passkey-login|'.$request->ip();
        $window = (int) config('passkeys.failure_window', 60);
        if (RateLimiter::tooManyAttempts($failures, (int) config('passkeys.max_failures', 10))) {
            event(new Lockout($request));
            $seconds = RateLimiter::availableIn($failures);

            throw ValidationException::withMessages([
                'credential' => [trans('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)])],
            ])->status(Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $credential = $this->ceremonies->parseCredential($request, AuthenticatorAssertionResponse::class);
            /** @var PublicKeyCredentialRequestOptions $options */
            $options = $this->ceremonies->pullChallenge($request, $this->loginCeremony($store), PublicKeyCredentialRequestOptions::class, 'store:'.$store->id);
            $passkey = $this->verify($credential, $options, $store);
        } catch (ValidationException $e) {
            RateLimiter::hit($failures, $window);

            throw $e;
        }

        $customer = Customer::withoutGlobalScope(AccountScope::class)
            ->where('store_id', $store->id)
            ->find($passkey->customer_id);
        if (! $customer instanceof Customer) {
            RateLimiter::hit($failures, $window);

            throw $this->failed();
        }

        // Exactly the password sign-in's session (CustomerAuthController::login).
        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();
        $request->session()->put(self::CONFIRMED_AT, ['customer' => (int) $customer->id, 'at' => now()->getTimestamp()]);
        RateLimiter::clear($failures);

        $claimed = OrderClaimService::claimSessionOrders($customer);
        if ($claimed > 0) {
            $request->session()->flash('gol_claimed_count', $claimed);
        }

        return new JsonResponse([
            'success' => true,
            'message' => ___('Logged in successfully'),
            'claimed_count' => $claimed,
            'reload' => true,
        ]);
    }

    // ── The customer's own passkeys ───────────────────────────────────────────

    public function registerOptions(Request $request, string $store): JsonResponse
    {
        $store = $this->storeFor($request, $store);
        $customer = $this->customer($store);

        if (empty($customer->password)) {
            if (! CustomerSignIn::isFresh($request, $customer)) {
                $code = trim((string) $request->input('code', ''));
                if ($code === '' || empty($customer->phone) || ! app(SmsOtp::class)->verify((string) $customer->phone, $code, OtpScope::store((int) $store->id))) {
                    $message = $code === ''
                        ? ___('For your security, enter a code sent to your phone or sign in again to add a passkey.')
                        : ___('Invalid or expired code');

                    return new JsonResponse([
                        'message' => $message,
                        'errors' => ['code' => [$message]],
                        'reauth' => true,
                    ], Response::HTTP_LOCKED);
                }
                CustomerSignIn::markProved($request, $customer);
            }
        } elseif (! $this->recentlyConfirmed($request, $customer)) {
            $password = (string) $request->input('password', '');
            if ($password === '' || ! Hash::check($password, (string) $customer->password)) {
                $message = ___('Confirm your password to add a passkey.');

                return new JsonResponse(['message' => $message, 'errors' => ['password' => [$message]]], Response::HTTP_LOCKED);
            }
            $request->session()->put(self::CONFIRMED_AT, ['customer' => (int) $customer->id, 'at' => now()->getTimestamp()]);
        }

        $this->belowLimit($customer);

        $rp = CustomerRelyingParty::for($request, $store);
        $options = CustomerPasskeys::registrationOptions($customer, $store, (string) ($rp['name'] ?? ''));
        $this->ceremonies->putChallenge($request, $this->registerCeremony($store), $options, 'customer:'.$customer->id);

        return new JsonResponse(['options' => WebAuthn::toBrowserArray($options)]);
    }

    /** A new sign-in code to the phone of a customer without a password (fresh proof for adding a passkey). */
    public function sendCode(Request $request, string $store): JsonResponse
    {
        $store = $this->storeFor($request, $store);
        $customer = $this->customer($store);
        abort_if(! empty($customer->password) || empty($customer->phone), 404);

        [$ok, $message] = app(SmsOtp::class)->request((string) $customer->phone, OtpScope::store((int) $store->id));

        return new JsonResponse(['success' => $ok, 'message' => $message], $ok ? 200 : 429);
    }

    public function store(Request $request, string $store): JsonResponse
    {
        $store = $this->storeFor($request, $store);
        $customer = $this->customer($store);

        $name = $this->validName($request);
        $credential = $this->ceremonies->parseCredential($request, AuthenticatorAttestationResponse::class);
        $rawId = Base64UrlSafe::encodeUnpadded($credential->rawId);
        if (strlen($rawId) > PasskeyCeremonies::MAX_CREDENTIAL_ID || CustomerPasskeys::credentialTaken($rawId)) {
            throw $this->notAdded();
        }
        /** @var PublicKeyCredentialCreationOptions $options */
        $options = $this->ceremonies->pullChallenge($request, $this->registerCeremony($store), PublicKeyCredentialCreationOptions::class, 'customer:'.$customer->id);

        $this->belowLimit($customer);

        try {
            $passkey = CustomerPasskeys::store($customer, $name, $credential, $options);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::info('[passkeys] customer registration refused: '.$e->getMessage(), ['customer_id' => $customer->id, 'store_id' => $store->id]);

            throw $this->notAdded();
        }

        return new JsonResponse([
            'message' => ___('Passkey added.'),
            'passkey' => ['id' => $passkey->id, 'name' => $passkey->name],
        ], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, string $store, int $passkeyId): RedirectResponse|JsonResponse
    {
        $storeKey = $store;
        $store = $this->storeFor($request, $store, offeredOnly: false);
        $customer = $this->customer($store);

        /** @var CustomerPasskey $passkey */
        $passkey = CustomerPasskeys::of($customer)->whereKey($passkeyId)->firstOrFail();
        $passkey->delete();

        return $request->expectsJson()
            ? new JsonResponse(['message' => ___('Passkey removed.')])
            : redirect()->route('customer.profile', $storeKey)->with('passkey_status', ___('Passkey removed.'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * The store of the page, with laravel/passkeys pointed at this host's relying party. A host that does not
     * serve this store's page (another store's domain, the dashboard host in split mode) is a 404. Removing a
     * passkey works wherever the account page is open (the passkey may belong to another of the store's hosts).
     */
    private function storeFor(Request $request, string $store, bool $offeredOnly = true): Store
    {
        $store = Store::idOrSlug($store)->firstOrFail();
        abort_unless(CustomerRelyingParty::configure($request, $store) || ! $offeredOnly, 404);

        return $store;
    }

    /** The signed-in customer of THIS store (the customer guard is shared by every store on a host). */
    private function customer(Store $store): Customer
    {
        $customer = Auth::guard('customer')->user();
        abort_unless($customer instanceof Customer && (int) $customer->store_id === (int) $store->id, 403);

        return $customer;
    }

    private function recentlyConfirmed(Request $request, Customer $customer): bool
    {
        $mark = $request->session()->get(self::CONFIRMED_AT);
        if (! is_array($mark) || (int) ($mark['customer'] ?? 0) !== (int) $customer->id) {
            return false;
        }

        return (now()->getTimestamp() - (int) ($mark['at'] ?? 0)) < (int) config('auth.password_timeout', 10800);
    }

    private function belowLimit(Customer $customer): void
    {
        if (CustomerPasskeys::of($customer)->count() >= (int) config('passkeys.max_per_user', 20)) {
            throw ValidationException::withMessages(['name' => ___('You have reached the maximum number of passkeys. Remove one first.')]);
        }
    }

    private function loginCeremony(Store $store): string
    {
        return 'customer-login.'.$store->id;
    }

    private function registerCeremony(Store $store): string
    {
        return 'customer-register.'.$store->id;
    }

    private function validName(Request $request): string
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);
        $name = trim(preg_replace('/\s+/u', ' ', (string) $data['name']) ?? '');
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ___('Give the passkey a name.')]);
        }

        return $name;
    }

    private function verify(PublicKeyCredential $credential, PublicKeyCredentialRequestOptions $options, Store $store): CustomerPasskey
    {
        try {
            return CustomerPasskeys::verify($credential, $options, $store);
        } catch (QueryException $e) {
            throw $e;
        } catch (CounterException $e) {
            Log::warning('[passkeys] customer sign counter did not increase; refused (possible cloned authenticator)', [
                'credential_id' => Base64UrlSafe::encodeUnpadded($credential->rawId),
                'store_id' => $store->id,
            ]);

            throw $this->failed();
        } catch (Throwable $e) {
            Log::info('[passkeys] customer assertion refused: '.$e->getMessage(), ['store_id' => $store->id]);

            throw $this->failed();
        }
    }

    private function failed(): ValidationException
    {
        return ValidationException::withMessages(['credential' => ___('This passkey could not be verified. Try again or sign in with your password.')]);
    }

    private function notAdded(): ValidationException
    {
        return ValidationException::withMessages(['credential' => ___('This passkey could not be added. Please try again.')]);
    }
}

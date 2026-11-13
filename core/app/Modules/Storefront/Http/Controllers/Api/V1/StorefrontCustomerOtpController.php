<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers\Api\V1;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Customers\Models\CustomerToken;
use Juwri\Modules\Messaging\Contracts\Sms;
use Juwri\Modules\Messaging\Contracts\SmsOtp;
use Juwri\Modules\Messaging\Data\OtpScope;
use Juwri\Modules\Storefront\Services\Api\StorefrontApiContext;
use Juwri\Modules\Storefront\Services\Api\StorefrontApiException;
use Juwri\Modules\Storefront\Services\Api\StorefrontIdempotency;
use Juwri\Modules\Storefront\Services\Api\StorefrontOrderPlacement;
use Symfony\Component\HttpFoundation\Response;

/**
 * P3-06b (kit/api README §2.6, API-14, API-24c) — customer phone OTP for headless clients, keyed per store:
 * `POST /api/v1/storefront/customer/otp/request` and `…/verify`, storefront token with `order:write` (Origin
 * allowlist), an `Idempotency-Key`, the store from the token only.
 *
 *  - Codes and limits are the store's (OtpScope::store): a code sent for store A never verifies at store B, and
 *    requests at A never throttle B (SmsOtpService). Over a limit: 429 `rate_limited` with Retry-After.
 *  - No account enumeration: request answers 202 with the same body for every well-formed phone (also when the text
 *    could not be sent); verify finds or creates the customer by phone, so a new and an existing phone answer the
 *    same; a wrong, used or expired code is 422 `otp_invalid` for every phone.
 *  - Verify returns a customer token of THAT store with `phone_verified_at` set.
 *  - Idempotency (StorefrontIdempotency): a retry with the same key and body gets the first answer again — no
 *    second text, the same token.
 */
class StorefrontCustomerOtpController extends Controller
{
    private const CODE_TTL = 600;

    public function __construct(private readonly StorefrontIdempotency $idempotency) {}

    public function request(Request $request): Response
    {
        $ctx = StorefrontApiContext::fromRequest($request);
        $key = StorefrontOrderPlacement::idempotencyKey($request);
        $body = $this->validated($request, ['phone' => ['required', 'string', 'max:20']]);
        $phone = $this->phone((string) $body['phone']);

        return $this->idempotency->run($ctx, 'customer_otp_request', $key, StorefrontOrderPlacement::requestHash(['phone' => $phone]), self::CODE_TTL, function () use ($ctx, $phone) {
            $result = app(SmsOtp::class)->request($phone, OtpScope::store((int) $ctx->store->id));
            if (! $result[0] && isset($result[2])) {
                throw new StorefrontApiException(429, 'rate_limited', ___('Too many requests. Retry after :seconds seconds.', ['seconds' => (string) $result[2]]), null, null, ['Retry-After' => (string) $result[2]]);
            }

            return response()->json([
                'message' => ___('If this number can get texts, we sent it a 6-digit code.'),
                'expires_in_seconds' => self::CODE_TTL,
            ], 202, ['Cache-Control' => 'no-store']);
        });
    }

    public function verify(Request $request): Response
    {
        $ctx = StorefrontApiContext::fromRequest($request);
        $key = StorefrontOrderPlacement::idempotencyKey($request);
        $body = $this->validated($request, [
            'phone' => ['required', 'string', 'max:20'],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
            'device' => ['sometimes', 'string', 'max:80'],
        ]);
        $phone = $this->phone((string) $body['phone']);
        $hash = StorefrontOrderPlacement::requestHash(['phone' => $phone, 'code' => (string) $body['code'], 'device' => $body['device'] ?? null]);

        return $this->idempotency->run($ctx, 'customer_otp_verify', $key, $hash, self::CODE_TTL, function () use ($ctx, $phone, $body) {
            $storeId = (int) $ctx->store->id;
            if (! app(SmsOtp::class)->verify($phone, (string) $body['code'], OtpScope::store($storeId))) {
                throw new StorefrontApiException(422, 'otp_invalid', ___('The code is wrong or has expired. Ask for a new code.'));
            }

            $customer = Customer::query()->firstOrCreate(
                ['store_id' => $storeId, 'phone' => $phone],
                ['name' => 'Guest', 'source' => 'storefront_api_otp']
            );
            $customer->forceFill(['phone_verified_at' => now()])->save();
            $raw = CustomerToken::issue($customer, isset($body['device']) ? (string) $body['device'] : 'storefront-api');
            $tz = store_tz($storeId);
            $placeholder = in_array((string) $customer->source, ['customer_app_otp', 'storefront_api_otp'], true) && $customer->name === 'Guest';

            return response()->json([
                'token' => $raw,
                'token_type' => 'Bearer',
                'expires_at' => null,
                'customer' => [
                    'public_id' => (string) $customer->getAttribute('public_id'),
                    'name' => $placeholder ? null : $customer->name,
                    'phone' => (string) $customer->phone,
                    'phone_verified_at' => now()->setTimezone($tz)->toIso8601String(),
                ],
            ], 200, ['Cache-Control' => 'no-store']);
        });
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $rules): array
    {
        $body = (array) $request->json()->all();
        $validator = Validator::make($body, $rules);
        if ($validator->fails()) {
            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();
            throw StorefrontApiException::validation($errors);
        }

        return $validator->validated();
    }

    /** @throws StorefrontApiException */
    private function phone(string $raw): string
    {
        $phone = app(Sms::class)->normalizePhone($raw);
        if ($phone === '') {
            throw StorefrontApiException::validation(['phone' => [___('Enter a valid phone number.')]]);
        }

        return $phone;
    }
}

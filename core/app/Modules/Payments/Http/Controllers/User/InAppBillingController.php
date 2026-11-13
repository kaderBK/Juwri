<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User;

use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Contracts\Billing\PlanCatalog;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Contracts\GooglePlayBilling;
use Juwri\Modules\Payments\Models\InAppPurchase;
use Juwri\Modules\Payments\Services\InApp\InAppBilling;
use Juwri\Modules\Payments\Services\InApp\InAppRejected;
use RuntimeException;

/**
 * P5-17: the owner app's in-app billing endpoints (signed-in owner) and the stores' server notifications (public,
 * verified by signature / OIDC token). Every route answers 404 while its store is switched off (inapp.*.enabled).
 */
class InAppBillingController extends Controller
{
    public function __construct(private readonly InAppBilling $billing) {}

    private function owner(Request $request, string $platform): User
    {
        abort_unless(InAppBilling::enabled($platform), 404);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** The token the app passes to StoreKit (appAccountToken) / Play Billing (obfuscatedAccountId) before a purchase. */
    public function accountToken(Request $request): JsonResponse
    {
        abort_unless(InAppBilling::enabled(InAppBilling::APPLE) || InAppBilling::enabled(InAppBilling::GOOGLE), 404);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json(['account_token' => $this->billing->accountToken($user)])->header('Cache-Control', 'no-store, private');
    }

    /**
     * P5-33: what the owner-app billing screen (P5-22) renders — the stores that are on, the products they sell (joined
     * to the platform plan) and the signed-in owner's latest in-app purchase.
     */
    public function overview(Request $request, PlanCatalog $catalog): JsonResponse
    {
        $apple = InAppBilling::enabled(InAppBilling::APPLE);
        $google = InAppBilling::enabled(InAppBilling::GOOGLE);
        abort_unless($apple || $google, 404);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $names = [];
        $planName = function (mixed $planId) use ($catalog, &$names): ?string {
            $id = (int) $planId;
            if ($id <= 0) {
                return null;
            }
            if (! array_key_exists($id, $names)) {
                $plan = $catalog->find($id);
                $names[$id] = $plan !== null ? (string) $plan->getAttribute('name') : null;
            }

            return $names[$id];
        };

        $plans = [];
        foreach (['apple' => [$apple, 'inapp.apple.products'], 'google' => [$google, 'inapp.google.products']] as $store => [$on, $key]) {
            if (! $on) {
                continue;
            }
            foreach ((array) config($key) as $productId => $product) {
                if (! is_array($product)) {
                    continue;
                }
                $name = $planName($product['plan_id'] ?? null);
                $interval = $product['interval'] ?? null;
                $amount = $product['amount'] ?? null;
                $currency = $product['currency'] ?? null;
                if ($name === null || ! in_array($interval, ['MONTHLY', 'ANNUAL'], true)
                    || ! is_scalar($amount) || (string) $amount === '' || ! is_string($currency) || $currency === '') {
                    continue; // never half a row
                }
                $plans[] = ['product_id' => (string) $productId, 'store' => $store, 'plan_id' => (int) $product['plan_id'], 'name' => $name,
                    'interval' => $interval, 'price' => (string) $amount, 'currency' => $currency];
            }
        }

        // Tenancy: the owner's own rows only (user_id) and the account scope stays on.
        $row = InAppPurchase::query()->where('user_id', $user->id)
            ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')->orderByDesc('expires_at')->orderByDesc('id')->first(); // NULLs last on every DB (PG sorts them first on DESC)
        $status = $row === null ? null : [
            'state' => (string) $row->state,
            'plan_id' => $row->plan_id !== null ? (int) $row->plan_id : null,
            'plan_name' => $planName($row->plan_id),
            'expires_at' => $row->expires_at?->utc()->toIso8601String(),
            'store' => (string) $row->platform,
        ];

        return response()->json(['stores' => ['apple' => $apple, 'google' => $google], 'plans' => $plans, 'status' => $status])
            ->header('Cache-Control', 'no-store, private');
    }

    public function verifyApple(Request $request): JsonResponse
    {
        $user = $this->owner($request, InAppBilling::APPLE);
        $data = $this->input($request, ['signed_transaction' => ['required', 'string', 'max:20000']]);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        return $this->answer(fn () => $this->billing->linkApple($user, $data['signed_transaction']));
    }

    public function verifyGoogle(Request $request): JsonResponse
    {
        $user = $this->owner($request, InAppBilling::GOOGLE);
        $data = $this->input($request, ['product_id' => ['required', 'string', 'max:191'], 'purchase_token' => ['required', 'string', 'max:2000']]);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        return $this->answer(fn () => $this->billing->linkGoogle($user, $data['product_id'], $data['purchase_token']));
    }

    /** App Store Server Notifications V2: {signedPayload}. */
    public function appleNotification(Request $request): JsonResponse
    {
        abort_unless(InAppBilling::enabled(InAppBilling::APPLE), 404);
        $payload = $request->input('signedPayload');
        if (! is_string($payload) || $payload === '' || strlen($payload) > 60000) {
            return response()->json(['code' => 'invalid_payload'], 400);
        }

        return $this->answer(fn () => ['ok' => $this->billing->appleNotification($payload)]);
    }

    /** Google RTDN through a Pub/Sub push subscription with an OIDC token. */
    public function googleNotification(Request $request, GooglePlayBilling $google): JsonResponse
    {
        abort_unless(InAppBilling::enabled(InAppBilling::GOOGLE), 404);
        if (! $google->verifyPushToken((string) $request->bearerToken())) {
            return response()->json(['code' => 'unauthenticated'], 401);
        }
        $message = $request->input('message');
        if (! is_array($message)) {
            return response()->json(['code' => 'invalid_payload'], 400);
        }

        return $this->answer(fn () => ['ok' => $this->billing->googleNotification($message)]);
    }

    /** @param  \Closure(): array<string, mixed>  $run */
    /**
     * P6-34b: validate with the same JSON error envelope as the rest of the owner-app API (message + code + errors).
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>|JsonResponse
     */
    private function input(Request $request, array $rules): array|JsonResponse
    {
        try {
            return $request->validate($rules);
        } catch (ValidationException $e) {
            return ApiError::response(422, 'validation_failed', $e->getMessage(), $e->errors());
        }
    }

    private function answer(\Closure $run): JsonResponse
    {
        try {
            return response()->json($run())->header('Cache-Control', 'no-store, private');
        } catch (InAppRejected $e) {
            return response()->json(['code' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            report($e);

            return response()->json(['code' => 'store_unavailable'], 503); // the store retries; the app tries again
        }
    }
}

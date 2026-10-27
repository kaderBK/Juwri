<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Http\Controllers\Concerns;

use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Juwri\Modules\Customers\Services\Privacy\ShopperPrivacyService;
use Juwri\Modules\Customers\Support\ShopperReauth;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * P6-91: "Download my data" / "Delete my account" for the storefront (web) and the customer app (API) — one
 * implementation, so both channels enforce the same re-authentication, rate limit and confirmation.
 */
trait HandlesShopperPrivacy
{
    /** The word a shopper types to confirm the deletion (shown in the form, never translated: one rule everywhere). */
    public const CONFIRM_WORD = 'DELETE';

    protected function privacyCode(Customer $customer): JsonResponse
    {
        [$ok, $message] = ShopperReauth::sendCode($customer);

        return new JsonResponse(['success' => $ok, 'message' => $message], $ok ? 200 : 422);
    }

    protected function privacyExport(Request $request, Customer $customer, bool $web): Response
    {
        $format = $request->input('format', 'json') === 'csv' ? 'csv' : 'json';
        if (($error = ShopperReauth::check($request, $customer, $web)) !== null) {
            return new JsonResponse(['success' => false, 'message' => $error, 'reauth' => true], Response::HTTP_LOCKED);
        }
        $key = 'shopper-export:'.$customer->id;
        if (RateLimiter::tooManyAttempts($key, ShopperPrivacyService::EXPORTS_PER_DAY)) {
            return new JsonResponse(['success' => false, 'message' => ___('You can download your data 3 times a day. Please try again tomorrow.')], Response::HTTP_TOO_MANY_REQUESTS);
        }
        RateLimiter::hit($key, 86400);
        $service = app(ShopperPrivacyService::class);
        $service->logExport($customer, $format, $web ? 'web' : 'app');
        $name = 'my-data-store-'.$customer->store_id.'-'.now()->format('Ymd');

        return new StreamedResponse(function () use ($service, $customer, $format): void {
            $out = fopen('php://output', 'wb');
            $format === 'csv' ? $service->writeOrdersCsv($customer, $out) : $service->writeJson($customer, $out);
            fclose($out);
        }, 200, [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/json',
            'Content-Disposition' => 'attachment; filename="'.$name.($format === 'csv' ? '-orders.csv' : '.json').'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    protected function privacyDelete(Request $request, Customer $customer, bool $web): JsonResponse
    {
        if (trim((string) $request->input('confirm', '')) !== self::CONFIRM_WORD) {
            $message = ___('Type :word to confirm.', ['word' => self::CONFIRM_WORD]);

            return new JsonResponse(['success' => false, 'message' => $message, 'errors' => ['confirm' => [$message]]], 422);
        }
        if (($error = ShopperReauth::check($request, $customer, $web)) !== null) {
            return new JsonResponse(['success' => false, 'message' => $error, 'reauth' => true], Response::HTTP_LOCKED);
        }
        $service = app(ShopperPrivacyService::class);
        if (($blocker = $service->blocker($customer)) !== null) {
            return new JsonResponse(['success' => false, 'message' => $blocker], 409);
        }
        $service->erase($customer, $web ? 'web' : 'app');

        return new JsonResponse(['success' => true, 'message' => ___('Your account has been deleted.')]);
    }
}

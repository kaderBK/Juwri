<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Http\Controllers\User;

use App\Services\Entitlements;
use App\Services\StoreCloneService;
use App\Services\Verticals\VerticalRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Juwri\Core\Editions\Limits\StoreLimitGuard;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Verticals\Services\StoreVerticalConversionService;

/**
 * Store vertical CONVERT (in place) + DUPLICATE-as-a-different-vertical.
 *
 * A NEW, small controller rather than growing StoreController (already up
 * against its ControllerSizeGuard ceiling — see cloneRestaurant() there for
 * the sibling same-type clone action this pairs with). All guard/transaction
 * logic lives in StoreVerticalConversionService (convert) and StoreCloneService
 * (duplicate's deep copy); this controller is auth/ownership/validation/response
 * only, mirroring the authorizeOwner() pattern used by the other per-store
 * settings controllers (WhatsAppSettingsController, MarketplaceSettingsController, …).
 */
class StoreVerticalController extends Controller
{
    /**
     * In-place convert: swap $store's store_type (same-group is an instant
     * swap; cross-group also re-picks the storefront template and re-heals
     * fulfillment modes). See StoreVerticalConversionService::convert().
     */
    public function convert(Request $request, Store $store): RedirectResponse|JsonResponse
    {
        $this->authorizeOwner($store);

        $validator = Validator::make($request->all(), [
            'store_type' => ['required', 'string', Rule::in(array_keys((array) config('verticals.types', [])))],
            'store_template' => ['nullable', 'string'],
        ]);
        if ($validator->fails()) {
            return $this->fail($request, implode(' ', $validator->errors()->all()));
        }

        // Edition limits: switching to a type the business already has is one more store of that type.
        if (StoreLimitGuard::normaliseType((string) $request->store_type) !== StoreLimitGuard::normaliseType($store->store_type)) {
            $edition = app(StoreLimitGuard::class)->checkNewStore($store->account_id ? (int) $store->account_id : null, (string) $request->store_type, (int) $store->id);
            if ($edition->refused()) {
                return $this->fail($request, $edition->message(), 422);
            }
        }

        $result = app(StoreVerticalConversionService::class)->convert(
            $store,
            $request->user(),
            (string) $request->store_type,
            $request->filled('store_template') ? (string) $request->store_template : null
        );

        if ($result['error'] !== null) {
            return $this->fail($request, $result['error']);
        }

        $message = ___(':noun type updated successfully.', ['noun' => store_noun($store, 'title')]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'id' => $store->id,
                'store_type' => $store->store_type,
                'same_group' => $result['sameGroup'],
                'warnings' => $result['warnings'],
                'message' => $message,
            ]);
        }

        app_alert_success($message);

        return back();
    }

    /**
     * Duplicate $store as a NEW store, optionally under a different
     * vertical. The source store is never touched. Shares the same
     * entitlement/hotel guard as convert() (StoreVerticalConversionService::
     * guardTargetType()) and the same restaurant-slot quota check
     * StoreController::cloneRestaurant() enforces for a same-type clone.
     */
    public function duplicateVertical(Request $request, Store $store): RedirectResponse|JsonResponse
    {
        $this->authorizeOwner($store);

        $validator = Validator::make($request->all(), [
            'store_type' => ['nullable', 'string', Rule::in(array_keys((array) config('verticals.types', [])))],
            'store_template' => ['nullable', 'string'],
        ]);
        if ($validator->fails()) {
            return $this->fail($request, implode(' ', $validator->errors()->all()));
        }

        $targetType = $request->filled('store_type')
            ? (string) $request->store_type
            : (string) ($store->store_type ?: app(VerticalRegistry::class)->default());

        $conversion = app(StoreVerticalConversionService::class);
        if ($error = $conversion->guardTargetType($store, $request->user(), $targetType)) {
            return $this->fail($request, $error);
        }

        // A duplicate consumes a restaurant slot like any other create — same
        // rule StoreController::cloneRestaurant() enforces for a same-type clone.
        $limit = effective_limit('restaurant');
        if (! Entitlements::isUnlimitedSentinel($limit) && Entitlements::usage($request->user(), 'restaurant') >= $limit) {
            return $this->fail($request, ___('Limit exceeded, please upgrade your membership.'), 422);
        }

        // Edition limits (Community: one store of each store type per business).
        $edition = app(StoreLimitGuard::class)->checkNewStore($store->account_id ? (int) $store->account_id : null, $targetType);
        if ($edition->refused()) {
            return $this->fail($request, $edition->message(), 422);
        }

        ['clone' => $clone, 'counts' => $counts] = app(StoreCloneService::class)->clone(
            $store,
            $targetType,
            $request->filled('store_template') ? (string) $request->store_template : null
        );

        log_activity('store.duplicate_vertical', $clone, [
            'cloned_from' => $store->id,
            'source_title' => $store->title,
            'source_type' => $store->store_type,
            'target_type' => $clone->store_type,
        ], $clone->id);

        $message = ___(':source duplicated as a new :type.', [
            'source' => store_noun($store, 'title'),
            'type' => ___((string) app(VerticalRegistry::class)->capability($targetType, 'label', $targetType)),
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'id' => $clone->id,
                'title' => $clone->title,
                'store_type' => $clone->store_type,
                'counts' => $counts,
                'message' => $message,
            ]);
        }

        app_alert_success($message);

        return back();
    }

    /**
     * @return JsonResponse|RedirectResponse
     */
    private function fail(Request $request, string $message, int $status = 422)
    {
        if ($request->wantsJson()) {
            return response()->json(['success' => false, 'error' => $message], $status);
        }

        app_alert_error($message);

        return back()->withInput();
    }

    private function authorizeOwner(Store $store): void
    {
        abort_unless(auth()->check() && (int) $store->user_id === (int) auth()->id(), 403);
    }
}

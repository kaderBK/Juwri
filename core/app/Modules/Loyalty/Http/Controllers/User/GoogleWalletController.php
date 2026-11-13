<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Http\Controllers\User;

use App\Models\GiftCard;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\StoreOption;
use App\Services\GoogleWallet\GoogleWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;

/**
 * google-suite Lane C — mints the "Save to Google Wallet" save-link URL for a
 * logged-in customer, on demand. The JWT is never embedded in the storefront HTML;
 * the badge button fetches it here so it is minted only for an authenticated
 * customer and the customer guard + ownership checks apply.
 *
 * Every path returns JSON. A disabled store / missing program / unsigned JWT returns
 * a 404 `{enabled:false}` (the button simply hides), never a 500.
 */
class GoogleWalletController extends Controller
{
    /** Save URL for the authed customer's loyalty card at this store. */
    public function loyaltyJwt(int $storeId): JsonResponse
    {
        $customer = auth('customer')->user();
        if (! $customer) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $store = Store::find($storeId);
        if (! $store || ! GoogleWalletService::enabledForStore($store)) {
            return response()->json(['enabled' => false], 404);
        }
        // Customers belong to one store: never mint a pass for another store's program.
        if ((int) $customer->store_id !== (int) $store->id) {
            return response()->json(['enabled' => false], 404);
        }

        $program = LoyaltyProgram::where('store_id', $storeId)->where('active', true)->first();
        if (! $program) {
            return response()->json(['enabled' => false], 404);
        }

        $account = LoyaltyAccount::forCustomer((int) $customer->id, (int) $storeId);
        $url = GoogleWalletService::loyaltySaveUrl($store, $program, $account, $customer);
        if (! $url) {
            return response()->json(['enabled' => false], 404);
        }

        return response()->json(['save_url' => $url]);
    }

    /**
     * Save URL for a gift card the authed customer OWNS (purchased). A card the
     * customer did not purchase → 403 (cross-customer guard). Recipient-by-email
     * cards have no customer link and are reached from the email, not here.
     */
    public function giftCardJwt(int $storeId, int $giftCardId): JsonResponse
    {
        $customer = auth('customer')->user();
        if (! $customer) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $store = Store::find($storeId);
        if (! $store || ! GoogleWalletService::enabledForStore($store)) {
            return response()->json(['enabled' => false], 404);
        }

        $card = GiftCard::find($giftCardId);
        if (! $card || (int) $card->store_id !== (int) $storeId) {
            return response()->json(['error' => 'not_found'], 404);
        }
        if ((int) ($card->purchaser_customer_id ?? 0) !== (int) $customer->id) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $url = GoogleWalletService::giftCardSaveUrl($store, $card);
        if (! $url) {
            return response()->json(['enabled' => false], 404);
        }

        return response()->json(['save_url' => $url]);
    }

    /**
     * Owner (dashboard) — flip the per-store `google_wallet_enabled` toggle. Tenant
     * ownership is enforced here (the store must belong to the authed owner). The
     * value is stored as a STRING '1'/'0' (store_options contract).
     */
    public function updateStoreToggle(Request $request, int $storeId): RedirectResponse
    {
        $store = Store::where('id', $storeId)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        StoreOption::updateStoreOption(
            $store->id,
            'google_wallet_enabled',
            $request->boolean('google_wallet_enabled') ? '1' : '0'
        );

        // biolink-tpl lane-d (T3-1): opt-in to a bio-page link on saved passes. The
        // mapper double-gates on bio liveness, so this only takes effect while the
        // bio page is live; storing '0' when dark keeps the pass link-free (today).
        StoreOption::updateStoreOption(
            $store->id,
            'wallet_bio_link',
            $request->boolean('wallet_bio_link') ? '1' : '0'
        );

        return back()->with('success', ___('Google Wallet setting saved.'));
    }
}

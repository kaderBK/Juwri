<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Http\Controllers\User;

use App\Models\GiftCard;
use App\Services\GiftCardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;

/**
 * T2-P4 owner gift-card management: list issued cards + balances, manual issue/void/
 * adjust (audited), and liability/redemption reports. Tenant-scoped to the owner's
 * restaurant(s) — a cross-tenant card id resolves to 404.
 */
class GiftCardAdminController extends Controller
{
    private function storeIds(): array
    {
        return dashboard_store_ids();
    }

    private function currentRestaurantId(Request $request): int
    {
        $ids = $this->storeIds();
        $rid = (int) ($request->query('store_id') ?: (dashboard_current_store()->id ?? $ids[0] ?? 0));
        abort_unless(in_array($rid, $ids, true), 403);

        return $rid;
    }

    private function ownedCard(int $id): GiftCard
    {
        return GiftCard::whereIn('store_id', $this->storeIds())->findOrFail($id);
    }

    public function index(Request $request)
    {
        $rid = $this->currentRestaurantId($request);

        // C5: optional status filter so owners can see lapsed (expired) cards.
        $status = (string) $request->query('status', '');
        $cards = GiftCard::where('store_id', $rid)
            ->when(in_array($status, ['active', 'expired', 'disabled'], true), fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        $stats = [
            'liability' => GiftCardService::liabilityFor($rid),
            'redeemed' => GiftCardService::redeemedTotalFor($rid),
            'active' => GiftCard::where('store_id', $rid)->where('status', 'active')->count(),
        ];

        return view('owner.marketing.giftcards', [
            'cards' => $cards,
            'stats' => $stats,
            'rid' => $rid,
            'status' => $status,
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function issue(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:100000'],
            'recipient_email' => ['nullable', 'email', 'max:191'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $card = GiftCardService::issueManual($rid, (float) $data['amount'], [
            'recipient_email' => $data['recipient_email'] ?? null,
            'message' => $data['message'] ?? null,
        ]);
        if ($card->recipient_email) {
            GiftCardService::deliver($card->fresh());
        }
        log_activity('giftcard.issued', null, ['gift_card_id' => $card->id, 'amount' => $data['amount']]);

        app_alert_success(___('Gift card issued').': '.$card->code);

        return back();
    }

    public function void(Request $request, int $id)
    {
        $card = $this->ownedCard($id);
        GiftCardService::void($card);
        log_activity('giftcard.voided', null, ['gift_card_id' => $card->id]);
        app_alert_success(___('Gift card voided.'));

        return back();
    }

    public function adjust(Request $request, int $id)
    {
        $card = $this->ownedCard($id);
        $data = $request->validate([
            'delta' => ['required', 'numeric'],
            'reason' => ['nullable', 'string', 'max:191'],
            // B1: a per-form-render nonce so a double-clicked adjustment applies once.
            'idempotency_token' => ['nullable', 'string', 'max:191'],
        ]);
        GiftCardService::adjust($card, (float) $data['delta'], $data['reason'] ?? 'manual', $data['idempotency_token'] ?? null);
        log_activity('giftcard.adjusted', null, ['gift_card_id' => $card->id, 'delta' => $data['delta']]);
        app_alert_success(___('Balance adjusted.'));

        return back();
    }

    /**
     * P6-214: owner-initiated resend. Resets the retry bookkeeping (attempts/backoff/
     * failed_at) so a fresh burst of MAX attempts is available, then tries once
     * immediately. A still-failing address comes back as a quick-alert error and the
     * card's delivery_failed_at is set again on this same call.
     */
    public function resend(Request $request, int $id): RedirectResponse
    {
        $card = $this->ownedCard($id);
        if ($card->status !== 'active') {
            app_alert_error(___('That gift card is not active.'));

            return back();
        }
        if (! $card->recipient_email) {
            app_alert_error(___('That gift card has no recipient email to send to.'));

            return back();
        }

        $delivered = GiftCardService::resend($card);
        log_activity('giftcard.resent', null, ['gift_card_id' => $card->id, 'delivered' => $delivered]);

        if ($delivered) {
            app_alert_success(___('Gift card resent.').' #'.$card->id);
        } else {
            app_alert_error(___('Gift card not delivered - check the address / resend').' #'.$card->id);
        }

        return back();
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Jobs;

use App\Models\Customer;
use App\Models\GiftCard;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Services\GoogleWallet\GoogleWalletService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Store;
use Juwri\Modules\Loyalty\Services\GoogleWallet\GiftCardPassMapper;
use Juwri\Modules\Loyalty\Services\GoogleWallet\LoyaltyPassMapper;

/**
 * Push a fresh balance to a customer's saved Google Wallet pass (loyalty or gift
 * card) via a REST PATCH. Queued and FAIL-SOFT: nothing here may ever break the
 * checkout / loyalty-accrual / gift-card path that dispatched it. Any failure —
 * disabled store, missing creds, network error, 404 (pass not saved yet) — is
 * logged and swallowed.
 *
 * Idempotent by design: the job re-reads LIVE balance at run time (not a captured
 * value) and PATCHes it, so a re-run or a slightly-early dispatch always writes the
 * correct current state. The pass OBJECT itself is created by the fat save-link JWT
 * when the customer first taps "Save to Google Wallet" (GoogleWalletService::*SaveUrl);
 * this job only keeps an already-saved pass current, so a PATCH that 404s (never
 * saved) is a harmless no-op.
 *
 * `$type` = 'loyalty' | 'giftcard'. `$subjectId` = customer id (loyalty) or gift-card
 * id (giftcard) — the pass subject. Only ids are serialized (no model snapshots) so
 * the balance is always read live.
 */
class SyncGoogleWalletPass implements ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;

    public int $tries = 3;

    /** P6-64: below queue retry_after. */
    public int $timeout = 120;

    /** @var int[] */
    public array $backoff = [30, 120, 600];

    public function __construct(
        public string $type,
        public int $storeId,
        public int $subjectId,
    ) {}

    public function handle(): void
    {
        try {
            if (! GoogleWalletService::enabled()) {
                return;
            }
            $store = Store::find($this->storeId);
            if (! $store || ! GoogleWalletService::enabledForStore($store)) {
                return;
            }

            if ($this->type === 'loyalty') {
                $this->syncLoyalty($store);
            } elseif ($this->type === 'giftcard') {
                $this->syncGiftCard($store);
            }
        } catch (\Throwable $e) {
            // Fail-soft: a pass sync must never surface. Logged for the owner to see.
            Log::warning('Google Wallet pass sync failed: '.$e->getMessage());
        }
    }

    private function syncLoyalty(Store $store): void
    {
        $program = LoyaltyProgram::where('store_id', $this->storeId)->where('active', true)->first();
        if (! $program) {
            return;
        }
        $account = LoyaltyAccount::where('customer_id', $this->subjectId)
            ->where('store_id', $this->storeId)->first();
        if (! $account) {
            return;
        }
        $customer = Customer::find($this->subjectId);

        GoogleWalletService::patchObject(
            'loyaltyObject',
            LoyaltyPassMapper::objectId($store, $this->subjectId),
            LoyaltyPassMapper::object($store, $program, $account, $customer)
        );
    }

    private function syncGiftCard(Store $store): void
    {
        $card = GiftCard::find($this->subjectId);
        if (! $card || (int) $card->store_id !== $this->storeId) {
            return;
        }

        GoogleWalletService::patchObject(
            'giftCardObject',
            GiftCardPassMapper::objectId($store, $this->subjectId),
            GiftCardPassMapper::object($store, $card)
        );
    }
}

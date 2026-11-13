<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Money\Money;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\GatewayRounding;
use Juwri\Modules\Payments\Support\Fulfilment;
use Symfony\Component\HttpFoundation\Response;

/**
 * BL-038 P1 (ops): release a recurring charge held for review (RenewalAmountHold, status `review`) once it was checked
 * at the gateway — the payment settles through the normal settlement, as if its webhook had been accepted, and the
 * review row is stamped `details.amount_review.released_at`. The review row itself stays (it is never a payment).
 * A charge below the plan price is refused unless `--accept-amount` is given: it is then booked at the amount the
 * gateway actually collected (logged with the operator), never at the plan price.
 */
class SettleHeldRenewal extends Command
{
    protected $signature = 'payments:settle-held-renewal {transaction : the review transaction id}
        {--accept-amount : settle a charge below the plan price, booking the amount the gateway actually collected}';

    protected $description = 'Settle a recurring charge held for review (amount or currency mismatch), after checking it at the gateway';

    public function handle(): int
    {
        $review = Transaction::query()->withoutGlobalScopes()->whereKey((int) $this->argument('transaction'))
            ->where('status', Transaction::STATUS_REVIEW)->first();
        $evidence = (array) data_get($review?->details, 'amount_review', []);
        $metadata = data_get($review?->details, 'amount_review.metadata');
        if ($review === null || ! is_scalar($evidence['payment_id'] ?? null) || $metadata === null) {
            $this->error('No held recurring charge with that id.');

            return self::FAILURE;
        }
        if (isset($evidence['released_at'])) {
            $this->info('Already released at '.$evidence['released_at'].'.');

            return self::SUCCESS;
        }
        $metadata = json_decode((string) json_encode($metadata));
        $gateway = (string) $review->transaction_gatway;
        $currency = strtoupper((string) ($metadata->currency_code ?? '')) ?: strtoupper((string) ($evidence['paid_currency'] ?? ''));
        // What the gateway collected for the plan (before tax and customer balance), as the hold recorded it.
        $collected = $evidence['collected_minor'] ?? $evidence['total_excluding_tax'] ?? $evidence['subtotal'] ?? $evidence['paid_minor'] ?? null;
        $expected = $evidence['expected_minor'] ?? null;
        if (! is_numeric($collected) || $currency === '') {
            $this->error('The held charge records no collected amount; settle it by hand.');

            return self::FAILURE;
        }
        if (! is_numeric($expected) || (int) $collected < (int) $expected) {
            if (! $this->option('accept-amount')) {
                $this->error('The gateway collected '.(int) $collected.' (minor units) of the expected '.(is_numeric($expected) ? (int) $expected : '?')
                    .'. Re-run with --accept-amount to settle it at the amount collected.');

                return self::FAILURE;
            }
            // Book what was actually collected, never the plan price.
            $metadata->amount = $metadata->base_amount = Money::ofMinor((int) $collected, $currency)->major();
            Log::warning('[payment-amount] held recurring charge released below the plan price', [
                'review_transaction' => $review->id, 'payment_id' => $evidence['payment_id'], 'collected_minor' => (int) $collected,
                'expected_minor' => is_numeric($expected) ? (int) $expected : null, 'currency' => $currency,
                'operator' => get_current_user(), 'host' => gethostname(),
            ]);
        }
        $response = Fulfilment::webhook($gateway, $metadata, (string) $evidence['payment_id'],
            is_scalar($evidence['subscription_id'] ?? null) ? (string) $evidence['subscription_id'] : null);
        $status = $response instanceof Response ? $response->getStatusCode() : 0;
        if ($status !== 200) {
            $this->error('The settlement answered '.$status.'; nothing released.');

            return self::FAILURE;
        }
        // As the normal settlement does: book the gateway's rounding of a 3-decimal currency on the settled payment.
        $settled = Transaction::query()->withoutGlobalScopes()->where('transaction_gatway', $gateway)
            ->where('payment_id', (string) $evidence['payment_id'])->first();
        if ($settled !== null) {
            GatewayRounding::book($settled, $gateway);
        }
        $details = (array) json_decode((string) json_encode($review->details), true);
        $details['amount_review']['released_at'] = now()->toIso8601String();
        $details['amount_review']['released_amount_minor'] = (int) $collected;
        $review->forceFill(['details' => $details])->save();
        $this->info('Settled payment '.$evidence['payment_id'].'.');

        return self::SUCCESS;
    }
}

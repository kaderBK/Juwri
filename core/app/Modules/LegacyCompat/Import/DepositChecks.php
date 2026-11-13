<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

/**
 * J59g held deposits (like the loyalty and gift-card checks): before a store is written, every reservation / catering
 * deposit the import will carry as held (paid, or owed back) must reconcile with its source payment — a deposit
 * payment of the right kind, paid, of the store's owner, imported into this install, in the deposit's currency and for
 * exactly the deposit amount (minor units). Any mismatch blocks the store (nothing written, reported with the row ids,
 * retried by a later run): customer money is never carried on a guess.
 */
final class DepositChecks
{
    public const REASON = 'deposit does not reconcile with its payment (J59g)';

    /** Source table => the payment method its deposits are paid with. */
    public const METHODS = ['reservations' => 'reservation_deposit', 'catering_requests' => 'catering_deposit'];

    /** Payment statuses of a completed payment. */
    private const PAID = ['success', 'consumed'];

    public function __construct(private readonly ImportContext $ctx) {}

    /**
     * Held deposits of the store that do not reconcile ("table#id"), checked on the source.
     *
     * @return list<string>
     */
    public function sourceMismatches(int $storeId, int $ownerId): array
    {
        $source = $this->ctx->source;
        $bad = [];
        foreach (self::METHODS as $table => $method) {
            $column = $source->has($table) ? $source->pick($table, LegacyTableMaps::STORE_KEYS) : null;
            if ($column === null || ! in_array('deposit_status', $source->columns($table), true)) {
                continue;
            }
            $source->chunk($table, function (array $rows) use (&$bad, $table, $method, $ownerId, $storeId): void {
                foreach ($rows as $row) {
                    if (! in_array($row['deposit_status'] ?? null, ScopeMaps::HELD_DEPOSITS, true)) {
                        continue;
                    }
                    $kept = $table === 'reservations' ? ScopeMaps::keepReservation($row, $this->ctx) : ScopeMaps::keepCatering($row, $this->ctx);
                    if ($kept && ! $this->reconciles($row, $method, $ownerId, $storeId)) {
                        $bad[] = $table.'#'.(int) $row['id'];
                    }
                }
            }, $column, [$storeId]);
        }

        return $bad;
    }

    /** @param array<string, mixed> $row */
    private function reconciles(array $row, string $method, int $ownerId, int $storeId): bool
    {
        $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
        if ($currency === '') {
            // The store's own currency (the import has not entered the store yet: read it from the source options).
            $currency = $this->storeCurrency($storeId);
        }
        $exponent = $this->ctx->exponentOf($currency);
        $deposit = $exponent === null ? null : LegacyMoney::toMinor($row['deposit_amount'] ?? null, $exponent);
        $paymentId = (int) ($row['deposit_transaction_id'] ?? 0);
        if ($deposit === null || $deposit <= 0 || $paymentId <= 0 || ! $this->ctx->resolvesPlatform('transaction', $paymentId)) {
            return false;
        }
        $payment = $this->ctx->source->find('transaction', $paymentId);
        if ($payment === null || ($payment['transaction_method'] ?? null) !== $method || (int) ($payment['user_id'] ?? 0) !== $ownerId
            || ! in_array($payment['status'] ?? null, self::PAID, true)) {
            return false;
        }
        $paidCurrency = strtoupper(trim((string) ($payment['currency_code'] ?? '')));
        if ($paidCurrency !== '' && $paidCurrency !== $currency) {
            return false;
        }

        return LegacyMoney::toMinor($payment['amount'] ?? null, (int) $exponent) === $deposit;
    }

    private function storeCurrency(int $storeId): string
    {
        $source = $this->ctx->source;
        $key = $source->pick(LegacyTableMaps::STORE_OPTIONS, LegacyTableMaps::STORE_KEYS);
        $code = $key === null ? null : $source->db->table(LegacyTableMaps::STORE_OPTIONS)->where($key, $storeId)
            ->where('option_name', LegacyTableMaps::STORE_CURRENCY_OPTION)->value('option_value');
        $code = strtoupper(trim((string) $code));

        return $code !== '' ? $code : (string) $this->ctx->platformCurrency;
    }
}

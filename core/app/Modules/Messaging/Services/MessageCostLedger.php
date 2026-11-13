<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Money\Currencies;
use Juwri\Modules\Messaging\Contracts\MessageCosts;
use Juwri\Modules\Messaging\Data\MessageCategory;
use Juwri\Modules\Messaging\Events\MessageCostRecorded;
use Juwri\Modules\Messaging\Models\MessageCost;

/**
 * P1-08b follow-up (J27): writes the message cost ledger (`message_costs`) for every sent message and announces it
 * with {@see MessageCostRecorded}. Never throws: the ledger insert runs in its own savepoint (a failure — missing
 * table on a half-installed app, an RLS WITH CHECK refusal — rolls back only that insert and does not abort the
 * caller's PostgreSQL transaction), and the event is dispatched even when the insert failed so billing never
 * depends on the ledger.
 *
 * Costs: WhatsApp = the billing gate's effective provider cost (the platform admin's cost override when set), else the
 * configured per-message rate (config whatsapp.rates[category][country|default], in config whatsapp.rates_currency,
 * default USD) — an estimate of the provider price, recorded for platform and BYOK
 * sends alike; SMS (BYOK providers, no price in the API answer) and email = unknown → units only, cost NULL.
 */
class MessageCostLedger implements MessageCosts
{
    /**
     * @param  array<string, mixed>  $charge  the billing gate's charge plan, passed through to the event
     */
    public function record(
        string $channel,
        string $category,
        int $units = 1,
        ?int $storeId = null,
        ?int $accountId = null,
        ?int $userId = null,
        ?string $provider = null,
        ?string $feature = null,
        ?string $template = null,
        ?string $providerRef = null,
        ?string $country = null,
        ?float $unitCostMajor = null,
        ?string $currency = null,
        array $charge = [],
    ): ?MessageCost {
        $category = MessageCategory::ledger($category);
        $units = max(1, $units);
        $country = $country !== null && preg_match('/^[A-Z]{2}$/', $country) ? $country : null;
        $unitMinor = $costMinor = null;
        if ($unitCostMajor !== null && $currency !== null) {
            $currency = strtoupper($currency);
            $unitMinor = self::minor($unitCostMajor, $currency);
            $costMinor = self::minor($unitCostMajor * $units, $currency);
        } else {
            $currency = null;
        }

        $row = null;
        try {
            $row = DB::transaction(fn () => MessageCost::create([
                'account_id' => $accountId,
                'store_id' => $storeId ?: null,
                'channel' => $channel,
                'category' => $category,
                'feature' => $feature !== null ? mb_substr($feature, 0, 40) : null,
                'provider' => $provider !== null ? mb_substr($provider, 0, 20) : null,
                'provider_ref' => $providerRef !== null ? mb_substr($providerRef, 0, 191) : null,
                'country' => $country,
                'units' => $units,
                'unit_cost_minor' => $unitMinor,
                'cost_minor' => $costMinor,
                'currency' => $currency,
            ]));
        } catch (\Throwable $e) {
            Log::warning('[MessageCost] ledger write failed: '.$e->getMessage(), ['channel' => $channel]);
        }

        event(new MessageCostRecorded(
            messageCostId: $row?->id,
            channel: $channel,
            category: $category,
            storeId: $row->store_id ?? ($storeId ?: null),
            accountId: $row->account_id ?? $accountId,
            userId: $userId,
            provider: $provider,
            feature: $feature,
            template: $template,
            country: $country,
            units: $units,
            unitCostMinor: $unitMinor,
            costMinor: $costMinor,
            currency: $currency,
            charge: $charge,
        ));

        return $row;
    }

    /** Configured WhatsApp rate (major units of {@see whatsappCurrency()}) or null when none is configured. */
    public static function whatsappRate(string $category, ?string $country): ?float
    {
        $rates = config('whatsapp.rates.'.$category);
        if (! is_array($rates)) {
            return null;
        }
        $rate = ($country !== null ? ($rates[$country] ?? null) : null) ?? ($rates['default'] ?? null);

        return is_numeric($rate) ? (float) $rate : null;
    }

    public static function whatsappCurrency(): string
    {
        return strtoupper((string) config('whatsapp.rates_currency', 'USD'));
    }

    /** SMS segments of a text: GSM-7 160/153 per part, otherwise UCS-2 70/67 (GSM extension chars counted once — an approximation). */
    public static function smsSegments(string $text): int
    {
        $gsm = (bool) preg_match('/^[\x{0A}\x{0D}\x{20}-\x{7E}£¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ¡ÄÖÑÜ§¿äöñüà€]*$/u', $text);
        $len = $gsm ? mb_strlen($text) : (int) (strlen((string) mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')) / 2);
        [$single, $multi] = $gsm ? [160, 153] : [70, 67];

        return $len <= $single ? 1 : (int) ceil($len / $multi);
    }

    /** Major amount → minor units of the currency as a decimal string with six fractional digits. */
    private static function minor(float $major, string $currency): string
    {
        return number_format($major * (10 ** Currencies::exponent($currency)), 6, '.', '');
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\WhatsApp;

use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Store;
use Juwri\Core\Support\PlanGate;
use Juwri\Modules\Messaging\Contracts\MessageChargeGate;
use Juwri\Modules\Messaging\Contracts\WhatsApp;
use Juwri\Modules\Messaging\Data\MessageCategory;
use Juwri\Modules\Messaging\Data\PhoneCountry;
use Juwri\Modules\Messaging\Models\WhatsAppConsent;
use Juwri\Modules\Messaging\Models\WhatsAppInbound;
use Juwri\Modules\Messaging\Models\WhatsAppLog;
use Juwri\Modules\Messaging\Models\WhatsAppOptout;
use Juwri\Modules\Messaging\Models\WhatsAppWindow;
use Juwri\Modules\Messaging\Services\MessageCostLedger;
use Juwri\Modules\Messaging\Services\MessagingGuard;
use Juwri\Modules\Messaging\Services\WhatsApp\Drivers\LinkProvider;

/**
 * Orchestrates WhatsApp sends across the resolved provider driver, with logging.
 *
 * Phase 1 scope: normalise -> resolve provider -> send (text/template) -> log,
 * with automatic wa.me link fallback. Gating/metering/wallet (§4/§16), the 24h
 * window check (§14.1) and opt-out honouring (§14.5) are layered in later phases
 * via the protected hooks below — kept as no-ops so call sites stay stable.
 *
 * See rev/whatsapp/IMPLEMENTATION-SPEC.md §2.4.
 *
 * P1-08b follow-up (J27): billing goes through Messaging's own seams — the pre-send {@see MessageChargeGate}
 * (bound by Billing) and, after a successful send, the {@see MessageCostLedger} line + MessageCostRecorded event
 * Billing settles from. Messaging no longer calls Billing.
 */
class WhatsAppService implements WhatsApp
{
    /** Send features that are order alerts to the customer (plan key `whatsapp_order_alerts`). */
    public const ORDER_ALERT_FEATURES = ['order', 'status'];

    public function __construct(
        protected WhatsAppProviderFactory $factory,
        protected ?MessageChargeGate $gate = null,
        protected ?MessageCostLedger $costs = null,
    ) {
        $this->gate ??= app(MessageChargeGate::class);
        $this->costs ??= app(MessageCostLedger::class);
    }

    /**
     * Send a WhatsApp message.
     *
     * @param  string  $to  destination phone (E.164-ish)
     * @param  string  $body  message text (ignored when $template set)
     * @param  int|null  $storeId  owning restaurant (post id)
     * @param  string  $feature  otp|order|status|campaign|general
     * @param  bool  $marketing  honour opt-outs when true
     * @param  string|null  $template  approved template name (real APIs)
     * @param  array  $vars  template variables
     * @param  int|null  $userId  billing owner; resolved from restaurant when null
     */
    public function send(
        string $to,
        string $body,
        ?int $storeId = null,
        string $feature = 'general',
        bool $marketing = false,
        ?string $template = null,
        array $vars = [],
        ?int $userId = null
    ): WhatsAppResult {
        $to = $this->normalizePhone($to);
        if ($to === '') {
            $this->log('', $body, null, WhatsAppResult::failed('', 'empty recipient'), $storeId, $feature);

            return WhatsAppResult::failed('', 'empty recipient');
        }

        // Pricing follow-ups (PRICING.md §3): order confirmations and status updates to customers need a plan that
        // includes WhatsApp order alerts (a plan without the key keeps them). Nothing is sent or charged.
        if (in_array($feature, self::ORDER_ALERT_FEATURES, true) && $storeId
            && ! PlanGate::storeAllows((int) $storeId, PlanGate::WHATSAPP_ORDER_ALERTS)) {
            return $this->finish($to, $body, new WhatsAppResult('', 'skipped_plan', error: 'plan does not include whatsapp_order_alerts'), $storeId, $feature);
        }

        $provider = $this->factory->make($storeId);
        $isLink = $provider->name() === 'link';
        $byok = method_exists($provider, 'isByok') && $provider->isByok();
        $userId ??= $this->ownerOf($storeId);
        $category = MessageCategory::forFeature($feature);

        // Marketing sends honour opt-out and require explicit consent.
        if ($marketing) {
            if (WhatsAppOptout::isOptedOut($to, $storeId)) {
                return $this->finish($to, $body, new WhatsAppResult($provider->name(), 'skipped_optout'), $storeId, $feature);
            }
            if ($storeId && ! WhatsAppConsent::isOptedIn($to, $storeId)) {
                return $this->finish($to, $body, new WhatsAppResult($provider->name(), 'skipped_optout', error: 'no consent'), $storeId, $feature);
            }
            // Abuse Guard: per-tenant daily cap on bulk sends (transactional exempt).
            if (! MessagingGuard::allow($storeId, 'wa')) {
                return $this->finish($to, $body, new WhatsAppResult($provider->name(), 'skipped_cap', error: 'daily marketing cap reached'), $storeId, $feature);
            }
        }

        // 24h session-window rule for real APIs: free-form (non-template) is only
        // allowed while a window is open; inside the window it is a free service
        // message, outside it a template is mandatory. (spec §14.1/§14.3)
        if (! $isLink && $template === null) {
            if (WhatsAppWindow::isOpen($storeId, $to)) {
                $category = 'service'; // in-window free-form => free
            } else {
                return $this->finish($to, $body, new WhatsAppResult($provider->name(), 'window_closed', error: 'requires template outside 24h window'), $storeId, $feature);
            }
        }

        // Pre-send billing gate (skipped for link/BYOK/service — all unbillable). A send through juwri's hosted messaging
        // (provider juwri_hosted) is paid with the installation's juwri send credits: billing it here too charged twice.
        $hosted = $provider->name() === 'juwri_hosted';
        $billable = ! $isLink && ! $byok && ! $hosted && $category !== 'service';
        try {
            $plan = $this->gate->authorize($userId, $category, $billable, $to);
        } catch (WhatsAppLimitExceededException $e) {
            $status = $e->reason === 'funds' ? 'insufficient_funds' : 'limit_exceeded';

            return $this->finish($to, $body, new WhatsAppResult($provider->name(), $status, error: $e->getMessage()), $storeId, $feature);
        }

        try {
            $result = $template !== null
                ? $provider->sendTemplate($to, $template, $vars, ['body' => $body])
                : $provider->sendText($to, $body);
        } catch (WhatsAppProviderException $e) {
            Log::warning('[WhatsApp] provider failed', ['provider' => $e->provider, 'error' => $e->getMessage()]);
            $result = config('whatsapp.fallback_to_link', true)
                ? $this->factory->makeByKey('link', $storeId)->sendText($to, $body)
                : WhatsAppResult::failed($e->provider, $e->getMessage(), $e->errorCode);
        }

        // Only charge for a real, successful API send: ledger line + MessageCostRecorded (Billing settles the plan).
        if ($result->sent && $hosted) {
            $result->category = $plan['category']; // paid at juwri (send credits): no local cost line, nothing to settle
        } elseif ($result->sent) {
            $result->category = $plan['category'];
            $this->recordCost($plan, $to, $userId, $storeId, $result, $feature, $template);
        } else {
            $this->gate->release($plan, $userId); // credit packs: a failed send gives back what the gate reserved
        }

        return $this->finish($to, $body, $result, $storeId, $feature);
    }

    /**
     * J27: one `message_costs` line for a sent message (cost = the gate's effective provider cost — the admin's override
     * when set — else the configured rate for its category/country; unknown for the free wa.me link) and the MessageCostRecorded event carrying the gate's charge plan.
     *
     * @param  array<string, mixed>  $plan
     */
    protected function recordCost(array $plan, string $to, ?int $userId, ?int $storeId, WhatsAppResult $result, string $feature, ?string $template): void
    {
        $category = (string) ($plan['category'] ?? 'service');
        $country = PhoneCountry::of($to);
        // The billing gate's effective provider cost (an admin override when one is set) wins over the shipped rate
        // map; a gate that names none (no billing module) leaves the shipped rate. The free wa.me link costs nothing.
        if ($result->provider === 'link') {
            $rate = $currency = null;
        } elseif (array_key_exists('unitCost', $plan)) {
            $rate = is_numeric($plan['unitCost']) ? (float) $plan['unitCost'] : null;
            $currency = $rate !== null && is_string($plan['costCurrency'] ?? null) ? (string) $plan['costCurrency'] : null;
            $rate = $currency !== null ? $rate : null;
        } else {
            $rate = MessageCostLedger::whatsappRate($category, $country);
            $currency = $rate !== null ? MessageCostLedger::whatsappCurrency() : null;
        }
        $this->costs->record(
            channel: 'whatsapp',
            category: $category,
            units: 1,
            storeId: $storeId,
            userId: $userId,
            provider: $result->provider,
            feature: $feature,
            template: $template,
            providerRef: $result->providerMsgId,
            country: $country,
            unitCostMajor: $rate,
            currency: $currency,
            charge: $plan,
        );
    }

    /** Log the outcome and return the result (single exit point). */
    protected function finish(string $to, string $body, WhatsAppResult $result, ?int $storeId, string $feature): WhatsAppResult
    {
        $this->log($to, $body, $result->provider ?: null, $result, $storeId, $feature);

        return $result;
    }

    /**
     * GDPR right-to-erasure: remove a contact's WhatsApp data. Inbound threads,
     * session windows and consent are deleted; outbound log rows are redacted
     * (kept for billing/audit counts but stripped of PII). Opt-out suppression
     * is intentionally retained so the number isn't re-marketed. (spec §15.7)
     *
     * @return int number of rows affected
     */
    public function forgetContact(string $phone, ?int $storeId = null): int
    {
        $phone = $this->normalizePhone($phone);
        if ($phone === '') {
            return 0;
        }

        $affected = 0;

        $affected += WhatsAppInbound::where('from_phone', $phone)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->delete();

        $affected += WhatsAppWindow::where('phone', $phone)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->delete();

        $affected += WhatsAppConsent::where('phone', $phone)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->delete();

        $affected += WhatsAppLog::where('to', $phone)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->update(['to' => 'REDACTED', 'body_preview' => null]);

        return $affected;
    }

    protected function ownerOf(?int $storeId): ?int
    {
        if (! $storeId) {
            return null;
        }
        try {
            return Store::whereKey($storeId)->value('user_id');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Convenience: resolve the wa.me deep link for a restaurant's WhatsApp
     * number without going through a real provider. Used by menu themes and the
     * QuickOrder flow as the single source of truth for link building.
     */
    public function link(string $number, string $message = ''): string
    {
        return LinkProvider::buildUrl($number, $message);
    }

    public function optInMarketing(string $phone, int $storeId, string $source): void
    {
        $normal = $this->normalizePhone($phone);
        if ($normal !== '') {
            WhatsAppConsent::optIn($normal, $storeId, mb_substr($source, 0, 12)); // whatsapp_consents.source is 12 characters
        }
    }

    /**
     * Best-effort E.164 normalisation (mirrors SmsService): keep a leading +,
     * convert 00 prefix to +, strip separators.
     */
    public function normalizePhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }
        $plus = str_starts_with($phone, '+');
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '') {
            return '';
        }
        if ($plus) {
            return '+'.$digits;
        }
        if (str_starts_with($digits, '00')) {
            return '+'.ltrim(substr($digits, 2), '0');
        }

        return $digits;
    }

    protected function log(string $to, string $body, ?string $provider, WhatsAppResult $result, ?int $storeId, string $feature): void
    {
        try {
            WhatsAppLog::create([
                'to' => $to,
                'body_preview' => mb_substr($body, 0, 191),
                'provider' => $provider,
                'status' => $result->status,
                'provider_msg_id' => $result->providerMsgId,
                'store_id' => $storeId,
                'feature' => $feature,
                'direction' => 'out',
                'error' => $result->error,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[WhatsApp] log write failed: '.$e->getMessage());
        }
    }
}

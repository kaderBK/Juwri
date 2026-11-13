<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Billing\PlanLimits;
use Juwri\Core\Contracts\Billing\PrepaidCredits;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Modules\Messaging\Contracts\Sms;
use Juwri\Modules\Messaging\Data\MessageCategory;
use Juwri\Modules\Messaging\Data\PhoneCountry;
use Juwri\Modules\Messaging\Models\SmsLog;
use Juwri\Modules\Messaging\Models\SmsOptout;
use Juwri\Modules\Messaging\Services\Hosted\HostedMessagingClient;

class SmsService implements Sms
{
    /**
     * Send an SMS message using the restaurant's or platform's credentials.
     *
     * @param  string  $to  Recipient phone number
     * @param  string  $message  Message content
     * @param  string  $feature  otp|order|status|campaign|general (for logging)
     * @param  bool  $marketing  when true, honour opt-outs (skip opted-out numbers)
     * @return bool true when accepted/sent (or safely simulated), false on failure
     */
    public function send(string $to, string $message, ?int $storeId = null, string $feature = 'general', bool $marketing = false): bool
    {
        $to = $this->normalizePhone($to);
        if ($to === '') {
            $this->log('', $message, null, 'failed', null, $storeId, $feature);

            return false;
        }

        // Marketing sends must respect opt-out; transactional (otp/order) do not.
        if ($marketing && SmsOptout::isOptedOut($to, $storeId)) {
            $this->log($to, $message, null, 'skipped_optout', null, $storeId, $feature);

            return true; // handled: intentionally not sent
        }

        // E3: per-plan monthly SMS quota. Absent `sms_monthly_limit` plan key →
        // today's behavior (no monthly cap). Auth OTPs are exempt so a spent
        // quota can never lock a user out of signing in.
        // Credit packs (PRICING.md §8): over the allowance, a send on the PLATFORM's key may still go out paid by the
        // owner's prepaid SMS credits (drawn just before the send, below); a send on the store's own key stays capped.
        $overCap = $storeId && $feature !== 'otp' && $this->overMonthlyPlanCap($storeId);
        if ($overCap && $this->storeCredential($storeId) !== null) {
            $this->log($to, $message, null, 'skipped_cap', null, $storeId, $feature);

            return true; // handled: monthly plan quota reached, not an error
        }

        // Abuse Guard: per-tenant daily cap on bulk sends (transactional exempt).
        if ($marketing && ! MessagingGuard::allow($storeId, 'sms')) {
            $this->log($to, $message, null, 'skipped_cap', null, $storeId, $feature);

            return true; // handled: capped, not an error
        }

        // Per-phone transactional cap. Transactional sends (order/otp/status) are exempt
        // from the marketing opt-out + daily cap, but several fire from PUBLIC,
        // unauthenticated endpoints (storefront order placement), so an attacker could
        // pump SMS to a chosen number on the store's credential (victim-bombing / cost).
        // Cap transactional messages per (store, phone) per hour — a real customer's
        // handful of order/status texts is unaffected. The key strips a leading + so
        // +E164 and bare digits can't be split into two buckets to double the limit.
        if (! $marketing) {
            $cap = (int) config('sms.txn_per_phone_hourly', 20);
            $key = 'sms:txn:'.($storeId ?: 0).':'.ltrim($to, '+').':'.now()->format('YmdH');
            if ($cap > 0 && (int) Cache::get($key, 0) >= $cap) {
                $this->log($to, $message, null, 'skipped_rate', null, $storeId, $feature);

                return true; // handled: per-phone transactional rate limit, not an error
            }
            Cache::put($key, (int) Cache::get($key, 0) + 1, now()->addHour());
        }

        // Fetch active SMS credentials
        $credential = Credential::where('feature', 'sms')
            ->where('active', true)
            ->where(function ($q) use ($storeId) {
                if ($storeId) {
                    $q->where('store_id', $storeId)->orWhereNull('store_id');
                } else {
                    $q->whereNull('store_id');
                }
            })
            ->orderByRaw('store_id IS NULL, store_id DESC') // Merchant override first (NULLs last on every driver)
            ->first();

        if (! $credential) {
            // P6: platform fallback now via config('sms.*') so it survives
            // config:cache (env() returns null once .env stops being parsed).
            $provider = config('sms.provider', 'log');
            $secrets = self::configSecrets();
        } else {
            $provider = $credential->provider;
            // The Integrations vault stores neutral key names (sid/auth_token/
            // from_number, key/secret); map them to the internal naming.
            $s = (array) $credential->secrets;
            $secrets = [
                'twilio_sid' => $s['twilio_sid'] ?? $s['sid'] ?? null,
                'twilio_token' => $s['twilio_token'] ?? $s['auth_token'] ?? null,
                'twilio_from' => $s['twilio_from'] ?? $s['from_number'] ?? null,
                'nexmo_key' => $s['nexmo_key'] ?? $s['key'] ?? null,
                'nexmo_secret' => $s['nexmo_secret'] ?? $s['secret'] ?? null,
                'nexmo_from' => $s['nexmo_from'] ?? $s['from_number'] ?? null,
                // Msegat neutral vault keys: username / api_key / sender (fallbacks kept).
                'msegat_username' => $s['msegat_username'] ?? $s['username'] ?? null,
                'msegat_api_key' => $s['msegat_api_key'] ?? $s['api_key'] ?? null,
                'msegat_sender' => $s['msegat_sender'] ?? $s['sender'] ?? $s['from_number'] ?? null,
            ];
        }

        $creditKey = null;
        if ($overCap) {
            $creditKey = $this->drawCredits((int) $storeId, $to, $message, $feature);
            if ($creditKey === null) {
                $this->log($to, $message, null, 'skipped_cap', null, $storeId, $feature);

                return true; // handled: allowance used up and no prepaid credits left
            }
        }

        $ok = $this->dispatch((string) $provider, $to, $message, $secrets, $storeId, $feature);
        if (! $ok && $creditKey !== null) {
            $this->releaseCredits((int) $storeId, $creditKey);
        }

        return $ok;
    }

    /** @return array<string, mixed> the platform's provider keys, from config only */
    private static function configSecrets(): array
    {
        return [
            'twilio_sid' => config('sms.twilio.sid'),
            'twilio_token' => config('sms.twilio.token'),
            'twilio_from' => config('sms.twilio.from'),
            'nexmo_key' => config('sms.nexmo.key'),
            'nexmo_secret' => config('sms.nexmo.secret'),
            'nexmo_from' => config('sms.nexmo.from'),
            // Msegat (KSA) — plain HTTPS, per-request userName + apiKey + sender.
            'msegat_username' => config('sms.msegat.username'),
            'msegat_api_key' => config('sms.msegat.api_key'),
            'msegat_sender' => config('sms.msegat.sender'),
        ];
    }

    /**
     * juwri-hosted messaging (hosted.gate:messaging): one send on the PLATFORM's keys from config only — never a stored
     * Credential. null = no platform provider configured (nothing is simulated); the SMS log keeps the send as usual.
     */
    public function sendOnPlatformConfig(string $to, string $message, string $feature = 'hosted'): ?bool
    {
        $to = $this->normalizePhone($to);
        $provider = (string) config('sms.provider', 'log');
        $s = self::configSecrets();
        $configured = ($provider === 'twilio' && ! empty($s['twilio_sid']) && ! empty($s['twilio_token']))
            || (in_array($provider, ['nexmo', 'vonage'], true) && ! empty($s['nexmo_key']))
            || ($provider === 'msegat' && ! empty($s['msegat_username']) && ! empty($s['msegat_api_key']));
        if ($to === '' || ! $configured) {
            return $to === '' ? false : null;
        }

        return $this->dispatch($provider, $to, $message, $s, null, $feature);
    }

    /**
     * Send through the resolved provider (or log-simulate without credentials) and log the outcome.
     *
     * @param  array<string, mixed>  $secrets
     */
    private function dispatch(string $provider, string $to, string $message, array $secrets, ?int $storeId, string $feature): bool
    {
        if ($provider === 'twilio' && ! empty($secrets['twilio_sid'])) {
            $ok = $this->sendTwilio($to, $message, $secrets);
            $this->log($to, $message, 'twilio', $ok ? 'sent' : 'failed', null, $storeId, $feature);

            return $ok;
        }

        if (($provider === 'nexmo' || $provider === 'vonage') && ! empty($secrets['nexmo_key'])) {
            $ok = $this->sendVonage($to, $message, $secrets);
            $this->log($to, $message, 'vonage', $ok ? 'sent' : 'failed', null, $storeId, $feature);

            return $ok;
        }

        if ($provider === 'msegat' && ! empty($secrets['msegat_username']) && ! empty($secrets['msegat_api_key'])) {
            [$ok, $providerMsgId] = $this->sendMsegat($to, $message, $secrets);
            $this->log($to, $message, 'msegat', $ok ? 'sent' : 'failed', $providerMsgId, $storeId, $feature);

            return $ok;
        }

        // Self-hosted on juwri's hosted messaging (SMS_PROVIDER=juwri_hosted): the sending store's credits, juwri's keys.
        if ($provider === 'juwri_hosted') {
            $sent = app(HostedMessagingClient::class)->send('sms', $to, $message, $storeId)['sent'];
            $this->log($to, $message, 'juwri_hosted', $sent ? 'sent' : 'failed', null, $storeId, $feature);

            return $sent;
        }

        // Fallback or testing mode: log-simulate (no credentials configured). The message text stays in the SMS log
        // only: the app log never carries message content.
        Log::info('SMS Send Simulation (No Credentials): '.mb_strlen($message).' characters to ****'.substr($to, -3));
        $this->log($to, $message, 'log', 'simulated', null, $storeId, $feature);

        return true;
    }

    /** The store's OWN active SMS credential (its own provider account — never billed by the platform), or null. */
    private function storeCredential(int $storeId): ?Credential
    {
        return Credential::where('feature', 'sms')->where('active', true)->where('store_id', $storeId)->first();
    }

    /**
     * Credit packs: draw the owner's prepaid SMS credits for one platform-key send over the allowance — one credit per
     * segment, of the recipient country's kind. Returns the usage key, or null when there are not enough credits.
     */
    private function drawCredits(int $storeId, string $to, string $message, string $feature): ?string
    {
        $ownerId = (int) Store::where('id', $storeId)->value('user_id');
        $credits = app(PrepaidCredits::class);
        $kind = $ownerId > 0 ? $credits->kindFor('sms', null, PhoneCountry::of($to)) : null;
        $usageKey = 'sms:'.Str::uuid()->toString();

        return $kind !== null && $credits->draw($ownerId, $kind, MessageCostLedger::smsSegments($message), $usageKey, $feature) ? $usageKey : null;
    }

    private function releaseCredits(int $storeId, string $usageKey): void
    {
        $ownerId = (int) Store::where('id', $storeId)->value('user_id');
        if ($ownerId > 0) {
            app(PrepaidCredits::class)->release($ownerId, $usageKey);
        }
    }

    /**
     * E3: is this store at/over its per-plan monthly SMS allowance? Reads the
     * store owner's `sms_monthly_limit` plan value (999 sentinel = unlimited; 0 or
     * absent = no cap) and counts this store's sent/simulated messages this month.
     */
    protected function overMonthlyPlanCap(int $storeId): bool
    {
        $owner = Store::where('id', $storeId)->first()?->user;
        if (! $owner) {
            return false;
        }
        $raw = app(PlanLimits::class)->value($owner, 'sms_monthly_limit');
        if ($raw === null || $raw === '') {
            return false; // absent → today's behavior (no monthly cap)
        }
        $limit = (int) $raw;
        if ($limit <= 0 || $limit >= 999) {
            return false; // 0 = no cap; 999 sentinel = unlimited
        }

        $used = SmsLog::where('store_id', $storeId)
            ->whereIn('status', ['sent', 'simulated'])
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        return $used >= $limit;
    }

    /**
     * Best-effort E.164 normalisation: keep a leading +, convert a 00 prefix to
     * +, strip spaces/dashes/parens. Numbers without a country code are left as
     * their digits (the gateway may still route them for a known sender region).
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

    protected function log(string $to, string $message, ?string $provider, string $status, ?string $providerMsgId, ?int $storeId, string $feature): void
    {
        try {
            SmsLog::create([
                'to' => $to,
                'message_preview' => mb_substr($message, 0, 160),
                'provider' => $provider,
                'status' => $status,
                'provider_msg_id' => $providerMsgId,
                'store_id' => $storeId,
                'feature' => $feature,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[SMS] log write failed: '.$e->getMessage());
        }

        // P1-08b follow-up (J27): a message a real provider accepted gets its cost-ledger line (segments as units;
        // the BYOK providers return no price, so the cost stays unknown). Simulated/skipped/failed sends cost nothing.
        if ($status === 'sent') {
            app(MessageCostLedger::class)->record(
                channel: 'sms',
                category: MessageCategory::forFeature($feature),
                units: MessageCostLedger::smsSegments($message),
                storeId: $storeId,
                provider: $provider,
                feature: $feature,
                providerRef: $providerMsgId,
                country: PhoneCountry::of($to),
            );
        }
    }

    private function sendTwilio(string $to, string $message, array $secrets): bool
    {
        $sid = $secrets['twilio_sid'];
        $token = $secrets['twilio_token'];
        $from = $secrets['twilio_from'] ?? config('sms.twilio.from') ?: 'juwri';

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";

        try {
            $response = Http::asForm()
                ->withBasicAuth($sid, $token)
                ->post($url, [
                    'To' => $to,
                    'From' => $from,
                    'Body' => $message,
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::error('Twilio SMS send failed: '.$response->body());

            return false;
        } catch (\Exception $e) {
            Log::error('Twilio SMS exception: '.$e->getMessage());

            return false;
        }
    }

    private function sendVonage(string $to, string $message, array $secrets): bool
    {
        $key = $secrets['nexmo_key'];
        $secret = $secrets['nexmo_secret'];
        $from = $secrets['nexmo_from'] ?? config('sms.nexmo.from') ?: 'juwri';

        $url = 'https://rest.nexmo.com/sms/json';

        try {
            $response = Http::post($url, [
                'api_key' => $key,
                'api_secret' => $secret,
                'to' => $to,
                'from' => $from,
                'text' => $message,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $messageStatus = $data['messages'][0]['status'] ?? '1';
                if ($messageStatus === '0') {
                    return true;
                }
                Log::error('Vonage/Nexmo SMS send error status: '.json_encode($data));

                return false;
            }

            Log::error('Vonage/Nexmo SMS HTTP failure: '.$response->body());

            return false;
        } catch (\Exception $e) {
            Log::error('Vonage/Nexmo SMS exception: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Msegat (KSA gateway). Plain HTTPS POST JSON, auth via per-request userName +
     * apiKey. Success codes: `1` or `M0000`. UTF8 native (Arabic). Never logs apiKey.
     *
     * @return array{0:bool,1:?string} [ok, providerMsgId]
     *
     * @see docs/MSEGAT-SENDER-REGISTRATION.md — KSA sender-name onboarding (BD-ACTIONS #4).
     */
    private function sendMsegat(string $to, string $message, array $secrets): array
    {
        $username = $secrets['msegat_username'];
        $apiKey = $secrets['msegat_api_key'];
        $sender = $secrets['msegat_sender'] ?? config('sms.msegat.sender') ?: 'juwri';
        $numbers = $this->msegatNumber($to);

        if ($numbers === '') {
            Log::error('Msegat SMS: unroutable number after normalization');

            return [false, null];
        }

        // UTF8 for Arabic (billing 70 chars/segment) — plain ASCII rides UTF8 too.
        $payload = [
            'userName' => $username,
            'apiKey' => $apiKey,
            'userSender' => $sender,
            'numbers' => $numbers,
            'msg' => $message,
            'msgEncoding' => 'UTF8',
        ];

        // Human-readable error map (log-friendly; never surfaced to the diner).
        $errors = [
            '1020' => 'invalid login', '1050' => 'empty message', '1060' => 'no balance',
            '1061' => 'duplicate message', '1110' => 'bad sender name',
            '1120' => 'bad numbers / international disabled', '1140' => 'message too long',
        ];

        $attempts = 0;
        do {
            $attempts++;
            try {
                $response = Http::timeout(15)
                    ->acceptJson()
                    ->post('https://www.msegat.com/gw/sendsms.php', $payload);
            } catch (\Throwable $e) {
                // Connection error — single retry, then give up.
                if ($attempts < 2) {
                    continue;
                }
                Log::error('Msegat SMS connection error: '.$e->getMessage());

                return [false, null];
            }

            $body = $response->json() ?? [];
            $code = (string) ($body['code'] ?? $body['message'] ?? '');

            if ($code === '1' || strtoupper($code) === 'M0000') {
                $id = isset($body['id']) ? (string) $body['id'] : (isset($body['reqBulkId']) ? (string) $body['reqBulkId'] : null);

                return [true, $id];
            }

            $meaning = $errors[$code] ?? ('code '.($code === '' ? 'unknown' : $code));
            Log::error("Msegat SMS send failed: {$meaning}");

            return [false, null];
        } while ($attempts < 2);

        return [false, null];
    }

    /**
     * Normalize a phone to the Msegat "9665XXXXXXXX" shape: strip +/spaces, map a
     * local KSA 05x → 9665x, keep already-international numbers as digits.
     */
    public function msegatNumber(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return '';
        }
        // 00 international prefix → drop it.
        if (str_starts_with($digits, '00')) {
            $digits = ltrim(substr($digits, 2), '0');
        }
        // Local KSA mobile 05XXXXXXXX → 9665XXXXXXXX.
        if (str_starts_with($digits, '05') && strlen($digits) === 10) {
            return '966'.substr($digits, 1);
        }
        // Bare 5XXXXXXXX (9 digits) → 9665XXXXXXXX.
        if (str_starts_with($digits, '5') && strlen($digits) === 9) {
            return '966'.$digits;
        }

        return $digits;
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\WhatsApp;

use Illuminate\Support\Facades\Cache;

/**
 * WhatsApp one-time-password send + verify. Codes are cached (default 5 min).
 * Delivery is an authentication-category message; on real providers an approved
 * authentication template is required outside the 24h window. (spec §7 OTP)
 */
class WhatsAppOtp
{
    public function __construct(protected WhatsAppService $wa) {}

    public function send(string $phone, ?int $storeId = null, ?string $template = null): WhatsAppResult
    {
        $phone = $this->wa->normalizePhone($phone);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->key($phone, $storeId), $code, now()->addMinutes((int) config('whatsapp.otp_ttl_minutes', 5)));

        $tpl = $template ?: ($storeId ? (store_options($storeId, 'whatsapp_otp_template') ?: null) : null);

        return $this->wa->send(
            $phone,
            "Your verification code is {$code}",
            $storeId,
            'otp',
            false,
            $tpl,
            [$code]
        );
    }

    public function verify(string $phone, string $code, ?int $storeId = null): bool
    {
        $phone = $this->wa->normalizePhone($phone);
        $key = $this->key($phone, $storeId);
        $stored = Cache::get($key);

        if ($stored !== null && hash_equals((string) $stored, trim($code))) {
            Cache::forget($key);

            return true;
        }

        return false;
    }

    protected function key(string $phone, ?int $storeId): string
    {
        return 'wa_otp:'.($storeId ?? 0).':'.$phone;
    }
}

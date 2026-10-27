<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\WhatsApp\Drivers;

use Juwri\Modules\Messaging\Services\WhatsApp\AbstractWhatsAppProvider;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppResult;

/**
 * wa.me click-to-chat driver. Sends nothing server-side — it builds a
 * pre-filled WhatsApp deep link the customer taps. This is the guaranteed
 * built-in fallback (no credentials, no cost) and reuses the restaurant's
 * existing numbers. See rev/whatsapp/IMPLEMENTATION-SPEC.md §5.1.
 */
class LinkProvider extends AbstractWhatsAppProvider
{
    public function name(): string
    {
        return 'link';
    }

    public function isConfigured(): bool
    {
        return true; // needs no secrets
    }

    public function capabilities(): array
    {
        return array_merge(parent::capabilities(), ['send' => true]);
    }

    /**
     * $to is the destination WhatsApp number (already resolved by the service
     * from branch / store_options). Returns a link result — nothing is sent.
     */
    public function sendText(string $to, string $body, array $opts = []): WhatsAppResult
    {
        return WhatsAppResult::link('link', self::buildUrl($to, $body));
    }

    /**
     * Build a wa.me / api.whatsapp.com deep link. Kept identical to the legacy
     * format so existing behaviour is byte-for-byte preserved.
     */
    public static function buildUrl(string $number, string $message = ''): string
    {
        $digits = preg_replace('/\D+/', '', (string) $number);
        $url = 'https://api.whatsapp.com/send?phone='.$digits;
        if ($message !== '') {
            $url .= '&text='.urlencode($message);
        }

        return $url;
    }
}

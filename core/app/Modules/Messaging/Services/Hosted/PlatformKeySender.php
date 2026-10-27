<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\Hosted;

use Juwri\Modules\Messaging\Contracts\PlatformKeySends;
use Juwri\Modules\Messaging\Services\SmsService;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppProviderFactory;

/** {@see PlatformKeySends}: SMS through SmsService's config-only path, WhatsApp through the platform provider. */
final class PlatformKeySender implements PlatformKeySends
{
    public function __construct(private readonly SmsService $sms, private readonly WhatsAppProviderFactory $whatsApp) {}

    public function sms(string $to, string $message, string $feature = 'hosted'): ?bool
    {
        return $this->sms->sendOnPlatformConfig($to, $message, $feature);
    }

    public function whatsApp(string $to, string $body, ?string $template = null, array $vars = []): ?bool
    {
        $provider = $this->whatsApp->make(null);
        if (in_array($provider->name(), ['link', 'juwri_hosted'], true) || ! $provider->isConfigured()) {
            return null; // no platform WhatsApp provider: a wa.me link is not a send
        }
        $result = $template !== null ? $provider->sendTemplate($to, $template, $vars, ['body' => $body]) : $provider->sendText($to, $body);

        return $result->sent;
    }
}

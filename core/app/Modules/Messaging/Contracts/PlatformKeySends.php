<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Contracts;

/**
 * Sends on the PLATFORM's own provider keys, read from config only (never a stored Credential, never a store's key):
 * what the juwri-hosted messaging service (hosted.gate:messaging) uses to send for a self-hosted installation. The
 * keys never leave this boundary (no return value, exception message or log line carries them).
 */
interface PlatformKeySends
{
    /** true sent, false the provider refused, null = no platform SMS provider configured */
    public function sms(string $to, string $message, string $feature = 'hosted'): ?bool;

    /**
     * @param  array<int|string, mixed>  $vars
     * @return bool|null true sent, false refused, null = no platform WhatsApp provider configured
     */
    public function whatsApp(string $to, string $body, ?string $template = null, array $vars = []): ?bool;
}

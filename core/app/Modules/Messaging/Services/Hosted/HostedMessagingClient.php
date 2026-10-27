<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\Hosted;

use Juwri\Core\Models\Store;
use Juwri\Core\Services\HostedServices\HostedServiceClient;

/**
 * Self-hosted installations: SMS / WhatsApp through juwri's hosted messaging service (POST
 * api/hosted/v1/messaging/send, behind hosted.gate:messaging), paid with the installation's juwri send credits — no
 * provider key on this server. Every call is signed and names the sending store. A refusal (store past the edition's
 * limit, no credits, the recipient opted out at juwri) is an unsent message, never retried some other way: the store
 * keeps trading. Switch on: SMS_PROVIDER=juwri_hosted / WHATSAPP_DEFAULT=juwri_hosted.
 */
final class HostedMessagingClient
{
    public function __construct(private readonly HostedServiceClient $client) {}

    public function connected(): bool
    {
        return $this->client->connected();
    }

    /**
     * @param  array<int|string, mixed>  $vars
     * @return array{sent: bool, code: string|null, message: string|null}
     */
    public function send(string $channel, string $to, string $body, ?int $storeId, bool $marketing = false, ?string $template = null, array $vars = []): array
    {
        $store = $storeId !== null ? Store::query()->find($storeId) : null;
        if ($store === null || ! $this->client->connected()) {
            return ['sent' => false, 'code' => $store === null ? 'no_store' : 'not_connected', 'message' => null];
        }
        $payload = array_filter(['channel' => $channel, 'to' => $to, 'body' => $body, 'marketing' => $marketing, 'template' => $template, 'vars' => $vars !== [] ? array_values($vars) : null], fn ($v) => $v !== null);
        try {
            $res = $this->client->send('POST', '/api/hosted/v1/messaging/send', $payload, $store);
        } catch (\Throwable) {
            return ['sent' => false, 'code' => 'unreachable', 'message' => null];
        }

        return ['sent' => $res->successful() && (bool) $res->json('sent'), 'code' => $res->successful() ? null : (string) $res->json('code'), 'message' => $res->successful() ? null : (string) $res->json('message')];
    }

    /** Tell juwri a recipient opted out of (or back into) this store's messages, so juwri's STOP list matches ours. */
    public function optOut(string $channel, string $phone, int $storeId, bool $optIn = false): bool
    {
        $store = Store::query()->find($storeId);
        if ($store === null || ! $this->client->connected()) {
            return false;
        }
        try {
            return $this->client->send('POST', '/api/hosted/v1/messaging/optouts', ['channel' => $channel, 'phone' => $phone, 'action' => $optIn ? 'optin' : 'optout'], $store)->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}

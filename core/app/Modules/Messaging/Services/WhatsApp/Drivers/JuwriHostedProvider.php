<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\WhatsApp\Drivers;

use Juwri\Modules\Messaging\Services\Hosted\HostedMessagingClient;
use Juwri\Modules\Messaging\Services\WhatsApp\AbstractWhatsAppProvider;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppResult;

/**
 * Self-hosted: WhatsApp through juwri's hosted messaging service (hosted.gate:messaging) with the installation's send
 * credits, for the sending store (WHATSAPP_DEFAULT=juwri_hosted after juwri:services:connect). Text and templates;
 * a refusal is an unsent message with juwri's reason, never sent some other way.
 */
final class JuwriHostedProvider extends AbstractWhatsAppProvider
{
    private ?int $storeId = null;

    public function forStore(?int $storeId): self
    {
        $this->storeId = $storeId;

        return $this;
    }

    public function isConfigured(): bool
    {
        return app(HostedMessagingClient::class)->connected();
    }

    public function capabilities(): array
    {
        return ['send' => true, 'template' => true, 'interactive' => false, 'catalog' => false, 'flow' => false, 'inbound' => false, 'status' => false, 'media' => false];
    }

    /**
     * P6-69: the hosted service delivers no inbound/status webhooks (capabilities
     * inbound/status = false) and signs none, so every POST to
     * /whatsapp-webhook/juwri_hosted is forged — reject it explicitly.
     *
     * @param  array<string, array<int, string>>  $headers
     * @param  array<string, mixed>  $params
     */
    public function authenticate(array $headers, string $rawBody, string $url, array $params): bool
    {
        return false;
    }

    /** @param  array<string, mixed>  $opts */
    public function sendText(string $to, string $body, array $opts = []): WhatsAppResult
    {
        return $this->result(app(HostedMessagingClient::class)->send('whatsapp', $to, $body, $this->storeId));
    }

    /**
     * @param  array<int|string, mixed>  $vars
     * @param  array<string, mixed>  $opts
     */
    public function sendTemplate(string $to, string $template, array $vars = [], array $opts = []): WhatsAppResult
    {
        return $this->result(app(HostedMessagingClient::class)->send('whatsapp', $to, (string) ($opts['body'] ?? ''), $this->storeId, false, $template, $vars));
    }

    /** @param  array{sent: bool, code: string|null, message: string|null}  $r */
    private function result(array $r): WhatsAppResult
    {
        return $r['sent'] ? new WhatsAppResult($this->key, 'sent', true) : new WhatsAppResult($this->key, 'failed', false, error: $r['message'] ?? $r['code'], errorCode: $r['code']);
    }
}

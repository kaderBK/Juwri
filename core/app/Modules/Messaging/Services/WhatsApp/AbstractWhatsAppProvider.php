<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\WhatsApp;


/**
 * Base driver: every capability defaults to "unsupported" so a concrete driver
 * only overrides what it can do. Unsupported sends throw a terminal
 * (non-retryable) exception; webhook parsers return null.
 */
abstract class AbstractWhatsAppProvider implements WhatsAppProviderInterface
{
    public function __construct(
        protected string $key,
        protected array $config = [],
        protected bool $byok = false,
    ) {}

    /** True when running on the restaurant's own key (skips platform billing). */
    public function isByok(): bool
    {
        return $this->byok;
    }

    public function name(): string
    {
        return $this->key;
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function capabilities(): array
    {
        return [
            'send' => false, 'template' => false, 'interactive' => false,
            'catalog' => false, 'flow' => false, 'inbound' => false,
            'status' => false, 'media' => false,
        ];
    }

    public function sendText(string $to, string $body, array $opts = []): WhatsAppResult
    {
        return $this->unsupported('text');
    }

    public function sendTemplate(string $to, string $template, array $vars = [], array $opts = []): WhatsAppResult
    {
        return $this->unsupported('template');
    }

    public function sendInteractive(string $to, array $interactive, array $opts = []): WhatsAppResult
    {
        return $this->unsupported('interactive');
    }

    public function sendCatalog(string $to, array $products, array $opts = []): WhatsAppResult
    {
        return $this->unsupported('catalog');
    }

    public function sendFlow(string $to, string $flowId, array $data = [], array $opts = []): WhatsAppResult
    {
        return $this->unsupported('flow');
    }

    public function verifyWebhook(array $query, array $headers, string $raw): bool|string
    {
        return false;
    }

    public function authenticate(array $headers, string $rawBody, string $url, array $params): bool
    {
        // P6-69: fail CLOSED. A driver without its own signature check has no
        // way to prove a POST came from its provider, so it is rejected.
        return false;
    }

    public function parseInbound(array $payload): ?array
    {
        return null;
    }

    public function parseStatus(array $payload): ?array
    {
        return null;
    }

    public function parseHealth(array $payload): ?array
    {
        return null;
    }

    /**
     * C1 default: reuse whatever the driver can already parse. Concrete drivers
     * override this when their status/health payloads carry the sender identity
     * in a different place from their inbound payloads.
     *
     * @param  array<string, mixed>  $payload
     */
    public function tenantHint(array $payload): ?string
    {
        $hint = $this->parseInbound($payload)['to'] ?? $this->parseHealth($payload)['sender_id'] ?? null;

        return ($hint === null || $hint === '') ? null : (string) $hint;
    }

    protected function unsupported(string $what): WhatsAppResult
    {
        throw new WhatsAppProviderException(
            "Provider '{$this->key}' does not support {$what} messages.",
            $this->key,
            retryable: false,
            errorCode: 'unsupported',
        );
    }
}

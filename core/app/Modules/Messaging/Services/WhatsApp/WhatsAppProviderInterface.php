<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\WhatsApp;


/**
 * Contract every WhatsApp provider driver implements. Drivers should extend
 * {@see AbstractWhatsAppProvider}, which supplies "unsupported" defaults so a
 * driver only overrides the capabilities it actually has.
 *
 * See rev/whatsapp/IMPLEMENTATION-SPEC.md §2.1 / §14.4.
 */
interface WhatsAppProviderInterface
{
    /** Provider key, e.g. 'link' | 'twilio' | 'meta'. */
    public function name(): string;

    /** True when this driver has the secrets it needs to operate. */
    public function isConfigured(): bool;

    /**
     * @return array{send:bool,template:bool,interactive:bool,catalog:bool,flow:bool,inbound:bool,status:bool,media:bool}
     */
    public function capabilities(): array;

    // --- Sending ----------------------------------------------------------

    /** Free-form text (allowed only inside the 24h session window on real APIs). */
    public function sendText(string $to, string $body, array $opts = []): WhatsAppResult;

    /** Pre-approved template message (required outside the 24h window). */
    public function sendTemplate(string $to, string $template, array $vars = [], array $opts = []): WhatsAppResult;

    /** Interactive reply-buttons (<=3) or list (<=10). */
    public function sendInteractive(string $to, array $interactive, array $opts = []): WhatsAppResult;

    /** Multi-product / catalog message. */
    public function sendCatalog(string $to, array $products, array $opts = []): WhatsAppResult;

    /** WhatsApp Flow (in-chat form). */
    public function sendFlow(string $to, string $flowId, array $data = [], array $opts = []): WhatsAppResult;

    // --- Inbound / webhook ------------------------------------------------

    /** Verify a webhook subscription request; return the challenge string (Meta) or bool. */
    public function verifyWebhook(array $query, array $headers, string $raw): bool|string;

    /** Authenticate an inbound webhook POST (signature check). (spec §15.2) */
    public function authenticate(array $headers, string $rawBody, string $url, array $params): bool;

    /**
     * C1: the business identity that RECEIVED this webhook, taken from the raw
     * payload BEFORE any signature check — so the caller can resolve the owning
     * tenant and rebuild the driver with that tenant's BYOK secret. Returns the
     * value that `whatsapp_routes.sender_id` holds for this provider (Meta:
     * phone_number_id; Twilio: the E.164 `To`), or null when the payload carries
     * no sender identity. Parsing only — never trusted as authentication.
     *
     * @param  array<string, mixed>  $payload
     */
    public function tenantHint(array $payload): ?string;

    /** Normalise an inbound message payload, or null when N/A. */
    public function parseInbound(array $payload): ?array;

    /** Normalise a delivery/read status payload, or null when N/A. */
    public function parseStatus(array $payload): ?array;

    /**
     * Normalise a sender quality/tier update, or null when N/A.
     * Shape: ['sender_id','quality','status','messaging_tier']. (spec §15.6)
     */
    public function parseHealth(array $payload): ?array;
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\WhatsApp;

/**
 * Normalised result of a single WhatsApp send attempt.
 *
 * The 'link' driver sets ->sent=false and ->link to a wa.me URL; real API
 * drivers set ->sent=true and ->providerMsgId. See rev/whatsapp/IMPLEMENTATION-SPEC.md §2.2.
 */
class WhatsAppResult
{
    /** queued|sent|delivered|link|failed|skipped_optout|skipped_cap|skipped_plan|limit_exceeded|insufficient_funds|window_closed */
    public string $status;

    public function __construct(
        public string $provider,
        string $status = 'queued',
        public bool $sent = false,
        public ?string $link = null,
        public ?string $providerMsgId = null,
        public ?string $error = null,
        public ?string $errorCode = null,
        public ?string $category = null,
    ) {
        $this->status = $status;
    }

    public static function link(string $provider, string $url): self
    {
        return new self(provider: $provider, status: 'link', sent: false, link: $url);
    }

    public static function sent(string $provider, ?string $providerMsgId = null, ?string $category = null): self
    {
        return new self(provider: $provider, status: 'sent', sent: true, providerMsgId: $providerMsgId, category: $category);
    }

    public static function failed(string $provider, string $error, ?string $errorCode = null): self
    {
        return new self(provider: $provider, status: 'failed', sent: false, error: $error, errorCode: $errorCode);
    }

    public function ok(): bool
    {
        return $this->sent || $this->status === 'link';
    }
}

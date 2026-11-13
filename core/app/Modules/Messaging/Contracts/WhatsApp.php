<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Contracts;

use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppResult;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppService;

/**
 * P1-08 L1 public surface: send one WhatsApp message now (owner inbox replies). Implemented by
 * {@see WhatsAppService} (consent, billing and logging included). Queue a message with {@see MessageQueue::whatsApp()}.
 */
interface WhatsApp
{
    /** @param  array<int|string, mixed>  $vars */
    public function send(string $to, string $body, ?int $storeId = null, string $feature = 'general', bool $marketing = false, ?string $template = null, array $vars = [], ?int $userId = null): WhatsAppResult;

    public function normalizePhone(string $phone): string;

    /**
     * P3-08 / N5: record a shopper's explicit marketing opt-in for this store's WhatsApp messages (a marketing send
     * goes out only with one on record and not withdrawn). `$source` names where it was given (≤ 12 characters, e.g. "restock").
     */
    public function optInMarketing(string $phone, int $storeId, string $source): void;
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Listeners;

use Illuminate\Mail\Events\MessageSent;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Modules\Messaging\Data\MessageCategory;
use Juwri\Modules\Messaging\Services\MessageCostLedger;

/**
 * P1-08b follow-up (J27): every sent email gets a `message_costs` line — units = recipients (to + cc + bcc), cost
 * unknown (NULL). Tenant: the account (and the store, when exactly one) of the current tenant context; platform
 * mail outside a tenant context is recorded without account. Category: the `X-Juwri-Message-Category` header when a
 * mailable sets one (marketing|utility|service|auth), else utility (transactional mail).
 */
final class RecordEmailCost
{
    public const CATEGORY_HEADER = 'X-Juwri-Message-Category';

    public function __construct(private MessageCostLedger $ledger) {}

    public function handle(MessageSent $event): void
    {
        $email = $event->message;
        $units = count($email->getTo()) + count($email->getCc()) + count($email->getBcc());

        $header = $email->getHeaders()->get(self::CATEGORY_HEADER)?->getBodyAsString();
        $category = in_array($header, MessageCategory::LEDGER, true) ? $header : 'utility';

        $subject = TenantContext::current()->subject();
        $storeIds = $subject['store_ids'] ?? null;

        $this->ledger->record(
            channel: 'email',
            category: $category,
            units: $units,
            storeId: is_array($storeIds) && count($storeIds) === 1 ? (int) $storeIds[0] : null,
            accountId: $subject['account_id'] ?? null,
            provider: (string) config('mail.default'),
            providerRef: $event->sent->getMessageId(),
        );
    }
}

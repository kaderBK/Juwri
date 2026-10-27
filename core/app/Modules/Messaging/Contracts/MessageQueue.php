<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Contracts;

/**
 * P1-08 L1 public surface: queue a message for sending — exactly the Messaging jobs the callers dispatched before
 * (SendSmsJob, SendWhatsAppJob, SendPushNotificationJob), so queue routing, retries and Bus fakes are unchanged.
 */
interface MessageQueue
{
    public function sms(string $to, string $message, ?int $storeId = null, string $feature = 'general', bool $marketing = false): void;

    /**
     * $afterCommit: dispatch only once the surrounding DB transaction commits (the job's ->afterCommit()).
     *
     * @param  array<int|string, mixed>  $vars
     */
    public function whatsApp(string $to, string $message, ?int $storeId = null, string $feature = 'general', bool $marketing = false, ?string $template = null, array $vars = [], bool $afterCommit = false): void;

    /** Web push to a store's staff browsers (one staff member when $staffId is set). */
    public function staffPush(int $storeId, string $title, string $body, string $url = '/staff-app', ?int $staffId = null): void;
}

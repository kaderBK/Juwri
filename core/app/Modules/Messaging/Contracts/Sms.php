<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Contracts;

use Juwri\Modules\Messaging\Services\SmsService;

/** P1-08 L1 public surface: SMS for modules above Messaging. Implemented by {@see SmsService}. */
interface Sms
{
    /** true when accepted/sent (or safely simulated), false on failure. */
    public function send(string $to, string $message, ?int $storeId = null, string $feature = 'general', bool $marketing = false): bool;

    public function normalizePhone(string $phone): string;
}

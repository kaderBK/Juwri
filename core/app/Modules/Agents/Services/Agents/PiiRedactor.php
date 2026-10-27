<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Agents;

use Juwri\Core\Support\CustomerText;

/**
 * AO5 #30 — PII minimization for stored chat. Customer-supplied message text is
 * untrusted and may contain personal data (email, phone, card-like numbers). We
 * redact it BEFORE persisting to agent_messages so the transcript store keeps
 * the conversation shape without hoarding raw PII. The live turn still processes
 * the original text; only what we save is redacted. The rules live in
 * {@see CustomerText::redactPii()} (shared with the review reply drafter).
 */
class PiiRedactor
{
    public static function redact(string $text): string
    {
        return CustomerText::redactPii($text);
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Listeners;

use App\Support\OutboundText;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * P6-106: every outbound email's Subject and From/Reply-To display names are single-line plain text. Customer- or
 * owner-typed text reaches them through short codes and store names; this strips CR/LF, bidi overrides, zero-width
 * chars and URLs-in-names so none can forge a header or spoof the sender. Bodies are untouched (Blade escapes them).
 */
final class SanitizeOutgoingHeaders
{
    public function handle(MessageSending $event): void
    {
        $email = $event->message;

        $subject = $email->getSubject();
        if ($subject !== null) {
            $email->subject(OutboundText::header($subject));
        }

        if ($email->getFrom() !== []) {
            $email->from(...$this->clean($email->getFrom()));
        }
        if ($email->getReplyTo() !== []) {
            $email->replyTo(...$this->clean($email->getReplyTo()));
        }
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return array<int, Address>
     */
    private function clean(array $addresses): array
    {
        return array_map(static fn (Address $a) => new Address($a->getAddress(), OutboundText::name($a->getName(), 80)), $addresses);
    }
}

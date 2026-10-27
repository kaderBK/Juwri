<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Listeners;

use Illuminate\Database\QueryException;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Juwri\Modules\Messaging\Data\BulkMail;
use Juwri\Modules\Messaging\Models\EmailSuppression;
use Symfony\Component\Mime\Address;

/**
 * P6-249: bulk mail (a mailable that calls {@see self::markBulk()}: campaigns, digests) never goes to a hard-bounced or
 * complained address — those recipients are dropped and an all-suppressed message is cancelled. Transactional mail
 * (receipts, password resets, order mails) is still sent; a warning is logged and the owner sees the address flagged
 * on the customer page.
 */
final class SkipSuppressedRecipients
{
    public const HEADER = BulkMail::HEADER;

    /** Tag a mailable as bulk mail. Call from its build(). Modules outside Messaging use {@see BulkMail::mark()}. */
    public static function markBulk(Mailable $mailable): void
    {
        BulkMail::mark($mailable);
    }

    public function handle(MessageSending $event): bool
    {
        $email = $event->message;
        $headers = $email->getHeaders();
        $bulk = $headers->has(self::HEADER) && strtolower((string) $headers->get(self::HEADER)?->getBodyAsString()) === 'bulk';
        $headers->remove(self::HEADER); // internal marker, never leaves the server

        $all = array_merge($email->getTo(), $email->getCc(), $email->getBcc());
        try {
            $suppressed = EmailSuppression::reasonsFor(array_map(static fn (Address $a) => $a->getAddress(), $all));
        } catch (QueryException $e) {
            // The suppression lookup never blocks mail: a DB hiccup (or a database not yet migrated) sends as before.
            Log::warning('mail: suppression lookup failed, sending unfiltered', ['error' => $e->getMessage()]);

            return true;
        }
        if ($suppressed === []) {
            return true;
        }

        if (! $bulk) {
            Log::warning('mail: transactional mail to a suppressed address', ['reasons' => array_values($suppressed)]);

            return true;
        }

        $keep = static fn (array $list) => array_values(array_filter($list,
            static fn (Address $a) => ! isset($suppressed[EmailSuppression::normalize($a->getAddress())])));
        $to = $keep($email->getTo());
        $cc = $keep($email->getCc());
        $bcc = $keep($email->getBcc());
        if ($to === [] && $cc === [] && $bcc === []) {
            return false; // nothing left to send
        }
        $headers->remove('To');
        $headers->remove('Cc');
        $headers->remove('Bcc');
        if ($to !== []) {
            $email->to(...$to);
        }
        if ($cc !== []) {
            $email->cc(...$cc);
        }
        if ($bcc !== []) {
            $email->bcc(...$bcc);
        }

        return true;
    }
}

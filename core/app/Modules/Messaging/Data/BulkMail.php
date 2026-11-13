<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Data;

use Illuminate\Mail\Mailable;

/**
 * P6-249: the bulk-mail marker (public surface for modules that send campaigns / digests). A mailable tagged here is
 * dropped for suppressed (bounced / complained) recipients by the Messaging SkipSuppressedRecipients listener.
 */
final class BulkMail
{
    public const HEADER = 'X-Juwri-Mail-Class';

    /** Tag a mailable as bulk mail. Call from its build(). */
    public static function mark(Mailable $mailable): void
    {
        $mailable->withSymfonyMessage(static fn ($m) => $m->getHeaders()->addTextHeader(self::HEADER, 'bulk'));
    }
}

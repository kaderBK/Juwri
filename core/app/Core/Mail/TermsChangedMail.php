<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * P1-23: "We're updating the Terms on <date>" — the 30-day notice of a material change (Terms 14.2). Sent to
 * account owners by `legal:notify-terms-change`. Subject: site.mail.terms_changed.subject; body: sysmail.terms_changed.*.
 */
class TermsChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public CarbonInterface $effectiveOn,
        public string $termsUrl,
    ) {}

    public function build(): static
    {
        $date = $this->effectiveOn->translatedFormat('j F Y');

        return $this->subject(__('site.mail.terms_changed.subject', ['date' => $date]))
            ->view('emails.terms-changed', ['date' => $date]);
    }
}

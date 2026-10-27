<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Polish (feature-platform): app billing lifecycle mails — trial ending
 * reminders (T-3/T-1) and the past_due grace notice. Plain-lines body so one
 * mailable covers every dunning moment.
 */
class AppDunningMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param list<string> $lines */
    public function __construct(
        public string $subjectLine,
        public array $lines,
    ) {
    }

    public function build()
    {
        return $this->subject($this->subjectLine)
            ->html(collect($this->lines)->map(fn ($l) => '<p>'.e($l).'</p>')->implode("\n"));
    }
}

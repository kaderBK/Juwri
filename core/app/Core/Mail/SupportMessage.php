<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SupportMessage extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** P6-64: below queue retry_after. */
    public int $timeout = 60;

    public array $backoff = [60, 300, 900];

    /**
     * @param  array<string, string>  $shortCodes
     */
    public function __construct(
        public string $subjectTemplate,
        public string $bodyTemplate,
        public array $shortCodes,
        public string $replyToEmail,
        public ?string $replyToName = null,
    ) {}

    public function build()
    {
        $subject = str_replace(
            array_keys($this->shortCodes),
            array_values($this->shortCodes),
            $this->subjectTemplate
        );

        // H3: from-identity comes from the options-backed mail config, not stale .env.
        // P6: config/mail.php already captures MAIL_FROM_* at build time, so the env()
        // fallbacks (null under config:cache) are dropped.
        $mail = $this->from(config('mail.from.address'), config('mail.from.name'))
            ->replyTo($this->replyToEmail, $this->replyToName)
            ->subject($subject)
            ->markdown('emails.default', [
                'body' => $this->bodyTemplate,
                'short_codes' => $this->shortCodes,
            ]);

        return $mail;
    }
}

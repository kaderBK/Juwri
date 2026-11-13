<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Mails;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;
use Juwri\Modules\Messaging\Data\BulkMail;

class CampaignMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** P6-64: below queue retry_after. */
    public int $timeout = 60;

    public array $backoff = [60, 300, 900];

    public $subject; // untyped to match parent Illuminate\Mail\Mailable::$subject

    public string $body;

    public string $unsubscribeUrl;

    public function __construct(string $subject, string $body, string $unsubscribeUrl)
    {
        $this->subject = $subject;
        $this->body = $body;
        $this->unsubscribeUrl = $unsubscribeUrl;
    }

    /** P6-222: signed, non-expiring unsubscribe link for a customer. */
    public static function unsubscribeUrlFor(int $customerId): string
    {
        return URL::signedRoute('campaigns.unsubscribe', ['customer' => $customerId]);
    }

    /** P6-222: RFC 2369 + RFC 8058 one-click headers (Gmail/Yahoo bulk-sender rule). */
    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function build()
    {
        BulkMail::mark($this); // P6-249

        return $this->subject($this->subject)
            ->view('emails.campaign')
            ->withSymfonyMessage(fn ($message) => $message->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$this->unsubscribeUrl.'>'));
    }
}

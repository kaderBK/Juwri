<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** P3-10: the 24-hour booking reminder email, with the signed manage link (all strings are plain text, escaped in the view). */
class BookingReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** P6-64: below queue retry_after. */
    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public string $storeName,
        public string $customerName,
        public string $when,
        public string $services,
        public string $manageUrl,
        ?string $language = null,
    ) {
        if ($language) {
            $this->locale($language);
        }
    }

    public function build(): self
    {
        return $this->subject(___('Reminder: your booking').' · '.$this->storeName)
            ->view('emails.booking-reminder', [
                'storeName' => $this->storeName,
                'customerName' => $this->customerName,
                'when' => $this->when,
                'services' => $this->services,
                'manageUrl' => $this->manageUrl,
            ]);
    }
}

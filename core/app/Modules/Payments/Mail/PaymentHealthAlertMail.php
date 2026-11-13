<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * P6-93: a payments:watch finding about ONE store, e-mailed to that store's owner. Lines are translation keys +
 * params, rendered in the owner's locale by the template (never another store's rows).
 */
class PaymentHealthAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var array<int,int> */
    public array $backoff = [60, 300, 900];

    /**
     * @param  list<array{0:string,1:array<string,string|int>}>  $lines
     */
    public function __construct(
        public string $ownerName,
        public string $storeTitle,
        public array $lines,
    ) {}

    public function build(): static
    {
        return $this->subject(___('Payment alert').': '.$this->storeTitle)
            ->view('emails.payment-health-alert');
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * GOL A4 (rev/guest-order-linking): the "finish setting up your account" email
 * a guest receives when they register with an email that already has previous
 * (guest) orders. The signed link proves inbox ownership before their chosen
 * password is activated and their past orders are attached.
 *
 * All display strings are passed in already-escaped by Blade; the restaurant
 * name is the only user-influenced value and the view echoes it through {{ }}.
 */
class CustomerClaimVerify extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** P6-64: below queue retry_after. */
    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public string $restaurantName,
        public string $verifyUrl,
        public string $customerName = ''
    ) {}

    public function build(): self
    {
        return $this->subject(___('Confirm your account').' · '.$this->restaurantName)
            ->view('emails.customer-claim-verify', [
                'restaurantName' => $this->restaurantName,
                'verifyUrl' => $this->verifyUrl,
                'customerName' => $this->customerName,
            ]);
    }
}

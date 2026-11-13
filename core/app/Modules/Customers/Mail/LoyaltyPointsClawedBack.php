<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Mail;

use Illuminate\Mail\Mailable;

/** P6-253 (J46): tells the shopper a refund took back loyalty points (and, if the balance is negative, why). */
class LoyaltyPointsClawedBack extends Mailable
{
    public function __construct(
        public string $storeName,
        public string $customerName,
        public int $orderId,
        public string $text,
        public ?string $mailLocale = null,
    ) {
        if ($mailLocale) {
            $this->locale($mailLocale);
        }
    }

    public function build(): self
    {
        return $this->subject(___('Loyalty points update for order #:order', ['order' => $this->orderId]).' · '.$this->storeName)
            ->view('emails.loyalty-points-clawed-back', [
                'storeName' => $this->storeName,
                'customerName' => $this->customerName,
                'orderId' => $this->orderId,
                'text' => $this->text,
            ]);
    }
}

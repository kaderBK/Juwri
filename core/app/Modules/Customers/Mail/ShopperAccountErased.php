<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Mail;

use Illuminate\Mail\Mailable;

/**
 * P6-91: the confirmation a shopper receives after deleting their account in one store. Sent synchronously (not
 * queued): the customer row is gone, so nothing may be serialised that points at it — only the store name, the
 * greeting name and the locale captured before the erasure.
 */
class ShopperAccountErased extends Mailable
{
    public function __construct(
        public string $storeName,
        public string $customerName = '',
        public ?string $mailLocale = null,
    ) {
        if ($mailLocale) {
            $this->locale($mailLocale);
        }
    }

    public function build(): self
    {
        return $this->subject(___('Your account has been deleted').' · '.$this->storeName)
            ->view('emails.shopper-account-erased', [
                'storeName' => $this->storeName,
                'customerName' => $this->customerName,
            ]);
    }
}

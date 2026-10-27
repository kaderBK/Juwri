<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * P6-304: the link that proves a customer's new e-mail (`confirm_email`), and the notice to the OLD address that a
 * phone / e-mail change was asked for (`notice_old`).
 */
class CustomerContactChange extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public string $restaurantName,
        public string $kind,
        public string $field,
        public string $newValueMasked,
        public ?string $confirmUrl = null,
        public string $customerName = ''
    ) {}

    public function build(): self
    {
        $subject = $this->kind === 'confirm_email' ? ___('Confirm your new email') : ___('Your contact details are changing');

        return $this->subject($subject.' · '.$this->restaurantName)
            ->view('emails.customer-contact-change', [
                'restaurantName' => $this->restaurantName,
                'customerName' => $this->customerName,
                'kind' => $this->kind,
                'field' => $this->field,
                'newValueMasked' => $this->newValueMasked,
                'confirmUrl' => $this->confirmUrl,
            ]);
    }

    /** "ab•••@example.com" / "•••••1234". */
    public static function mask(string $field, string $value): string
    {
        if ($field === 'email' && str_contains($value, '@')) {
            [$local, $domain] = explode('@', $value, 2);

            return mb_substr($local, 0, 2).'•••@'.$domain;
        }

        return '•••••'.mb_substr($value, -4);
    }
}

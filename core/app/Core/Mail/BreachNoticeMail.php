<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Mail;

use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;

/**
 * P1-23: the personal-data breach notice to a customer (security:breach-notice; DPA 6.2 lists what it carries:
 * the nature of the incident, the data affected, what we did and what they should do, a contact). Sent
 * synchronously by the command, never queued, so the breach_notices record means "handed to the mailer". Rendered
 * in the owner's preferred locale (the incident details are the operator's own text).
 */
class BreachNoticeMail extends Mailable
{
    /** @param  array{summary: string, data: string, measures: string, contact: string}  $details */
    public function __construct(public string $incident, public array $details, public CarbonImmutable $confirmedAt) {}

    public function build(): static
    {
        $brand = (string) (config('settings.site_title') ?: config('app.name'));
        $lines = [
            ___('We are writing to tell you about a security incident that affects personal data in your account.'),
            ___('Reference: :ref', ['ref' => $this->incident]),
            ___('Confirmed on: :date (UTC)', ['date' => $this->confirmedAt->utc()->format('Y-m-d H:i')]),
            ___('What happened: :text', ['text' => $this->details['summary']]),
            ___('Data affected: :text', ['text' => $this->details['data']]),
            ___('What we have done and what you should do: :text', ['text' => $this->details['measures']]),
            ___('You may need to notify your regulator and your customers. Contact :contact for the information you need, including the approximate number of people and records affected.', ['contact' => $this->details['contact']]),
            ___('We will keep you updated as our investigation continues.'),
        ];

        return $this->subject(___('Important security notice about your :brand account', ['brand' => $brand]))
            ->html(collect($lines)->map(fn ($l) => '<p>'.e($l).'</p>')->implode("\n"));
    }
}

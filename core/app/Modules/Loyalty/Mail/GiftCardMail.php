<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Mail;

use App\Models\GiftCard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * T2: delivers a gift card to its recipient — code, amount, personal message and the
 * restaurant's branding, plus a link to the storefront to spend it.
 */
class GiftCardMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** P6-64: below queue retry_after. */
    public int $timeout = 60;

    public array $backoff = [60, 300, 900];

    public function __construct(public GiftCard $card, public string $restaurantName, public ?string $storeUrl = null, ?string $language = null)
    {
        // The queue worker runs in the default language: pin the given (store) language, else the current one (P6-165 / P6-265).
        $this->locale($language ?: app()->getLocale());
    }

    public function build()
    {
        return $this->subject(__('You received a gift card').' · '.$this->restaurantName)
            ->view('emails.giftcard', [
                'card' => $this->card,
                'restaurantName' => $this->restaurantName,
                'storeUrl' => $this->storeUrl,
            ]);
    }
}

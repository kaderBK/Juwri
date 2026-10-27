<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * P3-08 / N5: "<item> is back in stock" to a shopper who asked for it with their marketing opt-in. One message,
 * in the language the shopper asked in.
 */
class BackInStockMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** P6-64: below queue retry_after. */
    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public string $storeName, public string $itemName, public string $itemUrl, string $language)
    {
        $this->locale($language);
    }

    public function build(): self
    {
        return $this->subject(___(':item is back in stock', ['item' => $this->itemName]).' · '.$this->storeName)
            ->view('emails.back-in-stock', [
                'storeName' => $this->storeName,
                'itemName' => $this->itemName,
                'itemUrl' => $this->itemUrl,
            ]);
    }
}

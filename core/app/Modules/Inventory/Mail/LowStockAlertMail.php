<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Inventory\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * The low-stock digest, e-mailed to the OWNER of the store the stock belongs to (never to another store's owner).
 * Names are the store's own item / ingredient names; the template escapes them.
 */
class LowStockAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** P6-64: below queue retry_after. */
    public int $timeout = 60;

    /** @var array<int,int> */
    public array $backoff = [60, 300, 900];

    /**
     * @param  list<string>  $names  the first few low items (already limited by the caller)
     */
    public function __construct(
        public string $ownerName,
        public string $storeTitle,
        public int $count,
        public array $names,
        public ?string $url = null,
    ) {}

    public function build(): static
    {
        return $this->subject(___('Low stock alert').': '.$this->storeTitle)
            ->view('emails.low-stock-alert');
    }
}

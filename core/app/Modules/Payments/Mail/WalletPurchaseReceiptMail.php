<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** P6-252: receipt for a purchase paid from the platform wallet (theme licence, app charge). */
class WalletPurchaseReceiptMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public string $productName,
        public string $amount,
        public string $currency,
        public int $receiptId,
        public ?string $receiptUrl,
    ) {}

    public function build(): static
    {
        $amount = number_format((float) $this->amount, currency_decimals($this->currency)).' '.$this->currency;
        $lines = [
            ___('Thank you for your purchase. It was paid from your wallet.'),
            ___('Item').': '.$this->productName,
            ___('Amount').': '.$amount,
            ___('Receipt').': #'.$this->receiptId,
        ];
        $html = collect($lines)->map(fn ($l) => '<p>'.e($l).'</p>')->implode("\n");
        if ($this->receiptUrl !== null) {
            $html .= "\n".'<p><a href="'.e($this->receiptUrl).'">'.e(___('View receipt')).'</a></p>';
        }

        return $this->subject(___('Your receipt').': '.$this->productName)->html($html);
    }
}

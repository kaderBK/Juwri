<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Analytics\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Juwri\Modules\Messaging\Data\BulkMail;

class WeeklyDigestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** P6-64: below queue retry_after. */
    public int $timeout = 60;

    public array $backoff = [60, 300, 900];

    public $name;

    public $totalScans;

    public $uniqueVisitors;

    public $totalRevenue;

    public $ordersCount;

    public $conversionRate;

    public $currencySymbol;

    /** Decimals for the store the symbol came from — 3 for OMR/KWD/JOD/BHD/LYD/TND. */
    public $currencyDecimals;

    /**
     * Create a new message instance.
     */
    public function __construct($data)
    {
        $this->name = $data['name'];
        $this->totalScans = $data['totalScans'];
        $this->uniqueVisitors = $data['uniqueVisitors'];
        $this->totalRevenue = $data['totalRevenue'];
        $this->ordersCount = $data['ordersCount'];
        $this->conversionRate = $data['conversionRate'];
        $this->currencySymbol = $data['currencySymbol'];
        $this->currencyDecimals = $data['currencyDecimals'] ?? 2;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        BulkMail::mark($this); // P6-249: bulk mail

        return $this->subject('Your Weekly Restaurant Analytics Digest 📊')
            ->view('emails.weekly-digest');
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Analytics\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DailyAnomalyAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** P6-64: below queue retry_after. */
    public int $timeout = 60;

    public array $backoff = [60, 300, 900];

    public string $name;

    public int $currentScans;

    public float $baselineAverageScans;

    public float $dropPercent;

    public int $restaurantCount;

    public int $comparisonWindow;

    public function __construct(array $data)
    {
        $this->name = $data['name'];
        $this->currentScans = (int) $data['currentScans'];
        $this->baselineAverageScans = (float) $data['baselineAverageScans'];
        $this->dropPercent = (float) $data['dropPercent'];
        $this->restaurantCount = (int) $data['restaurantCount'];
        $this->comparisonWindow = (int) $data['comparisonWindow'];
    }

    public function build()
    {
        return $this->subject('Scan alert: activity is down today')
            ->view('emails.anomaly-alert');
    }
}

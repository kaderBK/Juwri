<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Juwri\Core\Models\Notification;

class CreateNotificationJob implements ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;

    public int $tries = 3;

    /** P6-64: below queue retry_after. */
    public int $timeout = 120;

    public array $backoff = [60, 300, 900];

    public function __construct(
        public string $title,
        public string $type,
        public ?string $link = null,
    ) {}

    public function handle(): void
    {
        Notification::create([
            'title' => $this->title,
            'type' => $this->type,
            'link' => $this->link,
            'status' => 0,
        ]);
    }
}

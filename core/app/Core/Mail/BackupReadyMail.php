<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Juwri\Core\Models\StoreBackup;

/**
 * "Your backup is ready" — sent when an async export finishes, if the owner
 * asked to be notified. The email links to the dashboard backup page (which
 * gates the download behind their login), never a raw file URL.
 */
class BackupReadyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** P6-64: bounded retries; timeout stays below queue retry_after. */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public int $timeout = 60;

    public function __construct(public StoreBackup $backup, public ?string $storeName = null) {}

    public function build()
    {
        $url = route('backup.index');
        $size = $this->backup->size_bytes ? number_format($this->backup->size_bytes / 1024, 0).' KB' : '';

        $body = '<p>'.e(___('Your backup for :name is ready to download.', ['name' => $this->storeName ?? ''])).'</p>';
        if ($size) {
            $body .= '<p>'.e(___('Archive size: :size', ['size' => $size])).'</p>';
        }
        if ($this->backup->encrypted) {
            $body .= '<p>'.e(___('This archive is password-protected with the password you set.')).'</p>';
        }
        $body .= '<p><a href="'.$url.'">'.e(___('Open Backup & export')).'</a></p>';

        return $this->subject(___('Your backup is ready'))
            ->view('emails.default', ['body' => $body, 'short_codes' => []]);
    }
}

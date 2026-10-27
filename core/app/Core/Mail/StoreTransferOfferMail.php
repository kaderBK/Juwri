<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Juwri\Core\Models\StoreTransfer;

/**
 * WI-G7: notifies the recipient that an owner offered them a store (or notifies
 * the initiator that their offer was accepted). Links to the dashboard, which
 * gates accept/decline behind the recipient's own login — never acts on its own.
 */
class StoreTransferOfferMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** P6-64: bounded retries; timeout stays below queue retry_after. */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public int $timeout = 60;

    public function __construct(
        public StoreTransfer $transfer,
        public ?string $storeName = null,
        public string $kind = 'offer' // 'offer' | 'accepted'
    ) {}

    public function build()
    {
        $url = route('transfer.index');
        $name = $this->storeName ?? '';

        if ($this->kind === 'accepted') {
            $body = '<p>'.e(___('Your transfer of :name was accepted — it now belongs to the recipient.', ['name' => $name])).'</p>';
            $subject = ___('Your store transfer was accepted');
        } else {
            $body = '<p>'.e(___('You have been offered ownership of the store :name.', ['name' => $name])).'</p>'
                .'<p>'.e(___('Open your dashboard to review exactly what moves (and what does not) and accept or decline. The offer expires in 7 days.')).'</p>';
            $subject = ___('You have been offered a store');
        }
        $body .= '<p><a href="'.$url.'">'.e(___('Open ownership transfers')).'</a></p>';

        return $this->subject($subject)->view('emails.default', ['body' => $body, 'short_codes' => []]);
    }
}

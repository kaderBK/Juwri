<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Juwri\Core\Models\User;

/**
 * A1: "New sign-in to your account" for privileged accounts, sent when a
 * sign-in comes from a browser/device not seen before on that account.
 */
class NewDeviceLogin extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** P6-64: below queue retry_after. */
    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public User $user, public string $ip, public string $userAgent, public string $when) {}

    public function build(): static
    {
        $body = '<p>'.e(___('Your account was just signed in to from a new device.')).'</p>'
            .'<p><strong>'.e(___('When')).':</strong> '.e($this->when).'<br>'
            .'<strong>'.e(___('IP address')).':</strong> '.e($this->ip).'<br>'
            .'<strong>'.e(___('Browser')).':</strong> '.e(mb_substr($this->userAgent, 0, 180)).'</p>'
            .'<p>'.e(___('If this was you, you can ignore this email. If not, change your password now and turn on two-factor authentication.')).'</p>';

        return $this->subject(___('New sign-in to your :site account', ['site' => brand_title()]))
            ->view('emails.default', ['body' => $body, 'short_codes' => []]);
    }
}

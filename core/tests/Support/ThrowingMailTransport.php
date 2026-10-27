<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * P6-192/193/194: a mail transport that always fails like a dead SMTP server.
 * With the test queue on `sync`, a queued mailable runs through it at once, so a
 * caller that does not catch the failure surfaces it.
 */
final class ThrowingMailTransport implements TransportInterface
{
    public static function install(): void
    {
        config(['queue.default' => 'sync']);
        Mail::extend('throwing', fn () => new self);
        config(['mail.mailers.throwing' => ['transport' => 'throwing'], 'mail.default' => 'throwing']);
        Mail::forgetMailers();
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        throw new TransportException('Connection could not be established with host "smtp.test": timed out');
    }

    public function __toString(): string
    {
        return 'throwing://';
    }
}

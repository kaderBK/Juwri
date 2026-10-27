<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Unit\Mails;

use App\Models\GiftCard;
use Juwri\Modules\Loyalty\Mail\GiftCardMail;
use Tests\TestCase;

/** P6-165: a gift card e-mail is sent from the queue worker, so it must carry the language it was bought in. */
class GiftCardMailLocaleTest extends TestCase
{
    public function test_the_mail_keeps_the_language_of_the_request_that_created_it(): void
    {
        app()->setLocale('ar');

        $mail = new GiftCardMail(new GiftCard, 'Drill Kitchen');

        $this->assertSame('ar', $mail->locale);
    }
}

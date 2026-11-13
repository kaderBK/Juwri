<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Unit\Mails;

use App\Mails\CampaignMail;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * P6-176: every marketing campaign carries a List-Unsubscribe header pointing
 * at the GET unsubscribe URL — mailbox junk-folder rules and Gmail's one-click
 * unsubscribe UI both look at this. The header value is the single URL wrapped
 * in angle brackets, no one-click POST component.
 */
class CampaignMailHeaderTest extends TestCase
{
    public function test_adds_list_unsubscribe_header_pointing_at_the_get_url(): void
    {
        Mail::mailer('array')->to('a@example.test')->send(
            new CampaignMail('Spring menu', '<p>Hi there</p>', 'https://example.test/u')
        );

        $sent = Mail::mailer('array')->getSymfonyTransport()->messages()[0]->getOriginalMessage();
        $header = $sent->getHeaders()->get('List-Unsubscribe');

        $this->assertNotNull($header, 'campaign must carry a List-Unsubscribe header');
        $this->assertSame('<https://example.test/u>', $header->getBody());
        $this->assertStringNotContainsString('mailto:', $header->getBody(), 'no mailto: form, only the GET link');
    }
}
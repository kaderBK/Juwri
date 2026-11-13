<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Juwri\Modules\Messaging\Models\SmsLog;
use Juwri\Modules\Messaging\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Transactional SMS (order/otp/status) is exempt from the marketing opt-out + daily
 * cap, but several sends fire from PUBLIC unauthenticated endpoints, so an attacker
 * could pump SMS to a chosen number on the store's credential. A per-(store,phone)
 * hourly cap bounds that without touching a real customer's handful of texts.
 */
class MessagingHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_transactional_sms_is_capped_per_phone(): void
    {
        config(['sms.txn_per_phone_hourly' => 2, 'sms.provider' => 'log']);
        $svc = app(SmsService::class);

        // First two allowed. The `+E164` and bare-digit forms of the SAME number must
        // share ONE bucket (canonical key), so this is 2 sends to one phone, not 2×1.
        $svc->send('+15551230000', 'order #1', 100, 'order', false);
        $svc->send('15551230000', 'order #2', 100, 'order', false); // same number, no +

        // Third to the same number is over the cap → dropped, not sent.
        $svc->send('+15551230000', 'order #3', 100, 'order', false);

        $this->assertSame(1, SmsLog::where('status', 'skipped_rate')->count(), 'the 3rd send to one phone is rate-limited');
        $this->assertSame(0, SmsLog::where('status', 'skipped_rate')->where('to', '<>', '+15551230000')->count());
    }

    public function test_a_different_phone_has_its_own_bucket(): void
    {
        config(['sms.txn_per_phone_hourly' => 1, 'sms.provider' => 'log']);
        $svc = app(SmsService::class);

        $svc->send('+15551110000', 'a', 100, 'order', false); // fills phone A's bucket
        $svc->send('+15559990000', 'b', 100, 'order', false); // different phone → allowed

        $this->assertSame(0, SmsLog::where('status', 'skipped_rate')->count(), 'per-phone, not global');
    }
}

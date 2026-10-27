<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Juwri\Core\Mail\BreachNoticeMail;
use Juwri\Core\Models\BreachNotice;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Security\BreachNotices;
use Tests\TestCase;

/**
 * P1-23: "We will notify you of a personal data breach ... within 48 hours of confirming it" (Terms 9.2, DPA 6.1,
 * security page 8) and 24 hours for a customer established in Kuwait (DPA Schedule 4 D2). security:breach-notice
 * lists the affected customers with their deadlines (dry run by default) and, with --send, mails each account owner
 * once per incident and records it.
 */
class BreachNoticeTest extends TestCase
{
    use RefreshDatabase;

    private function store(array $user = [], ?string $billingCountry = null): Store
    {
        $owner = User::factory()->create(array_merge(['status' => 1], $user));
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug('s'.Str::random(6)), 'color' => '#111',
            'title' => 'S', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $store->refresh();
        DB::table('accounts')->where('id', $store->account_id)->update([
            'owner_user_id' => $owner->id,
            'billing_profile' => $billingCountry !== null ? json_encode(['country' => $billingCountry]) : null,
        ]);

        return $store;
    }

    public function test_dry_run_lists_customers_kuwait_first_with_their_deadlines_and_sends_nothing(): void
    {
        Mail::fake();
        $uk = $this->store(['email' => 'uk@example.test', 'country_code' => 'GB']);
        $kw = $this->store(['email' => 'kw@example.test'], 'Kuwait');

        $rows = app(BreachNotices::class)->recipients([$uk->id, $kw->id]);
        $this->assertSame(['kw@example.test', 'uk@example.test'], $rows->pluck('email')->all(), 'Kuwait (24 h) first');
        $this->assertSame([24, 48], $rows->pluck('hours')->all());
        $this->assertSame(['KW', 'GB'], $rows->pluck('country')->all());

        $this->artisan('security:breach-notice', [
            'incident' => 'INC-2026-001', '--store' => [$uk->id, $kw->id], '--confirmed-at' => '2026-10-04T10:00:00Z',
        ])
            // Ordered, one table row each: Kuwait (24 h) first, then the UK customer (48 h).
            ->expectsOutputToContain('2026-10-05 10:00')
            ->expectsOutputToContain('2026-10-06 10:00')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        Mail::assertNothingSent();
        $this->assertSame(0, BreachNotice::count());
    }

    public function test_send_mails_each_owner_once_records_the_deadline_and_copies_security(): void
    {
        Mail::fake();
        config(['platform.breach_notice.bcc' => 'security@example.test']);
        $uk = $this->store(['email' => 'uk@example.test', 'country_code' => 'GB']);
        $kw = $this->store(['email' => 'kw@example.test', 'country_code' => 'KW']);
        $untouched = $this->store(['email' => 'other@example.test']);

        $args = [
            'incident' => 'INC-2026-002', '--account' => [$uk->account_id, $kw->account_id],
            '--confirmed-at' => '2026-10-04T10:00:00Z', '--send' => true,
            '--summary' => 'A misconfigured export link', '--data' => 'names and phone numbers of customers',
            '--measures' => 'The link was disabled; no action needed', '--contact' => 'security@example.test',
        ];
        $this->artisan('security:breach-notice', $args)->expectsOutputToContain('Sent 2')->assertExitCode(0);

        Mail::assertSent(BreachNoticeMail::class, 2);
        Mail::assertSent(BreachNoticeMail::class, fn ($m) => $m->hasTo('kw@example.test') && $m->hasBcc('security@example.test'));
        Mail::assertNotSent(BreachNoticeMail::class, fn ($m) => $m->hasTo('other@example.test'));

        $kwRow = BreachNotice::where('email', 'kw@example.test')->firstOrFail();
        $this->assertSame(24, $kwRow->notice_hours);
        $this->assertSame('2026-10-05 10:00', $kwRow->deadline_at->utc()->format('Y-m-d H:i'));
        $this->assertNotNull($kwRow->sent_at);
        $this->assertSame(48, BreachNotice::where('email', 'uk@example.test')->value('notice_hours'));

        // Re-running the same incident notifies nobody twice.
        $this->artisan('security:breach-notice', $args)->expectsOutputToContain('already notified 2')->assertExitCode(0);
        Mail::assertSent(BreachNoticeMail::class, 2);
        $this->assertSame(2, BreachNotice::count());
        $this->assertNotNull($untouched->id);
    }

    public function test_send_requires_the_content_the_dpa_names(): void
    {
        Mail::fake();
        $store = $this->store();

        $this->artisan('security:breach-notice', ['incident' => 'INC-3', '--store' => [$store->id], '--send' => true, '--summary' => 'x'])
            ->assertExitCode(2);
        $this->artisan('security:breach-notice', ['incident' => 'INC-3'])->assertExitCode(2); // no scope

        Mail::assertNothingSent();
    }

    public function test_the_notice_is_written_in_the_owner_language(): void
    {
        $mail = (new BreachNoticeMail('INC-9', ['summary' => 's', 'data' => 'd', 'measures' => 'm', 'contact' => 'c'], now()->toImmutable()))->locale('ar');
        $mail->render();
        $this->assertNotSame('', (string) $mail->subject);
        $this->assertDoesNotMatchRegularExpression('/Important security notice/', (string) $mail->subject, 'Arabic subject, not the English source');

        $en = (new BreachNoticeMail('INC-9', ['summary' => 's', 'data' => 'd', 'measures' => 'm', 'contact' => 'c'], now()->toImmutable()))->locale('en');
        $html = $en->render();
        $this->assertStringContainsString('INC-9', $html);
        $this->assertStringContainsString('Important security notice', (string) $en->subject);
    }

    public function test_country_values_resolve_to_kuwait_by_code_or_name(): void
    {
        foreach (['KW', 'kw', 'Kuwait', 'الكويت', 'KWT'] as $v) {
            $this->assertSame('KW', BreachNotices::country($v), $v);
        }
        $this->assertSame('SA', BreachNotices::country('sa'));
        $this->assertNull(BreachNotices::country('Saudi Arabia'));
        $this->assertNull(BreachNotices::country(''));
    }
}

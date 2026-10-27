<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\CallLog;
use Juwri\Modules\Agents\Models\PhoneLine;
use Juwri\Modules\Agents\Services\Telephony\VoiceRetention;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-09 V-18: transcript / audio retention purge per phone line.
 */
class VoiceRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 999],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function line(Store $r, array $extra = []): PhoneLine
    {
        return PhoneLine::create(array_merge([
            'store_id' => $r->id, 'provider' => 'twilio', 'number' => '+1555'.Str::random(8), 'active' => true,
        ], $extra));
    }

    private function makeCall(Store $r, ?PhoneLine $line, Carbon $createdAt, array $extra = []): CallLog
    {
        return CallLog::create(array_merge([
            'store_id' => $r->id, 'call_sid' => 'CA-'.Str::random(14), 'provider' => 'twilio',
            'from_number' => '+1555000000', 'outcome' => 'order',
            'transcript' => [['role' => 'caller', 'text' => 'one pizza']],
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ], $line !== null ? ['phone_line_id' => $line->id] : [], $extra));
    }

    public function test_old_audio_file_deleted_and_path_nulled_young_kept(): void
    {
        $r = $this->storeFixture();
        $line = $this->line($r); // ksa region: default 30 / 7 days.
        $old = $this->makeCall($r, $line, Carbon::parse('2026-09-11 12:00:00'), ['audio_path' => 'accounts/1/old.mp3', 'audio_disk' => 'local']);
        $young = $this->makeCall($r, $line, Carbon::parse('2026-09-29 12:00:00'), ['audio_path' => 'accounts/1/young.mp3', 'audio_disk' => 'local']);
        Storage::disk('local')->put('accounts/1/old.mp3', 'o');
        Storage::disk('local')->put('accounts/1/young.mp3', 'y');

        $result = app(VoiceRetention::class)->purge(Carbon::parse('2026-10-01 12:00:00'));

        $this->assertSame(['transcripts' => 0, 'audio' => 1], $result); // 20d > 7d; transcript (20d < 30d) kept.
        Storage::disk('local')->assertMissing('accounts/1/old.mp3');
        Storage::disk('local')->assertExists('accounts/1/young.mp3');
        $this->assertNull($old->fresh()->audio_path);
        $this->assertNull($old->fresh()->audio_disk);
        $this->assertNotNull($old->fresh()->audio_purged_at);
        $this->assertNotNull($old->fresh()->transcript);
        $this->assertNotNull($young->fresh()->audio_path);
        $this->assertNull($young->fresh()->audio_purged_at);
    }

    public function test_transcript_purged_after_setting_days(): void
    {
        $r = $this->storeFixture();
        $line = $this->line($r, ['transcript_retention_days' => 5, 'audio_retention_days' => 7]);
        $call = $this->makeCall($r, $line, Carbon::parse('2026-09-20 12:00:00')); // 11 days old.

        $result = app(VoiceRetention::class)->purge(Carbon::parse('2026-10-01 12:00:00'));

        $this->assertSame(['transcripts' => 1, 'audio' => 0], $result);
        $this->assertNull($call->fresh()->transcript);
        $this->assertNotNull($call->fresh()->transcript_purged_at);
    }

    public function test_region_cap_beats_a_longer_store_setting(): void
    {
        $r = $this->storeFixture();
        $line = $this->line($r, ['recording_region' => 'other', 'transcript_retention_days' => 365, 'audio_retention_days' => 180]);
        $call = $this->makeCall($r, $line, Carbon::parse('2026-08-02 12:00:00'), ['audio_path' => 'x.mp3', 'audio_disk' => 'local']);
        Storage::disk('local')->put('x.mp3', 'x');

        $result = app(VoiceRetention::class)->purge(Carbon::parse('2026-10-01 12:00:00'));
        // 60d old: > the 'other' audio cap (30) despite the store's 180, so purged; < the transcript cap (90), kept.
        $this->assertSame(['transcripts' => 0, 'audio' => 1], $result);
        $this->assertNull($call->fresh()->audio_path);
        $this->assertNotNull($call->fresh()->transcript);
    }

    public function test_second_run_is_a_noop(): void
    {
        $r = $this->storeFixture();
        $line = $this->line($r);
        $call = $this->makeCall($r, $line, Carbon::parse('2026-08-17 12:00:00') /* 45d old */, ['audio_path' => 'a.mp3', 'audio_disk' => 'local']);
        Storage::disk('local')->put('a.mp3', 'a');
        $service = app(VoiceRetention::class);

        $first = $service->purge(Carbon::parse('2026-10-01 12:00:00'));
        $second = $service->purge(Carbon::parse('2026-10-01 12:00:00'));

        $this->assertSame(['transcripts' => 1, 'audio' => 1], $first);
        $this->assertSame(['transcripts' => 0, 'audio' => 0], $second);
        $this->assertNotNull($call->fresh()->transcript_purged_at);
        Storage::disk('local')->assertMissing('a.mp3');
    }

    public function test_missing_audio_disk_falls_back_to_the_configured_recordings_disk(): void
    {
        config(['voice.recordings_disk' => 'rec']);
        Storage::fake('rec');
        $r = $this->storeFixture();
        $this->makeCall($r, $this->line($r), Carbon::parse('2026-08-17 12:00:00'), ['audio_path' => 'b.mp3']);
        Storage::disk('rec')->put('b.mp3', 'b');

        app(VoiceRetention::class)->purge(Carbon::parse('2026-10-01 12:00:00'));

        Storage::disk('rec')->assertMissing('b.mp3');
    }

    public function test_fully_purged_rows_are_not_selected_again(): void
    {
        $r = $this->storeFixture();
        $call = $this->makeCall($r, $this->line($r), Carbon::parse('2026-08-17 12:00:00'), [
            'transcript' => null, 'transcript_purged_at' => now(), 'audio_path' => null, 'audio_purged_at' => now(),
        ]);

        $counts = app(VoiceRetention::class)->purge(Carbon::parse('2026-10-01 12:00:00'));

        $this->assertSame(['transcripts' => 0, 'audio' => 0], $counts);
        $this->assertNotNull($call->fresh()->audio_purged_at);
    }

    public function test_another_stores_call_is_judged_by_its_own_line_settings(): void
    {
        $rA = $this->storeFixture();
        $lineA = $this->line($rA, ['audio_retention_days' => 2]);
        $callA = $this->makeCall($rA, $lineA, Carbon::parse('2026-09-26 12:00:00'), ['audio_path' => 'a.mp3', 'audio_disk' => 'local']);
        $rB = $this->storeFixture();
        $lineB = $this->line($rB, ['audio_retention_days' => 20]);
        $callB = $this->makeCall($rB, $lineB, Carbon::parse('2026-09-26 12:00:00'), ['audio_path' => 'b.mp3', 'audio_disk' => 'local']);
        Storage::disk('local')->put('a.mp3', 'a');
        Storage::disk('local')->put('b.mp3', 'b');

        $result = app(VoiceRetention::class)->purge(Carbon::parse('2026-10-01 12:00:00'));
        // 5d old: A's effective audio = min(2, 7) = 2 -> purged; B's = min(20, 7) = 7 -> kept.
        $this->assertSame(['transcripts' => 0, 'audio' => 1], $result);
        $this->assertNull($callA->fresh()->audio_path);
        $this->assertNotNull($callB->fresh()->audio_path);
        Storage::disk('local')->assertMissing('a.mp3');
        Storage::disk('local')->assertExists('b.mp3');
    }

    public function test_call_without_a_line_uses_the_strictest_defaults(): void
    {
        $r = $this->storeFixture();
        $old = $this->makeCall($r, null, Carbon::parse('2026-09-20 12:00:00') /* 11d: > default audio 7 */, ['audio_path' => 'c.mp3', 'audio_disk' => 'local']);
        $young = $this->makeCall($r, null, Carbon::parse('2026-09-11 12:00:00') /* 20d: < default transcript 30 */);
        Storage::disk('local')->put('c.mp3', 'c');

        $result = app(VoiceRetention::class)->purge(Carbon::parse('2026-10-01 12:00:00'));

        $this->assertSame(['transcripts' => 0, 'audio' => 1], $result);
        $this->assertNull($old->fresh()->audio_path);
        $this->assertNotNull($young->fresh()->transcript);
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * whatsapp:audit-numbers — a read-only report of undiallable numbers.
 * The one hard guarantee: it never writes to the data it audits.
 */
class WhatsAppAuditNumbersTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $phone = '123'): Store
    {
        $owner = User::factory()->create(['status' => 1]);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => 'r-' . Str::lower(Str::random(8)),
            'color' => '#111',
            'title' => 'WA Audit Test',
            'sub_title' => 't',
            'description' => 't',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 St',
            'phone' => $phone,
        ]);
    }

    private function branch(Store $store, string $whatsapp): Branch
    {
        return Branch::create([
            'store_id' => $store->id,
            'name' => 'Main',
            'whatsapp' => $whatsapp,
            'position' => 0,
        ]);
    }

    public function test_classifies_every_problem_shape(): void
    {
        $clean = $this->store('447700900201');
        $this->branch($clean, '+44 7700 900201');           // fine — punctuation strips

        $leadingZero = $this->store('447000000001');
        $this->branch($leadingZero, '0529000111');          // leading-0

        $junk = $this->store('447000000002');
        $this->branch($junk, 'لايوجد');                     // empty after normalize

        // Store 777's real shape: double-encoded Arabic labels + two numbers.
        $mojibake = $this->store('447000000003');
        $this->branch($mojibake, 'ÙˆØ§ØªØ³Ø§Ø¨ 0529000111 Ø§ØªØµØ§Ù„ 0509000222');

        $this->artisan('whatsapp:audit-numbers')
            ->expectsOutputToContain('leading-0')
            ->expectsOutputToContain('empty')
            ->expectsOutputToContain('mojibake')
            ->expectsOutputToContain('multiple')
            ->expectsOutputToContain('too-long')
            ->assertSuccessful();
    }

    public function test_clean_numbers_produce_no_rows(): void
    {
        $clean = $this->store('447700900201');
        $this->branch($clean, '+44 7700 900201');
        // An Arabic-numeral number is VALID input, not a problem class.
        $arabic = $this->store('447000000004');
        $this->branch($arabic, '٩٦٦٥٤٦٠٠٠٣٣٣');

        $this->artisan('whatsapp:audit-numbers')
            ->expectsOutputToContain('No problem numbers found.')
            ->assertSuccessful();
    }

    public function test_class_filter_and_unknown_class(): void
    {
        $s = $this->store('447000000005');
        $this->branch($s, '0529000111');

        $this->artisan('whatsapp:audit-numbers', ['--class' => 'mojibake'])
            ->expectsOutputToContain('No problem numbers found.')
            ->assertSuccessful();

        $this->artisan('whatsapp:audit-numbers', ['--class' => 'nope'])
            ->assertFailed();
    }

    public function test_audit_is_strictly_read_only(): void
    {
        $s = $this->store('junk text');
        $b = $this->branch($s, 'ÙˆØ§ØªØ³Ø§Ø¨ 0529000111 Ø§ØªØµØ§Ù„ 0509000222');

        $this->artisan('whatsapp:audit-numbers')->assertSuccessful();

        $this->assertSame('ÙˆØ§ØªØ³Ø§Ø¨ 0529000111 Ø§ØªØµØ§Ù„ 0509000222', $b->fresh()->whatsapp);
        $this->assertSame('junk text', $s->fresh()->phone);
    }

    public function test_csv_is_not_written_from_the_test_suite(): void
    {
        // Mirrors the WhatsAppMigrateLegacy report guard: the suite must not
        // leave artifacts on disk.
        $s = $this->store('447000000006');
        $this->branch($s, '0529000111');

        $path = storage_path('app/wa-audit-test-guard.csv');
        @unlink($path);

        $this->artisan('whatsapp:audit-numbers', ['--csv' => $path])->assertSuccessful();

        $this->assertFileDoesNotExist($path);
    }
}

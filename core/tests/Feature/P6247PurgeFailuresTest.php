<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Services\Account\AccountDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Console\Commands\PurgeDeletedAccountsCommand;
use Juwri\Core\Models\AccountDeletion;
use Juwri\Core\Models\PlatformAlert;
use Juwri\Core\Models\User;
use Tests\TestCase;

/** P6-247: a failing purge is reported, counted, and never blocks the rows behind it. */
class P6247PurgeFailuresTest extends TestCase
{
    use RefreshDatabase;

    private function due(int $daysAgo): AccountDeletion
    {
        $user = User::factory()->create();

        return AccountDeletion::create([
            'user_id' => $user->id, 'status' => AccountDeletion::PENDING,
            'requested_at' => now()->subDays($daysAgo + 30), 'purge_after' => now()->subDays($daysAgo),
        ]);
    }

    /** @param  list<int>  $failIds */
    private function fakeService(array $failIds): void
    {
        $this->app->instance(AccountDeletionService::class, new class($failIds) extends AccountDeletionService
        {
            /** @param list<int> $failIds */
            public function __construct(private array $failIds) {}

            public function purge(AccountDeletion $deletion): array
            {
                if (in_array($deletion->id, $this->failIds, true)) {
                    throw new \RuntimeException('boom');
                }
                $deletion->forceFill(['status' => AccountDeletion::PURGED, 'purged_at' => now()])->save();

                return ['stores' => [], 'tables' => []];
            }
        });
    }

    public function test_one_failing_purge_does_not_stop_the_others_and_exits_non_zero_with_an_alert(): void
    {
        $bad = $this->due(5);
        $good = $this->due(1);
        $this->fakeService([$bad->id]);

        $this->artisan('accounts:purge-deleted')->assertExitCode(1);

        $this->assertSame(AccountDeletion::PURGED, $good->fresh()->status);
        $this->assertSame(AccountDeletion::PENDING, $bad->fresh()->status);
        $this->assertSame(1, $bad->fresh()->purge_report['failed_attempts']);
        $this->assertTrue(PlatformAlert::where('kind', 'account-deletion')->where('message', 'like', "%#{$bad->id}%")->exists());
    }

    public function test_a_row_is_marked_failed_after_max_attempts_and_no_longer_takes_a_slot(): void
    {
        $bad = $this->due(5);
        $good = $this->due(1);
        $bad->forceFill(['purge_report' => ['failed_attempts' => PurgeDeletedAccountsCommand::MAX_ATTEMPTS - 1]])->save();
        $this->fakeService([$bad->id]);

        $this->artisan('accounts:purge-deleted')->assertExitCode(1);
        $this->assertSame(AccountDeletion::FAILED, $bad->fresh()->status);

        $late = $this->due(0);
        $this->artisan('accounts:purge-deleted', ['--limit' => 1])->assertExitCode(0);
        $this->assertSame(AccountDeletion::FAILED, $bad->fresh()->status);
        $this->assertSame(AccountDeletion::PURGED, $late->fresh()->status);
        $this->assertSame(AccountDeletion::PURGED, $good->fresh()->status);
    }

    public function test_exhausted_rows_do_not_use_the_limit(): void
    {
        $stuck = [];
        foreach ([9, 8, 7] as $d) {
            $row = $this->due($d);
            $row->forceFill(['purge_report' => ['failed_attempts' => PurgeDeletedAccountsCommand::MAX_ATTEMPTS]])->save();
            $stuck[] = $row;
        }
        $good = $this->due(1);
        $this->fakeService([]);

        $this->artisan('accounts:purge-deleted', ['--limit' => 1])->assertExitCode(0);

        $this->assertSame(AccountDeletion::PURGED, $good->fresh()->status);
        foreach ($stuck as $row) {
            $this->assertSame(AccountDeletion::FAILED, $row->fresh()->status);
        }
    }
}

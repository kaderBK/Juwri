<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform\Admin;

use App\Jobs\DeleteUsersJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Feature\Admin\InteractsWithAdminPanel;
use Tests\TestCase;

/** P6-119e: "delete all filtered users" is a queued, idempotent job with progress; auth stays admin-only. */
class UsersBulkDeleteJobTest extends TestCase
{
    use InteractsWithAdminPanel;
    use RefreshDatabase;

    private ?User $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->bootAdminPanel();
        Cache::flush();
    }

    private function filteredDelete(array $extra = []): TestResponse
    {
        $this->admin ??= $this->admin();

        return $this->actingAs($this->admin)->postJson(route('admin.users.deleteFiltered'), ['restaurants' => '0'] + $extra);
    }

    public function test_filtered_delete_is_queued_not_run_in_the_request(): void
    {
        Bus::fake();
        $a = $this->plainUser();
        $res = $this->filteredDelete()->assertStatus(202)->assertJson(['success' => true, 'queued' => true]);

        Bus::assertDispatched(DeleteUsersJob::class, fn (DeleteUsersJob $j) => in_array($a->id, $j->ids, true) && $j->queue === 'heavy');
        $this->assertNotNull($a->fresh(), 'nothing is deleted inside the web request');
        $this->assertSame('queued', DeleteUsersJob::status($res->json('token'))['state']);
    }

    public function test_same_selection_is_not_queued_twice_while_in_flight(): void
    {
        Bus::fake();
        $this->plainUser();
        $this->filteredDelete()->assertStatus(202);
        $this->filteredDelete()->assertStatus(202);
        Bus::assertDispatchedTimes(DeleteUsersJob::class, 1);
    }

    public function test_job_deletes_users_with_their_stores_and_reports_progress(): void
    {
        $admin = $this->admin();
        $a = $this->plainUser();
        $b = $this->plainUser();
        $keep = $this->plainUser();
        $store = Store::factory()->create(['user_id' => $a->id]);

        // sync queue in tests: the job runs inside the request.
        $res = $this->actingAs($admin)->postJson(route('admin.users.deleteFiltered'), ['search' => ['value' => 'zzz-no-match']]);
        $res->assertStatus(422);

        $ids = [$a->id, $b->id];
        $token = DeleteUsersJob::tokenFor($ids);
        DeleteUsersJob::markQueued($token, 2);
        DeleteUsersJob::dispatchSync($token, $ids);

        $this->assertNull($a->fresh());
        $this->assertNull($b->fresh());
        $this->assertNull(Store::find($store->id));
        $this->assertNotNull($keep->fresh());
        $this->assertNotNull($admin->fresh());
        $this->assertSame(['state' => 'done', 'total' => 2, 'done' => 2, 'failed' => 0, 'message' => 'Deleted'], DeleteUsersJob::status($token));

        $this->actingAs($admin)->getJson(route('admin.users.deleteFilteredStatus', $token))
            ->assertOk()->assertJson(['success' => true, 'state' => 'done', 'done' => 2]);
    }

    public function test_job_is_idempotent_and_resumes_after_a_partial_run(): void
    {
        $a = $this->plainUser();
        $b = $this->plainUser();
        $ids = [$a->id, $b->id];
        $token = DeleteUsersJob::tokenFor($ids);

        $a->delete(); // an earlier attempt got this far
        DeleteUsersJob::dispatchSync($token, $ids);
        DeleteUsersJob::dispatchSync($token, $ids); // a retry / duplicate is harmless

        $this->assertNull($b->fresh());
        $status = DeleteUsersJob::status($token);
        $this->assertSame('done', $status['state']);
        $this->assertSame(2, $status['done']);
    }

    public function test_status_endpoint_validates_the_token(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->getJson(route('admin.users.deleteFilteredStatus', 'not-a-token'))->assertNotFound();
        $this->actingAs($admin)->getJson(route('admin.users.deleteFilteredStatus', str_repeat('a', 32)))->assertNotFound();
    }

    public function test_another_tenants_owner_cannot_delete_users_or_read_status(): void
    {
        Bus::fake();
        $victim = $this->plainUser();
        Store::factory()->create(['user_id' => $victim->id]);
        $otherOwner = $this->plainUser();
        Store::factory()->create(['user_id' => $otherOwner->id]);
        $token = DeleteUsersJob::tokenFor([$victim->id]);
        DeleteUsersJob::markQueued($token, 1);

        $this->assertAdminOnly('post', route('admin.users.deleteFiltered'), ['restaurants' => '1']);
        $this->assertAdminOnly('post', route('admin.users.delete'), ['ids' => [$victim->id]]);
        $this->assertAdminOnly('get', route('admin.users.deleteFilteredStatus', $token));
        $this->actingAs($otherOwner)->post(route('admin.users.deleteFiltered'), ['restaurants' => '1'])->assertRedirect(route('login'));

        Bus::assertNotDispatched(DeleteUsersJob::class);
        $this->assertNotNull($victim->fresh());
    }
}

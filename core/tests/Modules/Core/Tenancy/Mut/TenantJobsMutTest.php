<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Tenancy\Mut;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Mockery;
use Tests\TestCase;

/**
 * P6-27: a queued job runs in exactly the tenant that dispatched it (or none for platform jobs) and gives the
 * worker / request its previous context back afterwards.
 */
class TenantJobsMutTest extends TestCase
{
    use RefreshDatabase;
    use TenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenants();
    }

    protected function tearDown(): void
    {
        Context::forgetHidden(TenantJobs::KEY);
        TenantContext::current()->end();
        Mockery::close();
        parent::tearDown();
    }

    private function job(?string $displayName, string $resolved = 'App\\Jobs\\SomeTenantJob'): Job
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn($displayName === null ? [] : ['displayName' => $displayName]);
        $job->shouldReceive('resolveName')->andReturn($resolved);

        return $job;
    }

    public function test_tenant_job_runs_as_the_carried_account_then_restores(): void
    {
        $ctx = TenantContext::current();
        $ctx->actAs((int) $this->ownerA->account_id);
        Context::addHidden(TenantJobs::KEY, ['account_id' => (string) $this->ownerB->account_id, 'store_ids' => [(string) $this->storeB->id]]);

        TenantJobs::processing($this->job(TenantJobs::TENANT[0]));
        $this->assertSame((int) $this->ownerB->account_id, $ctx->accountId());
        $this->assertSame([$this->storeB->id], $ctx->storeIds());
        $this->assertSame([$this->storeB->id], $this->visibleOrderStores());

        TenantJobs::finished();
        $this->assertSame((int) $this->ownerA->account_id, $ctx->accountId());
        $this->assertSame([$this->storeA1->id, $this->storeA2->id], $this->visibleOrderStores());
    }

    public function test_account_wide_subject_and_missing_fields(): void
    {
        $ctx = TenantContext::current();
        Context::addHidden(TenantJobs::KEY, ['account_id' => $this->ownerA->account_id, 'store_ids' => null]);
        TenantJobs::processing($this->job(null));
        $this->assertSame([$this->storeA1->id, $this->storeA2->id], $this->visibleOrderStores());
        $this->assertNull($ctx->storeIds());
        TenantJobs::finished();

        // A subject without an account sees nothing (fail closed), and non-array store ids mean "every store".
        Context::addHidden(TenantJobs::KEY, ['store_ids' => 'nope']);
        TenantJobs::processing($this->job(null));
        $this->assertTrue($ctx->active());
        $this->assertNull($ctx->accountId());
        $this->assertNull($ctx->storeIds());
        $this->assertSame([], $this->visibleOrderStores());
        TenantJobs::finished();
        $this->assertFalse($ctx->active());
        $this->assertCount(3, $this->visibleOrderStores());
    }

    public function test_platform_jobs_run_unscoped_even_inside_a_tenant(): void
    {
        $ctx = TenantContext::current();
        foreach (TenantJobs::PLATFORM as $class) {
            $ctx->actAs((int) $this->ownerA->account_id, [$this->storeA1->id]);
            Context::addHidden(TenantJobs::KEY, ['account_id' => $this->ownerA->account_id, 'store_ids' => [$this->storeA1->id]]);
            $this->assertTrue(TenantJobs::isPlatform($this->job($class)), $class);

            TenantJobs::processing($this->job($class));
            $this->assertFalse($ctx->active(), $class);
            $this->assertCount(3, $this->visibleOrderStores(), $class);

            TenantJobs::finished();
            $this->assertSame([$this->storeA1->id], $this->visibleOrderStores(), $class.' restores');
        }
        // Platform detection also works through resolveName() when the payload has no displayName.
        $this->assertTrue(TenantJobs::isPlatform($this->job(null, TenantJobs::PLATFORM[0])));
    }

    public function test_job_without_carried_subject_runs_unscoped(): void
    {
        $ctx = TenantContext::current();
        $ctx->actAs((int) $this->ownerB->account_id);
        Context::forgetHidden(TenantJobs::KEY);
        TenantJobs::processing($this->job(TenantJobs::TENANT[0]));
        $this->assertFalse($ctx->active());
        $this->assertTrue($ctx->exportState()['resolved'], 'pinned to "no tenant", not pending');
        $this->assertCount(3, $this->visibleOrderStores());
        TenantJobs::finished();
        $this->assertSame([$this->storeB->id], $this->visibleOrderStores());
    }

    public function test_nested_sync_jobs_unwind_in_order_and_extra_finish_is_harmless(): void
    {
        $ctx = TenantContext::current();
        $ctx->actAs((int) $this->ownerA->account_id);
        Context::addHidden(TenantJobs::KEY, ['account_id' => $this->ownerB->account_id, 'store_ids' => null]);
        TenantJobs::processing($this->job(TenantJobs::TENANT[0]));
        Context::forgetHidden(TenantJobs::KEY);
        TenantJobs::processing($this->job(TenantJobs::TENANT[0]));
        $this->assertCount(3, $this->visibleOrderStores());
        TenantJobs::finished();
        $this->assertSame([$this->storeB->id], $this->visibleOrderStores());
        TenantJobs::finished();
        $this->assertSame([$this->storeA1->id, $this->storeA2->id], $this->visibleOrderStores());
        TenantJobs::finished(); // empty stack: no change
        $this->assertSame([$this->storeA1->id, $this->storeA2->id], $this->visibleOrderStores());
    }

    public function test_dispatch_carries_the_subject_only_inside_a_tenant(): void
    {
        $ctx = TenantContext::current();
        $ctx->actAs((int) $this->ownerB->account_id, [$this->storeB->id]);
        $this->assertSame(['account_id' => (int) $this->ownerB->account_id, 'store_ids' => [$this->storeB->id]], $this->carried());

        // A stale hidden subject is dropped when dispatching outside any tenant.
        $ctx->end();
        Context::addHidden(TenantJobs::KEY, ['account_id' => $this->ownerA->account_id]);
        $this->assertNull($this->carried());
    }

    private function carried(): mixed
    {
        $hidden = Context::dehydrate()['hidden'] ?? [];

        return isset($hidden[TenantJobs::KEY]) ? unserialize($hidden[TenantJobs::KEY]) : null;
    }

    public function test_lists_and_job_class(): void
    {
        foreach (TenantJobs::PLATFORM as $class) {
            $this->assertTrue(class_exists($class), $class);
            $this->assertContains($class, TenantJobs::platform());
            $this->assertNotContains($class, TenantJobs::tenant());
        }
        foreach (TenantJobs::TENANT as $class) {
            $this->assertTrue(class_exists($class), $class);
            $this->assertContains($class, TenantJobs::tenant());
            $this->assertFalse(TenantJobs::isPlatform($this->job($class)));
        }
        $this->assertSame(array_values(TenantJobs::platform()), TenantJobs::platform());
        $this->assertSame(count(array_unique(TenantJobs::tenant())), count(TenantJobs::tenant()));
        $this->assertSame('X\\Y', TenantJobs::jobClass($this->job('X\\Y', 'Other')));
        $this->assertSame('Other', TenantJobs::jobClass($this->job(null, 'Other')));
    }
}

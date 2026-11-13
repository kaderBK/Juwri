<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Modules\Integrations\Jobs\CommitSwitchImportJob;
use Juwri\Modules\Integrations\Models\SwitchImport;
use Juwri\Modules\Integrations\Services\Switching\SwitchImports;
use RuntimeException;
use Tests\TestCase;

/**
 * P4-22c: the switching screen shows a live progress element for a queued import (with the poll URL and the queued
 * label, and no "Import now" form), flips to "committed" once the job is done, and offers a resume form ("Continue the
 * import") when the import failed.
 */
class SwitchingProgressUiTest extends TestCase
{
    use RefreshDatabase;

    public static bool $boom = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        self::$boom = false;
    }

    protected function tearDown(): void
    {
        TenantContext::current()->clear();
        parent::tearDown();
    }

    private function owner(): User
    {
        return User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
    }

    private function store(User $owner): Store
    {
        $store = Store::create(['user_id' => $owner->id, 'slug' => 'sp-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Switch',
            'sub_title' => 't', 'description' => 'd', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1']);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'SAR');

        return $store;
    }

    private function upload(User $owner, Store $store, string $dataset, string $csv): SwitchImport
    {
        $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->post(route('integrations.switch.preview'), ['dataset' => $dataset, 'source' => 'file', 'file' => UploadedFile::fake()->createWithContent('rows.csv', $csv)])
            ->assertRedirect()->assertSessionHasNoErrors();

        return SwitchImport::query()->latest('id')->firstOrFail();
    }

    /** @return TestResponse<\Symfony\Component\HttpFoundation\Response> */
    private function commitHttp(User $owner, Store $store, SwitchImport $import): TestResponse
    {
        return $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])->post(route('integrations.switch.commit', $import->id))->assertRedirect();
    }

    /** @return array<string, mixed> */
    private function progress(User $owner, Store $store, SwitchImport $import): array
    {
        return (array) $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->getJson(route('integrations.switch.progress', $import->id))->assertOk()->json();
    }

    private function show(User $owner, Store $store, SwitchImport $import): string
    {
        return (string) $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->get(route('integrations.switch.show', $import->id))->assertOk()->getContent();
    }

    /** A menu CSV with a header and $n distinct rows (each worth a create). */
    private function menuCsv(int $n): string
    {
        $csv = "name,sku,category,price\n";
        for ($i = 1; $i <= $n; $i++) {
            $csv .= "Item {$i},SKU-{$i},Cat,{$i}.05\n";
        }

        return $csv;
    }

    /** A customers CSV whose row $boomAt is named "Boom" so the failing hook can throw on it. */
    private function customerCsv(int $n, int $boomAt): string
    {
        $csv = "name,phone\n";
        for ($i = 1; $i <= $n; $i++) {
            $csv .= ($i === $boomAt ? 'Boom' : "Guest {$i}").',+9665'.sprintf('%08d', $i)."\n";
        }

        return $csv;
    }

    private function runJob(int $importId, int $storeId): void
    {
        (new CommitSwitchImportJob($importId, $storeId))->handle(app(SwitchImports::class));
        TenantContext::current()->clear();
    }

    public function test_a_queued_import_shows_the_progress_element_and_waiting_label_without_the_import_now_form(): void
    {
        $alice = $this->owner();
        $store = $this->store($alice);
        $import = $this->upload($alice, $store, 'menu', $this->menuCsv(320));

        Queue::fake();
        $this->commitHttp($alice, $store, $import)->assertSessionHas('success');

        $page = $this->show($alice, $store, $import);
        $this->assertStringContainsString('data-switch-progress', $page);
        $this->assertStringContainsString('data-progress-url="'.route('integrations.switch.progress', $import->id).'"', $page);
        $this->assertStringContainsString('data-status="queued"', $page);
        $this->assertStringContainsString(___('Waiting to start…'), $page);
        $this->assertStringNotContainsString('data-switch-commit', $page, 'a queued import has no "Import now" form');
    }

    public function test_after_the_job_runs_the_progress_json_and_the_page_report_committed(): void
    {
        $alice = $this->owner();
        $store = $this->store($alice);
        $import = $this->upload($alice, $store, 'menu', $this->menuCsv(320));

        Queue::fake();
        $this->commitHttp($alice, $store, $import);
        $this->runJob($import->id, (int) $store->id);

        $p = $this->progress($alice, $store, $import);
        $this->assertSame('committed', $p['status']);
        $this->assertSame(320, $p['rows_done']);
        $this->assertSame(320, $p['rows_total']);
        $this->assertStringContainsString('data-status="committed"', $this->show($alice, $store, $import));
    }

    public function test_a_failed_import_shows_the_resume_form(): void
    {
        $n = SwitchImports::SYNC_ROWS + 20;
        Customer::creating(function (Customer $c) {
            if (self::$boom && $c->name === 'Boom') {
                throw new RuntimeException('database went away');
            }
        });
        $alice = $this->owner();
        $store = $this->store($alice);
        $import = $this->upload($alice, $store, 'customers', $this->customerCsv($n, SwitchImports::CHUNK + 50));

        Queue::fake();
        $this->commitHttp($alice, $store, $import);
        self::$boom = true;
        $this->runJob($import->id, (int) $store->id);
        $this->assertSame('failed', $import->refresh()->status);

        $page = $this->show($alice, $store, $import);
        $this->assertStringContainsString('action="'.route('integrations.switch.commit', $import->id).'"', $page);
        $this->assertStringContainsString('data-switch-resume', $page);
        $this->assertStringContainsString(___('Continue the import'), $page);
    }
}

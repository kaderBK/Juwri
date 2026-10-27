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
use Juwri\Modules\Menu\Models\Menu;
use RuntimeException;
use Tests\TestCase;

/**
 * P4-22b: a big switching import commits on the queue — once (double click, duplicate dispatch, second worker), in
 * the import's own store only, in chunks with exact prices, with progress on the row and a clear "failed" status
 * that resumes where it stopped without repeating a row.
 */
class SwitchingQueuedCommitTest extends TestCase
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
        $store = Store::create(['user_id' => $owner->id, 'slug' => 'sq-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Switch',
            'sub_title' => 't', 'description' => 'd', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1']);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'SAR');

        return $store;
    }

    private function upload(User $owner, Store $store, string $dataset, string $csv): SwitchImport
    {
        $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->post(route('integrations.switch.preview'), ['dataset' => $dataset, 'source' => 'file', 'file' => UploadedFile::fake()->createWithContent('big.csv', $csv)])
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

    private function menuCsv(int $n): string
    {
        $csv = "name,sku,category,price\n";
        for ($i = 1; $i <= $n; $i++) {
            $csv .= "Item {$i},SKU-{$i},Cat,{$i}.05\n";
        }

        return $csv;
    }

    private function runJob(int $importId, int $storeId): void
    {
        (new CommitSwitchImportJob($importId, $storeId))->handle(app(SwitchImports::class));
        TenantContext::current()->clear();
    }

    public function test_a_big_menu_is_queued_once_imported_once_with_exact_prices_and_progress(): void
    {
        $n = SwitchImports::SYNC_ROWS + 30;
        $alice = $this->owner();
        $store = $this->store($alice);
        $import = $this->upload($alice, $store, 'menu', $this->menuCsv($n));

        Queue::fake();
        $this->commitHttp($alice, $store, $import)->assertSessionHas('success');
        $this->commitHttp($alice, $store, $import)->assertSessionHas('error'); // double click
        CommitSwitchImportJob::dispatch($import->id, (int) $store->id);     // duplicate dispatch: unique per import
        Queue::assertPushed(CommitSwitchImportJob::class, 1);
        $this->assertSame(['queued', 0, $n], array_values(array_intersect_key($this->progress($alice, $store, $import), array_flip(['status', 'rows_done', 'rows_total']))));
        $this->assertSame(0, Menu::query()->where('store_id', $store->id)->count(), 'nothing written before the job runs');

        $this->runJob($import->id, (int) $store->id);
        $this->runJob($import->id, (int) $store->id); // a retried job does nothing

        $this->assertSame($n, Menu::query()->where('store_id', $store->id)->count());
        $this->assertSame(705, (int) Menu::query()->where('store_id', $store->id)->where('external_sku', 'SKU-7')->sole()->price_minor);
        $expected = 0;
        for ($i = 1; $i <= $n; $i++) {
            $expected += $i * 100 + 5;
        }
        $this->assertSame($expected, (int) Menu::query()->where('store_id', $store->id)->sum('price_minor'), 'every price exact in minor units');

        $p = $this->progress($alice, $store, $import);
        $this->assertSame(['committed', $n, $n, $n], [$p['status'], $p['rows_done'], $p['rows_total'], $p['counts']['create']]);
        $this->assertNull($import->refresh()->rows, 'the rows are cleared after the commit');
    }

    public function test_a_job_for_one_store_cannot_touch_another_store_s_import(): void
    {
        $alice = $this->owner();
        $store = $this->store($alice);
        $bob = $this->owner();
        $bobStore = $this->store($bob);
        $import = $this->upload($alice, $store, 'menu', $this->menuCsv(SwitchImports::SYNC_ROWS + 5));
        Queue::fake();
        $this->commitHttp($alice, $store, $import);

        $this->runJob($import->id, (int) $bobStore->id); // forged payload: Bob's store with Alice's import
        $this->assertSame('queued', $import->refresh()->status);
        $this->assertSame(0, Menu::query()->count());

        $this->actingAs($bob)->withSession(['dashboard_store_id' => $bobStore->id])
            ->getJson(route('integrations.switch.progress', $import->id))->assertNotFound();

        $this->runJob($import->id, (int) $store->id);
        $this->assertSame([SwitchImports::SYNC_ROWS + 5, 0], [Menu::query()->where('store_id', $store->id)->count(), Menu::query()->where('store_id', $bobStore->id)->count()]);
    }

    public function test_a_failure_leaves_a_failed_status_and_importing_again_resumes_without_repeating_rows(): void
    {
        $n = SwitchImports::SYNC_ROWS + 20;
        $boomAt = SwitchImports::CHUNK + 50;
        Customer::creating(function (Customer $c) {
            if (self::$boom && $c->name === 'Boom') {
                throw new RuntimeException('database went away');
            }
        });
        $alice = $this->owner();
        $store = $this->store($alice);
        $csv = "name,phone\n";
        for ($i = 1; $i <= $n; $i++) {
            $csv .= ($i === $boomAt ? 'Boom' : "Guest {$i}").',+9665'.sprintf('%08d', $i)."\n";
        }
        $import = $this->upload($alice, $store, 'customers', $csv);

        Queue::fake();
        $this->commitHttp($alice, $store, $import);
        self::$boom = true;
        $this->runJob($import->id, (int) $store->id);

        $p = $this->progress($alice, $store, $import);
        $this->assertSame(['failed', SwitchImports::CHUNK, $n], [$p['status'], $p['rows_done'], $p['rows_total']]);
        $this->assertNotEmpty($p['error']);
        $this->assertStringNotContainsString('database went away', (string) $p['error'], 'no internal error text shown');
        $this->assertSame(SwitchImports::CHUNK, Customer::query()->where('store_id', $store->id)->count(), 'the failed chunk rolled back');

        self::$boom = false;
        $this->commitHttp($alice, $store, $import)->assertSessionHas('success'); // the rest is small: finishes in the request
        $this->assertSame('committed', $import->refresh()->status);
        $this->assertSame($n, Customer::query()->where('store_id', $store->id)->count(), 'resumed without repeating a row');
        $this->assertSame($n, (int) $import->summary['counts']['create']);
    }
}

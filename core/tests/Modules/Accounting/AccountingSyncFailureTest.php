<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Accounting;

use App\Http\Middleware\Installed;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\SsrfGuard;
use Juwri\Modules\Accounting\Jobs\SyncInvoiceJob;
use Juwri\Modules\Accounting\Models\ExternalInvoice;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-329: a failed accounting sync must leave a visible, retryable row instead of
 * only a failed_jobs entry. Failed rows are shown on the owner page, ignored by
 * the success idempotency guard, turned into 'synced' by a later successful run
 * (same order+provider row), and retried per store.
 */
class AccountingSyncFailureTest extends TestCase
{
    use RefreshDatabase;

    private const ACCESS_TOKEN = 'TOK-SECRET-AT';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['settings.admin_allow_accounting_sync' => true]);
        SsrfGuard::$resolver = static fn (string $h): array => ['93.184.216.34'];
        $this->withoutMiddleware(Installed::class);

        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'd.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'f.png'])
        {
            public function __construct(private array $d) {}

            public function __get($k): mixed
            {
                return $this->d[$k] ?? null;
            }

            public function __set($k, $v): void
            {
                $this->d[$k] = $v;
            }

            public function __isset($k): bool
            {
                return array_key_exists($k, $this->d);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect()]);
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function store(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{0: User, 1: Store} */
    private function ownerRestaurant(): array
    {
        $owner = $this->owner();

        return [$owner, $this->store($owner)];
    }

    private function connectXero(Store $store): Credential
    {
        return Credential::create([
            'store_id' => $store->id, 'feature' => 'accounting', 'provider' => 'xero', 'active' => true,
            'secrets' => [
                'provider' => 'xero', 'access_token' => self::ACCESS_TOKEN, 'tenant_id' => 'TENANT-9',
                'base_url' => 'https://xero.example/api.xro/2.0',
            ],
        ]);
    }

    private function order(Store $store): Order
    {
        return Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway']);
    }

    private function failedInvoice(Store $store, string $error = 'provider error 401'): ExternalInvoice
    {
        return ExternalInvoice::create([
            'order_id' => $this->order($store)->id,
            'store_id' => $store->id,
            'provider' => 'xero',
            'external_id' => null,
            'status' => 'failed',
            'error' => $error,
        ]);
    }

    private function syncedInvoice(Store $store): ExternalInvoice
    {
        return ExternalInvoice::create([
            'order_id' => $this->order($store)->id,
            'store_id' => $store->id,
            'provider' => 'xero',
            'external_id' => 'XR-SYNCED',
            'status' => 'synced',
            'error' => null,
        ]);
    }

    public function test_failed_persists_the_error_text_without_the_access_token(): void
    {
        $store = $this->ownerRestaurant()[1];
        $this->connectXero($store);
        $order = $this->order($store);

        $job = new SyncInvoiceJob($order->id);
        $job->failed(new \RuntimeException('Xero 401 rejected access token '.self::ACCESS_TOKEN));

        $row = ExternalInvoice::where('order_id', $order->id)->where('provider', 'xero')->first();
        $this->assertNotNull($row);
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('Xero 401', (string) $row->error);
        $this->assertLessThanOrEqual(255, strlen((string) $row->error));
        $this->assertStringNotContainsString(self::ACCESS_TOKEN, (string) $row->error);
        $this->assertStringNotContainsString(self::ACCESS_TOKEN, json_encode($row->getAttributes()) ?: '');
        $this->assertSame(1, ExternalInvoice::where('order_id', $order->id)->where('provider', 'xero')->count());
    }

    public function test_failed_does_not_overwrite_an_already_synced_row(): void
    {
        $store = $this->ownerRestaurant()[1];
        $this->connectXero($store);
        $order = $this->order($store);

        $synced = ExternalInvoice::create([
            'order_id' => $order->id,
            'store_id' => $store->id,
            'provider' => 'xero',
            'external_id' => 'XR-ORIGINAL',
            'status' => 'synced',
            'error' => null,
        ]);

        (new SyncInvoiceJob($order->id))->failed(new \RuntimeException('a later retry failed'));

        $synced->refresh();
        $this->assertSame('synced', $synced->status);
        $this->assertSame('XR-ORIGINAL', $synced->external_id);
        $this->assertNull($synced->error);
        $this->assertSame(1, ExternalInvoice::where('order_id', $order->id)->where('provider', 'xero')->count());
    }

    public function test_failed_row_is_reused_and_turned_synced_by_a_later_successful_handle(): void
    {
        $store = $this->ownerRestaurant()[1];
        $this->connectXero($store);
        $order = $this->order($store);

        Http::fake(['xero.example/*' => Http::sequence()
            ->push(['Message' => 'Unauthorized'], 401)
            ->push(['Invoices' => [['InvoiceID' => 'XR-LATE']]], 200)]);
        $job = new SyncInvoiceJob($order->id);
        try {
            $job->handle();
            $this->fail('A 401 provider answer must throw so the queue can retry.');
        } catch (\Throwable $e) {
            $job->failed($e);
        }

        $row = ExternalInvoice::where('order_id', $order->id)->where('provider', 'xero')->first();
        $this->assertNotNull($row);
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('401', (string) $row->error);

        (new SyncInvoiceJob($order->id))->handle();

        $row->refresh();
        $this->assertSame('synced', $row->status);
        $this->assertNull($row->error);
        $this->assertSame('XR-LATE', $row->external_id);
        $this->assertSame(1, ExternalInvoice::where('order_id', $order->id)->where('provider', 'xero')->count());
    }

    public function test_owner_page_banner_only_counts_the_current_store_failures(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $otherStore = $this->store($owner); // same owner/account: only store_id may hide it

        $this->withoutMiddleware();

        // No failures at all: no banner.
        $this->actingAs($owner)->get(route('stores.accounting.index').'?store_id='.$store->id)
            ->assertOk()
            ->assertDontSee('did not sync')
            ->assertDontSee('Retry all failed');

        // A failure of another store (same owner) must not be counted for this store.
        $this->failedInvoice($otherStore);
        $this->actingAs($owner)->get(route('stores.accounting.index').'?store_id='.$store->id)
            ->assertOk()
            ->assertDontSee('did not sync')
            ->assertDontSee('Retry all failed');

        // A failure of the current store shows the banner and the retry-all action.
        $this->failedInvoice($store);
        $this->actingAs($owner)->get(route('stores.accounting.index').'?store_id='.$store->id)
            ->assertOk()
            ->assertSee('did not sync')
            ->assertSee('Retry all failed');
    }

    public function test_retry_failed_queues_jobs_only_for_the_current_store_failed_rows(): void
    {
        Queue::fake();

        [$owner, $store] = $this->ownerRestaurant();
        $otherStore = $this->store($owner);

        $first = $this->failedInvoice($store);
        $second = $this->failedInvoice($store);
        $other = $this->failedInvoice($otherStore);
        $alreadySynced = $this->syncedInvoice($store);

        $this->withoutMiddleware();
        $this->actingAs($owner)->post(route('stores.accounting.retry-failed').'?store_id='.$store->id)
            ->assertRedirect();

        Queue::assertPushed(SyncInvoiceJob::class, 2);
        foreach ([$first, $second] as $failed) {
            Queue::assertPushed(SyncInvoiceJob::class, fn (SyncInvoiceJob $job) => $job->orderId === (int) $failed->order_id);
        }
        Queue::assertNotPushed(SyncInvoiceJob::class, fn (SyncInvoiceJob $job) => $job->orderId === (int) $other->order_id);
        Queue::assertNotPushed(SyncInvoiceJob::class, fn (SyncInvoiceJob $job) => $job->orderId === (int) $alreadySynced->order_id);
    }
}

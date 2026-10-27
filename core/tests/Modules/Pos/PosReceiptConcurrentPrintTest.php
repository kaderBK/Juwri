<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StaffMember;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-272: PosController::receipt() bumps orders.receipt_print_count under
 * DB::transaction + lockForUpdate and marks every print after the first as a
 * COPY. Two simultaneous FIRST prints of one order must therefore yield exactly
 * one original (no COPY) and one COPY, with the count landing on 2. The first
 * test proves that with real forked processes on their own connections; the
 * second pins the sequential contract (three prints, kitchen tickets uncounted).
 */
class PosReceiptConcurrentPrintTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<int> */
    private array $stores = [];

    /** @var list<int> */
    private array $plans = [];

    /** @var list<int> */
    private array $orders = [];

    /** @var list<string> */
    private array $tempFiles = [];

    /** BL-017: `languages` rows as they were before setUp (null = absent); restored in tearDown because this test commits. */
    private array $languagesBefore = [];

    /**
     * Fixture rows must be COMMITTED when this runs on a real server DB: the
     * forked children open their own connections and can never see rows still
     * held inside this test's transaction. On sqlite :memory: the in-memory DB
     * cannot be shared across connections anyway, so the transaction stays and
     * the concurrent test skips itself.
     *
     * @return array<int, string|null>
     */
    protected function connectionsToTransact(): array
    {
        return $this->usingInMemoryDatabase() ? [config('database.default')] : [];
    }

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        foreach (['en' => ['English', 'ltr'], 'ar' => ['Arabic', 'rtl']] as $code => [$name, $direction]) {
            $this->languagesBefore[$code] = Language::where('code', $code)->first()?->only(['name', 'direction', 'active']);
            Language::updateOrCreate(['code' => $code], ['name' => $name, 'direction' => $direction, 'active' => 1]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];

        $stores = $this->stores;
        $orders = $this->orders;
        $plans = $this->plans;
        $this->stores = $this->orders = $this->plans = [];

        if ($stores !== [] && in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
            foreach (['order_item_extras', 'order_items', 'order_adjustments'] as $table) {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    DB::table($table)->whereIn('store_id', $stores)->delete();
                }
            }
            if (DB::getSchemaBuilder()->hasTable('orders')) {
                DB::table('orders')->whereIn('store_id', $stores)->delete();
            }
            foreach (['daily_tokens', 'menu_variants', 'store_options', 'items', 'categories', 'branches', 'staff_members'] as $table) {
                if (DB::getSchemaBuilder()->hasTable($table) && DB::getSchemaBuilder()->hasColumn($table, 'store_id')) {
                    DB::table($table)->whereIn('store_id', $stores)->delete();
                }
            }
            $owners = DB::table('stores')->whereIn('id', $stores)->pluck('user_id')->all();
            DB::table('stores')->whereIn('id', $stores)->delete();
            DB::table('users')->whereIn('id', $owners)->update(['account_id' => null]);
            DB::table('accounts')->whereIn('owner_user_id', $owners)->delete();
            DB::table('users')->whereIn('id', $owners)->delete();
            if ($plans !== []) {
                TestPlans::delete(...$plans);
            }
        }

        // BL-017: on mysql/pgsql the language rows were written outside a transaction. The migration seeds `ar` as
        // inactive, and leaving it active made the next test's language switcher (ViewModelGoldenTest) list a second language.
        if ($this->languagesBefore !== [] && in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
            foreach ($this->languagesBefore as $code => $before) {
                $before === null
                    ? Language::where('code', $code)->delete()
                    : Language::where('code', $code)->update($before);
            }
        }
        $this->languagesBefore = [];

        parent::tearDown();
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $this->plans[] = $plan;

        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->stores[] = (int) $store->id;

        return $store;
    }

    private function order(Store $r, float $price = 100): Order
    {
        $staff = StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'S '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1,
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => $price, 'active' => 1]);

        $order = PosService::createOrder($r, $staff, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        $this->orders[] = (int) $order->id;

        return $order;
    }

    private function receiptUrl(Store $r, Order $order, array $query = []): string
    {
        return route('pos.orders.receipt', array_merge(['id' => $order->id, 'store' => $r->id], $query));
    }

    public function test_two_simultaneous_first_prints_yield_one_original_and_one_copy(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Needs pcntl_fork() to run two genuine simultaneous prints in one test process.');
        }
        if ($this->usingInMemoryDatabase()) {
            $this->markTestSkipped('An in-memory sqlite DB (database=:memory:) cannot be shared with a forked connection, so the concurrent prints cannot contend.');
        }

        $r = $this->storeFixture();
        $order = $this->order($r, 100);

        $verdicts = $this->raceFirstPrints($r, $order);

        $this->assertSame(['copy', 'original'], $verdicts, 'Two simultaneous first prints must give exactly one original and one copy.');
        $this->assertSame(2, (int) DB::table('orders')->where('id', $order->id)->value('receipt_print_count'), 'Both prints are counted.');
    }

    public function test_three_sequential_prints_count_three_and_kitchen_does_not_count(): void
    {
        $r = $this->storeFixture();
        $order = $this->order($r, 100);
        $url = $this->receiptUrl($r, $order, ['lang' => 'en']);
        $count = fn (): int => (int) DB::table('orders')->where('id', $order->id)->value('receipt_print_count');

        $first = $this->actingAs($r->user, 'web')->get($url)->assertOk()->getContent();
        $this->assertStringNotContainsString('COPY', $first, 'The first print is the original.');
        $this->assertSame(1, $count());
        $this->assertNotNull(
            DB::table('orders')->where('id', $order->id)->value('receipt_printed_at'),
            'The first print must stamp receipt_printed_at.'
        );

        $second = $this->actingAs($r->user, 'web')->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('COPY', $second, 'The second print is a copy.');

        $third = $this->actingAs($r->user, 'web')->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('COPY', $third, 'The third print is a copy.');
        $this->assertSame(3, $count(), 'Three sequential prints land on count 3.');

        $this->actingAs($r->user, 'web')
            ->get($this->receiptUrl($r, $order, ['lang' => 'en', 'format' => 'kitchen']))
            ->assertOk();

        $this->assertSame(3, $count(), 'A kitchen ticket must not bump receipt_print_count.');
    }

    /**
     * Fire two genuinely simultaneous first prints of one order, each in a
     * forked child on its own fresh DB connection. Each child records whether
     * the COPY marker was shown in a temp file the parent reads back.
     *
     * @return list<string> one 'original'|'copy'|'error: …' verdict per child, sorted
     */
    private function raceFirstPrints(Store $r, Order $order): array
    {
        $url = $this->receiptUrl($r, $order, ['lang' => 'en']);

        $this->actingAs($r->user, 'web');

        $slots = [];
        for ($i = 0; $i < 2; $i++) {
            $file = tempnam(sys_get_temp_dir(), 'pos_rcpt_');
            $this->assertIsString($file);
            $this->tempFiles[] = $file;
            $slots[] = $file;
        }

        // Drop the parent's handle first so no child can inherit a live socket;
        // every child (and the parent) then reconnects on its own.
        DB::purge();

        $go = microtime(true) + 2.0;
        $pids = [];
        for ($i = 0; $i < 2; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                DB::reconnect();
                $this->fail('pcntl_fork() failed.');
            }
            if ($pid === 0) {
                $this->forkedPrint($url, $slots[$i], $go);
            }
            $pids[] = $pid;
        }

        DB::reconnect();
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $verdicts = [];
        foreach ($slots as $file) {
            $verdict = trim((string) @file_get_contents($file));
            $verdicts[] = $verdict === '' ? 'error: child wrote no verdict' : $verdict;
        }
        sort($verdicts);

        return $verdicts;
    }

    /**
     * The body of a forked print: a fresh connection, a shared start time, the
     * real receipt request, and the verdict written where the parent can read it.
     */
    private function forkedPrint(string $url, string $file, float $go): never
    {
        DB::purge();
        DB::reconnect();

        while (microtime(true) < $go) {
            usleep(500);
        }

        try {
            $response = $this->get($url);
            $body = (string) $response->getContent();
            $verdict = $response->getStatusCode() === 200
                ? (str_contains($body, 'COPY') ? 'copy' : 'original')
                : 'error: HTTP '.$response->getStatusCode();
        } catch (\Throwable $e) {
            $verdict = 'error: '.$e->getMessage();
        }

        @file_put_contents($file, $verdict);

        exit(0);
    }
}

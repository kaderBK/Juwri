<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Tenancy\StoreTable;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Tenancy\StoreDataEraser;
use Juwri\Core\Services\Tenancy\StoreDataExporter;
use Juwri\Core\Services\Tenancy\StoreDataImporter;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\StoreDataScope;
use Juwri\Core\Services\Tenancy\TenantFill;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Services\GatewaySecretVault;
use RuntimeException;
use Tests\Support\TestPlans;
use Tests\TestCase;
use ZipArchive;

/**
 * P1-21 part 2 (J29-5): export, delete (GDPR/PDPL erasure) and restore of ONE account through the store-data registry.
 * Tenancy-critical: with two accounts, every registered table is counted before/after — the other account's rows
 * never change, a bundle never carries a secret, and a tampered bundle cannot write another account's rows.
 */
class StoreDataLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $mediaFiles = [];

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->tmp = storage_path('framework/testing/store-data-'.Str::lower(Str::random(8)));
    }

    protected function tearDown(): void
    {
        foreach ($this->mediaFiles as $file) {
            @unlink($file);
        }
        foreach (glob($this->tmp.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function owner(string $name): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1,
        ]);

        return User::factory()->create(['name' => $name, 'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(), 'password' => Hash::make('pw-'.$name)]);
    }

    /** @param array<string, mixed> $row */
    private function row(string $table, array $row): int
    {
        return (int) DB::table($table)->insertGetId(TenantFill::row(DB::connection(), $table, $row));
    }

    private function media(string $name): string
    {
        $dir = public_path('storage/menu');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir.'/'.$name, 'img-'.$name);
        $this->mediaFiles[] = $dir.'/'.$name;

        return $name;
    }

    /** A store with business data, secrets, files and media. */
    private function store(User $owner, string $slug, string $sharedImage): Store
    {
        $store = Store::create([
            'user_id' => $owner->id, 'title' => 'Store '.$slug, 'slug' => $slug, 'color' => '#fff', 'sub_title' => 's',
            'description' => 'd', 'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1',
        ]);
        $a = (int) $store->account_id;
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        MenuCategory::create(['store_id' => $store->id, 'name' => 'Sub', 'parent' => $cat->id]);
        $own = $this->media($slug.'-'.Str::lower(Str::random(6)).'.jpg');
        $kabsa = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Kabsa', 'price' => 30, 'active' => 1, 'image' => $own]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Shared', 'price' => 5, 'active' => 1, 'image' => $sharedImage]);
        $order = $this->row('orders', ['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_name' => 'C', 'phone_number' => '1', 'created_at' => now()]);
        $this->row('order_items', ['order_id' => $order, 'item_id' => $kabsa->id, 'quantity' => 2, 'variation' => 0, 'status' => 'pending']);
        $this->row('customers', ['store_id' => $store->id, 'name' => 'Guest '.$slug, 'password' => Hash::make('customer-secret-'.$slug), 'created_at' => now()]);
        $this->row('staff_members', ['store_id' => $store->id, 'owner_id' => $owner->id, 'name' => 'W', 'email' => $slug.'@staff.test', 'password' => Hash::make('staff-secret-'.$slug), 'pin' => '4321', 'role' => 'waiter', 'active' => 1]);
        Credential::create(['store_id' => $store->id, 'feature' => 'accounting', 'provider' => 'xero', 'secrets' => ['refresh_token' => 'rt-secret-'.$slug], 'active' => true]);
        GatewaySecretVault::put($store->id, 'restaurant_stripe_secret_key', 'sk_test_'.$slug);
        StoreOption::updateStoreOption($store->id, 'restaurant_stripe_title', 'Card');
        StoreOption::updateStoreOption($store->id, 'meta_pixel_id', 'pixel-'.$slug);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'SAR');
        $receipt = "accounts/{$a}/expenses/{$store->id}/{$slug}.pdf";
        Storage::disk('local')->put($receipt, 'receipt-'.$slug);
        $this->row('expenses', ['store_id' => $store->id, 'amount_minor' => 100, 'tax_minor' => 0, 'date' => now()->toDateString(), 'payment_source' => 'cash', 'receipt_path' => $receipt]);
        $supplier = $this->row('suppliers', ['store_id' => $store->id, 'name' => 'Supplier', 'lead_time_days' => 1, 'active' => 1]);
        // D-P1-21-15: a supplier payment carries its supplier's store_id/account_id (filled from the parent).
        $this->row('supplier_payments', ['supplier_id' => $supplier, 'amount_minor' => 500, 'user_id' => $owner->id, 'paid_at' => now()]);

        return $store;
    }

    /** @return array{0: User, 1: list<Store>, 2: User, 3: list<Store>, 4: string} */
    private function twoAccounts(): array
    {
        $shared = $this->media('shared-'.Str::lower(Str::random(6)).'.jpg');
        $alice = $this->owner('alice');
        $aStores = [$this->store($alice, 'al-one-'.Str::lower(Str::random(4)), $shared), $this->store($alice, 'al-two-'.Str::lower(Str::random(4)), $shared)];
        $bob = $this->owner('bob');
        $bStores = [$this->store($bob, 'bob-one-'.Str::lower(Str::random(4)), $shared)];
        foreach ([$alice, $bob] as $u) {
            DB::table('api_tokens')->insert(['user_id' => $u->id, 'name' => 'cli', 'token' => hash('sha256', Str::random(20)), 'created_at' => now()]);
            // D-P1-21-15: wallet rows and owner coupons carry the owner's account_id (filled from the user).
            $this->row('wallet_transactions', ['user_id' => $u->id, 'amount_minor' => 100, 'currency_code' => 'SAR', 'balance_after_minor' => 100, 'type' => 'credit', 'created_at' => now()]);
            $this->row('coupons', ['user_id' => $u->id, 'code' => 'OWN'.strtoupper(Str::random(6)), 'percentage_rate' => 10, 'coupon_type' => 'percentage', 'limit' => 5, 'created_at' => now()]);
        }
        // A platform coupon (no owner, NULL account) is nobody's account data: never exported, deleted or restored.
        $this->row('coupons', ['code' => 'PLATFORM'.strtoupper(Str::random(4)), 'issuer_scope' => 'platform', 'percentage_rate' => 5, 'coupon_type' => 'percentage', 'limit' => 5, 'created_at' => now()]);

        return [$alice->fresh(), $aStores, $bob->fresh(), $bStores, $shared];
    }

    /** @return array<string, int> rows per registered table in the scope */
    private function counts(StoreDataScope $scope): array
    {
        $out = [];
        foreach (StoreDataRegistry::tables() as $name => $table) {
            $q = $scope->query(DB::connection(), $table);
            $out[$name] = $q === null ? 0 : $q->count();
        }

        return $out;
    }

    /** @return array<string, int> every registered table's total rows */
    private function totals(): array
    {
        $out = [];
        foreach (array_keys(StoreDataRegistry::tables()) as $name) {
            $out[$name] = DB::table($name)->count();
        }
        $out['accounts'] = DB::table('accounts')->count();

        return $out;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, list<array<string, mixed>>>, 2: string} manifest, rows, all bytes */
    private function read(string $zipPath): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $rows = [];
        $all = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $content = (string) $zip->getFromIndex($i);
            $all .= $content;
            if (str_starts_with($name, 'data/')) {
                $rows[substr($name, 5, -7)] = array_map(fn ($l) => json_decode($l, true), array_values(array_filter(explode("\n", $content))));
            }
        }
        $zip->close();

        return [$manifest, $rows, $all];
    }

    public function test_account_export_holds_every_row_of_the_account_and_no_secret(): void
    {
        [$alice, $aStores, $bob, $bStores] = $this->twoAccounts();
        $scope = StoreDataScope::account((int) $alice->account_id);
        $expected = array_filter($this->counts($scope));

        $manifest = app(StoreDataExporter::class)->export(DB::connection(), $scope, $this->tmp.'/alice.zip');
        [$m, $rows, $bytes] = $this->read($this->tmp.'/alice.zip');

        $this->assertSame($manifest['tables'], $m['tables']);
        foreach ($expected as $table => $count) {
            if (! StoreDataRegistry::get($table)->exported) {
                $this->assertArrayHasKey($table, $m['excluded']['tables'], "{$table} listed as excluded");
                $this->assertArrayNotHasKey($table, $rows);

                continue;
            }
            $this->assertSame($count, count($rows[$table] ?? []), "{$table}: every row of the account");
        }
        $this->assertSame(2, count($rows['stores']));
        $this->assertSame([(int) $alice->id], array_map(fn ($u) => (int) $u['id'], $rows['users']));
        // Nothing of Bob's.
        $bobStores = array_map(fn ($s) => (int) $s->id, $bStores);
        foreach ($rows as $table => $list) {
            foreach ($list as $row) {
                $this->assertNotContains((int) ($row['store_id'] ?? 0), $bobStores, "{$table} carries a row of Bob's store");
                $this->assertNotSame((int) $bob->account_id, (int) ($row['account_id'] ?? 0), "{$table} carries a row of Bob's account");
                $this->assertNotSame((int) $bob->id, (int) ($row['user_id'] ?? 0), "{$table} carries Bob's user row");
            }
        }
        // No secret anywhere in the bundle; each exclusion listed.
        foreach ($aStores as $s) {
            $this->assertStringNotContainsString('sk_test_'.$s->slug, $bytes);
            $this->assertStringNotContainsString('rt-secret-'.$s->slug, $bytes);
            $this->assertStringNotContainsString('pixel-'.$s->slug, $bytes);
        }
        $this->assertStringNotContainsString((string) $alice->password, $bytes);
        $this->assertArrayNotHasKey('password', $rows['staff_members'][0]);
        $this->assertArrayNotHasKey('password', $rows['customers'][0]);
        $this->assertArrayNotHasKey('pin', $rows['staff_members'][0]);
        $this->assertContains('password', $m['excluded']['columns']['users']);
        $this->assertContains('credentials', $m['excluded']['columns']['store_payment_gateways']);
        $this->assertArrayHasKey('credentials', $m['excluded']['tables']);
        $this->assertArrayHasKey('api_tokens', $m['excluded']['tables']);
        $this->assertContains('meta_pixel_id', $m['excluded']['settings']);
        $this->assertStringContainsString('Card', $bytes, 'the gateway title (not a secret) is kept');
        // Owner-level and child rows; files and media.
        $this->assertCount(1, $rows['wallet_transactions']);
        $this->assertCount(2, $rows['supplier_payments']);
        $this->assertCount(1, $rows['coupons']);
        $this->assertSame((int) $alice->id, (int) $rows['coupons'][0]['user_id']);
        foreach (['wallet_transactions', 'supplier_payments', 'coupons'] as $table) {
            foreach ($rows[$table] as $row) {
                $this->assertSame((int) $alice->account_id, (int) $row['account_id'], "{$table}: keyed by the account (D-P1-21-15)");
            }
        }
        $this->assertCount(2, array_filter(array_keys($m['files']), fn ($e) => str_contains($e, '/expenses/')));
        $this->assertNotEmpty(array_filter(array_keys($m['media']), fn ($e) => str_starts_with($e, 'media/public/storage/menu/al-one-')));
    }

    public function test_store_export_holds_only_that_store(): void
    {
        [$alice, $aStores] = $this->twoAccounts();
        $scope = StoreDataScope::store((int) $aStores[0]->id);

        app(StoreDataExporter::class)->export(DB::connection(), $scope, $this->tmp.'/store.zip');
        [$m, $rows] = $this->read($this->tmp.'/store.zip');

        $this->assertSame([(int) $aStores[0]->id], array_map(fn ($s) => (int) $s['id'], $rows['stores']));
        $this->assertArrayNotHasKey('users', $rows);
        $this->assertArrayNotHasKey('wallet_transactions', $rows, 'owner-level rows are not store data');
        foreach ($rows as $table => $list) {
            foreach ($list as $row) {
                if (array_key_exists('store_id', $row)) {
                    $this->assertSame((int) $aStores[0]->id, (int) $row['store_id'], $table);
                }
            }
        }
    }

    public function test_account_delete_removes_every_row_and_file_of_the_account_and_nothing_else(): void
    {
        [$alice, $aStores, $bob, $bStores, $shared] = $this->twoAccounts();
        $aScope = StoreDataScope::account((int) $alice->account_id);
        $bScope = StoreDataScope::account((int) $bob->account_id);
        $aliceRows = $this->counts($aScope);
        $bobBefore = $this->counts($bScope);
        $totalsBefore = $this->totals();
        $receipts = DB::table('expenses')->whereIn('store_id', [$aStores[0]->id, $aStores[1]->id])->pluck('receipt_path')->all();
        $ownImage = public_path('storage/menu/'.DB::table('items')->where('store_id', $aStores[0]->id)->where('name', 'Kabsa')->value('image'));

        $report = app(StoreDataEraser::class)->delete(DB::connection(), StoreDataScope::account((int) $alice->account_id));

        // Alice: nothing left in any registered table, the account row gone.
        $this->assertSame([], array_filter($this->counts(StoreDataScope::account((int) $alice->account_id))));
        $this->assertFalse(DB::table('accounts')->where('id', $alice->account_id)->exists());
        $this->assertFalse(DB::table('users')->where('id', $alice->id)->exists());
        // Bob: every registered table unchanged; totals dropped by exactly Alice's rows.
        $this->assertSame($bobBefore, $this->counts(StoreDataScope::account((int) $bob->account_id)));
        $totalsAfter = $this->totals();
        foreach ($totalsBefore as $table => $before) {
            $expected = $before - ($table === 'accounts' ? 1 : $aliceRows[$table]);
            $this->assertSame($expected, $totalsAfter[$table], "{$table}: only Alice's rows deleted");
        }
        // Files: Alice's receipts and own media gone, the media file Bob still uses kept.
        foreach ($receipts as $path) {
            Storage::disk('local')->assertMissing($path);
        }
        $this->assertFileDoesNotExist($ownImage);
        $this->assertFileExists(public_path('storage/menu/'.$shared));
        Storage::disk('local')->assertExists(DB::table('expenses')->where('store_id', $bStores[0]->id)->value('receipt_path'));
        // Audit kept, no personal data.
        $op = DB::table('store_data_operations')->where('operation', 'delete')->where('target_account_id', $alice->account_id)->first();
        $this->assertNotNull($op);
        $this->assertStringNotContainsString($alice->email, (string) $op->report);
        $this->assertStringNotContainsString('alice', (string) $op->report);
        $this->assertSame($report['tables']['orders'], 2);
    }

    public function test_delete_refuses_when_a_cascade_would_reach_another_account(): void
    {
        [$alice, $aStores, $bob, $bStores] = $this->twoAccounts();
        // A corrupt cross-account reference: Bob's order line points at Alice's item (FK order_items.item_id cascades).
        $bobOrder = (int) DB::table('orders')->where('store_id', $bStores[0]->id)->value('id');
        $aliceItem = (int) DB::table('items')->where('store_id', $aStores[0]->id)->value('id');
        $line = $this->row('order_items', ['order_id' => $bobOrder, 'item_id' => $aliceItem, 'quantity' => 1, 'variation' => 0, 'status' => 'pending']);
        // Since keep_order_lines_when_items_deleted the FK is ON DELETE SET NULL (order history survives); a cascading FK
        // must still make the delete refuse.
        $hasCascade = collect(DB::getSchemaBuilder()->getForeignKeys('order_items'))
            ->contains(fn ($fk) => $fk['foreign_table'] === 'items' && strtolower((string) ($fk['on_delete'] ?? '')) === 'cascade');
        $before = $this->totals();

        try {
            app(StoreDataEraser::class)->delete(DB::connection(), StoreDataScope::account((int) $alice->account_id));
            $this->assertFalse($hasCascade, 'with a cascading FK the delete must be refused');
            $this->assertTrue(DB::table('order_items')->where('id', $line)->exists(), "Bob's row survives");
            $this->assertNull(DB::table('order_items')->where('id', $line)->value('item_id'), "Bob's line loses only the link to Alice's item");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('outside it', $e->getMessage());
            $this->assertSame($before, $this->totals(), 'nothing deleted');
        }
    }

    public function test_store_delete_keeps_the_rest_of_the_account(): void
    {
        [$alice, $aStores, $bob] = $this->twoAccounts();
        $bobBefore = $this->counts(StoreDataScope::account((int) $bob->account_id));
        $sisterBefore = $this->counts(StoreDataScope::store((int) $aStores[1]->id));

        app(StoreDataEraser::class)->delete(DB::connection(), StoreDataScope::store((int) $aStores[0]->id));

        $this->assertFalse(DB::table('stores')->where('id', $aStores[0]->id)->exists());
        $this->assertSame([], array_filter($this->counts(StoreDataScope::store((int) $aStores[0]->id))));
        $this->assertSame($sisterBefore, $this->counts(StoreDataScope::store((int) $aStores[1]->id)));
        $this->assertSame($bobBefore, $this->counts(StoreDataScope::account((int) $bob->account_id)));
        $this->assertTrue(DB::table('users')->where('id', $alice->id)->exists());
        $this->assertSame(1, DB::table('wallet_transactions')->where('user_id', $alice->id)->count());
    }

    public function test_delete_command_needs_the_typed_confirmation(): void
    {
        [$alice] = $this->twoAccounts();
        $label = 'account:'.$alice->account_id;

        $this->artisan('store-data:delete', ['--account' => $alice->account_id, '--confirm' => 'account:999999', '--no-interaction' => true])->assertFailed();
        $this->assertTrue(DB::table('accounts')->where('id', $alice->account_id)->exists());

        $this->artisan('store-data:delete', ['--account' => $alice->account_id, '--confirm' => $label])->assertSuccessful();
        $this->assertFalse(DB::table('accounts')->where('id', $alice->account_id)->exists());
    }

    public function test_round_trip_export_delete_import_gives_the_same_rows(): void
    {
        [$alice, $aStores, $bob] = $this->twoAccounts();
        $scope = fn () => StoreDataScope::account((int) $alice->account_id);
        $bobBefore = $this->counts(StoreDataScope::account((int) $bob->account_id));
        app(StoreDataExporter::class)->export(DB::connection(), $scope(), $this->tmp.'/before.zip');
        $receipt = DB::table('expenses')->where('store_id', $aStores[0]->id)->value('receipt_path');
        $staffHash = DB::table('staff_members')->where('store_id', $aStores[0]->id)->value('password');

        app(StoreDataEraser::class)->delete(DB::connection(), $scope());
        Storage::disk('local')->assertMissing($receipt);
        $this->artisan('store-data:import', ['path' => $this->tmp.'/before.zip', '--confirm' => 'account:'.$alice->account_id])->assertSuccessful();

        app(StoreDataExporter::class)->export(DB::connection(), $scope(), $this->tmp.'/after.zip');
        [$m1, $before] = $this->read($this->tmp.'/before.zip');
        [$m2, $after] = $this->read($this->tmp.'/after.zip');
        foreach (['coupons' => 1, 'supplier_payments' => 2, 'wallet_transactions' => 1] as $table => $n) {
            $this->assertCount($n, $before[$table] ?? [], "{$table} is in the bundle (D-P1-21-15)");
        }
        $this->assertSame(1, DB::table('coupons')->where('issuer_scope', 'platform')->count(), 'the platform coupon was not touched');
        $this->assertSame(array_keys($before), array_keys($after));
        foreach ($before as $table => $rows) {
            $this->assertEquals($rows, $after[$table], "{$table}: same rows (ids kept) after the round trip");
        }
        $this->assertSame(array_keys($m1['excluded']['tables']), array_keys($m2['excluded']['tables']));
        $this->assertSame($m1['excluded']['columns'], $m2['excluded']['columns']);
        // Secrets were reset, not restored; the owner signs in again.
        $this->assertNotSame($staffHash, DB::table('staff_members')->where('store_id', $aStores[0]->id)->value('password'));
        $this->assertNull(DB::table('users')->where('id', $alice->id)->value('password'));
        $this->assertSame(0, Credential::where('store_id', $aStores[0]->id)->count());
        Storage::disk('local')->assertExists($receipt);
        $this->assertSame($bobBefore, $this->counts(StoreDataScope::account((int) $bob->account_id)));
        // New rows get ids after the restored ones (PostgreSQL sequences moved).
        $next = $this->row('orders', ['store_id' => $aStores[0]->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_name' => 'N', 'phone_number' => '1', 'created_at' => now()]);
        $this->assertGreaterThan((int) DB::table('orders')->where('store_id', $aStores[1]->id)->max('id'), $next);
    }

    public function test_restore_refuses_a_bundle_carrying_another_accounts_row(): void
    {
        [$alice, $aStores, $bob, $bStores] = $this->twoAccounts();
        app(StoreDataExporter::class)->export(DB::connection(), StoreDataScope::account((int) $alice->account_id), $this->tmp.'/a.zip');
        app(StoreDataEraser::class)->delete(DB::connection(), StoreDataScope::account((int) $alice->account_id));
        $bobBefore = $this->counts(StoreDataScope::account((int) $bob->account_id));

        // Tamper: one order row now claims Bob's store.
        $zip = new ZipArchive;
        $zip->open($this->tmp.'/a.zip');
        $lines = explode("\n", trim((string) $zip->getFromName('data/orders.ndjson')));
        $row = json_decode($lines[0], true);
        $row['store_id'] = $bStores[0]->id;
        $row['account_id'] = $bob->account_id;
        $row['id'] = 9_000_001;
        $lines[] = json_encode($row);
        $zip->addFromString('data/orders.ndjson', implode("\n", $lines)."\n");
        $zip->close();

        try {
            app(StoreDataImporter::class)->import(DB::connection(), $this->tmp.'/a.zip');
            $this->fail('a tampered bundle must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not belong', $e->getMessage());
        }
        $this->assertFalse(DB::table('accounts')->where('id', $alice->account_id)->exists(), 'nothing restored');
        $this->assertFalse(DB::table('orders')->where('id', 9_000_001)->exists());
        $this->assertSame($bobBefore, $this->counts(StoreDataScope::account((int) $bob->account_id)));
    }

    /** Bug fix D-P1-21-15: a bundle row with no tenant key (a NULL-account coupon of another user) is not the account's. */
    public function test_restore_refuses_a_bundle_row_without_any_key_of_the_account(): void
    {
        [$alice, , $bob] = $this->twoAccounts();
        app(StoreDataExporter::class)->export(DB::connection(), StoreDataScope::account((int) $alice->account_id), $this->tmp.'/a.zip');
        app(StoreDataEraser::class)->delete(DB::connection(), StoreDataScope::account((int) $alice->account_id));

        $zip = new ZipArchive;
        $zip->open($this->tmp.'/a.zip');
        $lines = explode("\n", trim((string) $zip->getFromName('data/coupons.ndjson')));
        $row = json_decode($lines[0], true);
        $row = ['id' => 9_000_002, 'code' => 'BOBSNEAK', 'user_id' => $bob->id, 'account_id' => null] + $row;
        $lines[] = json_encode($row);
        $zip->addFromString('data/coupons.ndjson', implode("\n", $lines)."\n");
        $zip->close();

        try {
            app(StoreDataImporter::class)->import(DB::connection(), $this->tmp.'/a.zip');
            $this->fail('a row with no key of the account must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not belong', $e->getMessage());
        }
        $this->assertFalse(DB::table('coupons')->where('code', 'BOBSNEAK')->exists());
        $this->assertFalse(DB::table('accounts')->where('id', $alice->account_id)->exists(), 'nothing restored');
    }

    public function test_restore_refuses_when_the_account_exists(): void
    {
        [$alice] = $this->twoAccounts();
        app(StoreDataExporter::class)->export(DB::connection(), StoreDataScope::account((int) $alice->account_id), $this->tmp.'/a.zip');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');
        app(StoreDataImporter::class)->import(DB::connection(), $this->tmp.'/a.zip');
    }

    /** P6-92: export one store, delete it, restore it into its live account — the same rows, files and media. */
    public function test_store_bundle_restores_into_its_live_account(): void
    {
        [$alice, $aStores, $bob] = $this->twoAccounts();
        $store = (int) $aStores[0]->id;
        $bobBefore = $this->counts(StoreDataScope::account((int) $bob->account_id));
        $sisterBefore = $this->counts(StoreDataScope::store((int) $aStores[1]->id));
        app(StoreDataExporter::class)->export(DB::connection(), StoreDataScope::store($store), $this->tmp.'/s.zip');
        $receipt = (string) DB::table('expenses')->where('store_id', $store)->value('receipt_path');
        $image = (string) DB::table((new Menu)->getTable())->where('store_id', $store)->where('name', 'Kabsa')->value('image');

        app(StoreDataEraser::class)->delete(DB::connection(), StoreDataScope::store($store));
        Storage::disk('local')->assertMissing($receipt);
        @unlink(public_path('storage/menu/'.$image));

        $this->artisan('stores:restore-bundle', ['path' => $this->tmp.'/s.zip', '--account' => $alice->account_id, '--dry-run' => true])
            ->expectsOutputToContain('Dry run OK')->assertSuccessful();
        $this->assertFalse(DB::table('stores')->where('id', $store)->exists(), 'a dry run writes nothing');
        Storage::disk('local')->assertMissing($receipt);

        $this->artisan('stores:restore-bundle', ['path' => $this->tmp.'/s.zip', '--account' => $alice->account_id, '--confirm' => 'store:999'])->assertFailed();
        $this->artisan('stores:restore-bundle', ['path' => $this->tmp.'/s.zip', '--account' => $alice->account_id, '--confirm' => 'store:'.$store])->assertSuccessful();

        app(StoreDataExporter::class)->export(DB::connection(), StoreDataScope::store($store), $this->tmp.'/s2.zip');
        [, $before] = $this->read($this->tmp.'/s.zip');
        [, $after] = $this->read($this->tmp.'/s2.zip');
        $this->assertSame(array_keys($before), array_keys($after));
        foreach ($before as $table => $rows) {
            $this->assertEquals($rows, $after[$table], "{$table}: same rows (ids kept) after the store round trip");
        }
        $this->assertSame((int) $alice->account_id, (int) DB::table('stores')->where('id', $store)->value('account_id'));
        Storage::disk('local')->assertExists($receipt);
        $this->assertFileExists(public_path('storage/menu/'.$image), 'media restored');
        $this->assertSame(0, Credential::where('store_id', $store)->count(), 'secrets stay out; the owner reconnects');
        $this->assertSame($sisterBefore, $this->counts(StoreDataScope::store((int) $aStores[1]->id)));
        $this->assertSame($bobBefore, $this->counts(StoreDataScope::account((int) $bob->account_id)));
        $this->assertTrue(DB::table('store_data_operations')->where(['operation' => 'restore', 'scope_type' => 'store', 'target_store_id' => $store])->exists());
    }

    public function test_store_bundle_restore_refuses_while_the_store_exists(): void
    {
        [$alice, $aStores] = $this->twoAccounts();
        app(StoreDataExporter::class)->export(DB::connection(), StoreDataScope::store((int) $aStores[0]->id), $this->tmp.'/s.zip');
        $totals = $this->totals();

        $this->expectExceptionMessage('already exists');
        try {
            app(StoreDataImporter::class)->import(DB::connection(), $this->tmp.'/s.zip', null, 'console', (int) $alice->account_id);
        } finally {
            $this->assertSame($totals, $this->totals());
        }
    }

    public function test_store_bundle_restore_refuses_another_account(): void
    {
        [$alice, $aStores, $bob] = $this->twoAccounts();
        $store = (int) $aStores[0]->id;
        app(StoreDataExporter::class)->export(DB::connection(), StoreDataScope::store($store), $this->tmp.'/s.zip');
        app(StoreDataEraser::class)->delete(DB::connection(), StoreDataScope::store($store));
        $totals = $this->totals();

        $this->artisan('stores:restore-bundle', ['path' => $this->tmp.'/s.zip', '--account' => $bob->account_id, '--confirm' => 'store:'.$store])
            ->expectsOutputToContain('another account')->assertFailed();
        $this->assertSame($totals, $this->totals());

        // The account changed hands (another owner): refused too.
        $carol = $this->owner('carol');
        DB::table('accounts')->where('id', $alice->account_id)->update(['owner_user_id' => $carol->id]);
        $this->expectExceptionMessage('not the owner of account');
        app(StoreDataImporter::class)->import(DB::connection(), $this->tmp.'/s.zip', null, 'console', (int) $alice->account_id);
    }

    public function test_store_bundle_restore_refuses_a_tampered_or_unsigned_bundle(): void
    {
        [$alice, $aStores] = $this->twoAccounts();
        $store = (int) $aStores[0]->id;
        app(StoreDataExporter::class)->export(DB::connection(), StoreDataScope::store($store), $this->tmp.'/s.zip');
        app(StoreDataEraser::class)->delete(DB::connection(), StoreDataScope::store($store));
        $totals = $this->totals();
        $import = fn (string $zip) => app(StoreDataImporter::class)->import(DB::connection(), $zip, null, 'console', (int) $alice->account_id);

        $cases = [
            'edited row' => fn (ZipArchive $z) => $z->addFromString('data/orders.ndjson', str_replace('"C"', '"Mallory"', (string) $z->getFromName('data/orders.ndjson'))),
            'added entry' => fn (ZipArchive $z) => $z->addFromString('data/coupons.ndjson', json_encode(['id' => 9_000_003, 'code' => 'SNEAK', 'store_id' => $store])."\n"),
            'removed entry' => fn (ZipArchive $z) => $z->deleteName('data/order_items.ndjson'),
            'unsigned' => fn (ZipArchive $z) => $z->deleteName(StoreDataExporter::SIGNATURE_ENTRY),
            'manifest edited' => fn (ZipArchive $z) => $z->addFromString('manifest.json', str_replace('"store"', '"store" ', (string) $z->getFromName('manifest.json'))),
        ];
        foreach ($cases as $label => $tamper) {
            $copy = $this->tmp.'/t-'.Str::slug($label).'.zip';
            copy($this->tmp.'/s.zip', $copy);
            $zip = new ZipArchive;
            $zip->open($copy);
            $tamper($zip);
            $zip->close();
            try {
                $import($copy);
                $this->fail("{$label}: must be refused");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('refused', $e->getMessage(), $label);
            }
            $this->assertSame($totals, $this->totals(), "{$label}: nothing written");
        }

        // A bundle signed by another installation (another APP_KEY) is refused too.
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->expectExceptionMessage('signature');
        $import($this->tmp.'/s.zip');
    }

    public function test_every_registered_table_kind_has_a_scope(): void
    {
        $scope = StoreDataScope::account(1);
        foreach (StoreDataRegistry::tables() as $table) {
            /** @var StoreTable $table */
            $q = $scope->query(DB::connection(), $table);
            $this->assertTrue($table->kind === 'platform' ? $q === null : true, $table->table);
        }
        $this->assertNotEmpty($scope->orderedTables(DB::connection()));
    }
}

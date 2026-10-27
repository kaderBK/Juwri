<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Console\Kernel;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use App\Services\Backup\StoreBackupService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Juwri\Core\Jobs\GenerateStoreBackupJob;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\StoreBackup;
use Juwri\Core\Models\StoreBackupSchedule;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Ops\MediaCollector;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Accounting\Models\Expense;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Ordering\Models\OrderItemExtra;
use Juwri\Modules\Payments\Services\GatewaySecretVault;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Tests\TestCase;
use ZipArchive;

class StoreBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => (object) [], 'languages' => collect()]);
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function storeFixture(User $user, string $slug = 'burger-barn'): Store
    {
        $r = Store::create([
            'user_id' => $user->id, 'title' => 'Burger Barn', 'slug' => $slug,
            'color' => '#fff', 'sub_title' => 's', 'description' => 'd',
            'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1',
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => '1']);
        Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Cheeseburger', 'price' => 20, 'active' => '1']);

        return $r;
    }

    /** Read one file out of a StoreBackup's ZIP, or null if absent. */
    private function zipRead(StoreBackup $b, string $entry): ?string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(storage_path('app/'.$b->path)) === true, 'ZIP should open');
        $content = $zip->getFromName($entry);
        $zip->close();

        return $content === false ? null : $content;
    }

    public function test_owner_creates_backup_without_pii_excludes_customers(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        Customer::create(['store_id' => $r->id, 'name' => 'Alice', 'email' => 'a@x.com']);

        $this->actingAs($owner)->post(route('backup.store'), ['include_pii' => 0])->assertRedirect();

        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();
        $this->assertSame('ready', $b->status);            // sync queue ran it inline
        $this->assertNotNull($b->path);

        $manifest = json_decode($this->zipRead($b, 'manifest.json'), true);
        $this->assertFalse($manifest['includes_pii']);
        $this->assertStringContainsString('Cheeseburger', $this->zipRead($b, 'menu_items.json'));
        $this->assertNull($this->zipRead($b, 'customers.json'), 'PII file must be absent when not opted in');
    }

    public function test_pii_datasets_include_customers_and_orders(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        Customer::create(['store_id' => $r->id, 'name' => 'Bob', 'email' => 'bob@x.com']);
        Order::create(['store_id' => $r->id, 'type' => 'delivery', 'status' => 'pending', 'created_at' => now()]);

        $this->actingAs($owner)->post(route('backup.store'), [
            'datasets' => ['menu', 'customers', 'orders'],
        ])->assertRedirect();

        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();
        $this->assertTrue($b->include_pii); // derived from the PII datasets picked
        $this->assertStringContainsString('Bob', $this->zipRead($b, 'customers.json'));
        $this->assertNotNull($this->zipRead($b, 'orders.json'));
    }

    public function test_menu_variants_and_order_item_extras_are_exported(): void
    {
        // C3 §1.5a: both tables were absent from the export before this item.
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        $menu = Menu::where('store_id', $r->id)->firstOrFail();
        MenuVariant::create(['menu_id' => $menu->id, 'price' => 3, 'options' => json_encode(['label' => 'VarLabelXYZ']), 'active' => 1]);

        $order = Order::create(['store_id' => $r->id, 'type' => 'delivery', 'status' => 'pending', 'created_at' => now()]);
        $item = OrderItem::create(['order_id' => $order->id, 'quantity' => 1]);
        OrderItemExtra::create(['order_item_id' => $item->id, 'quantity' => 77]);

        $this->actingAs($owner)->post(route('backup.store'), [
            'datasets' => ['menu', 'menu_structure', 'orders'],
        ])->assertRedirect();

        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();
        $variants = (string) $this->zipRead($b, 'menu_variants.json');
        $this->assertStringContainsString('VarLabelXYZ', $variants);
        $extras = $this->zipRead($b, 'order_item_extras.json');
        $this->assertNotNull($extras, 'order_item_extras.json must be present');
        $this->assertStringContainsString('77', (string) $extras);
    }

    public function test_only_selected_datasets_are_exported(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);

        $this->actingAs($owner)->post(route('backup.store'), ['datasets' => ['menu']])->assertRedirect();
        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();

        $this->assertNotNull($this->zipRead($b, 'menu_items.json'));
        $this->assertNull($this->zipRead($b, 'coupons.json'));   // not selected
        $this->assertNull($this->zipRead($b, 'settings.json'));  // not selected
    }

    public function test_json_only_format_omits_csv(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);

        $this->actingAs($owner)->post(route('backup.store'), ['datasets' => ['menu'], 'format' => 'json'])->assertRedirect();
        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();

        $this->assertNotNull($this->zipRead($b, 'menu_items.json'));
        $this->assertNull($this->zipRead($b, 'menu_items.csv'));
    }

    public function test_csv_only_format_omits_json_rows(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);

        $this->actingAs($owner)->post(route('backup.store'), ['datasets' => ['menu'], 'format' => 'csv'])->assertRedirect();
        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();

        $this->assertNotNull($this->zipRead($b, 'menu_items.csv'));
        $this->assertNull($this->zipRead($b, 'menu_items.json'));
        $this->assertNotNull($this->zipRead($b, 'manifest.json')); // manifest is always JSON
    }

    public function test_order_date_window_filters_rows(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        Order::create(['store_id' => $r->id, 'type' => 'delivery', 'status' => 'paid', 'created_at' => now()->subDays(5)]);
        Order::create(['store_id' => $r->id, 'type' => 'delivery', 'status' => 'paid', 'created_at' => now()->subDays(200)]);

        $this->actingAs($owner)->post(route('backup.store'), [
            'datasets' => ['orders'], 'orders_window' => '30',
        ])->assertRedirect();
        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();

        $orders = json_decode($this->zipRead($b, 'orders.json'), true);
        $this->assertCount(1, $orders); // only the 5-day-old order is within 30 days
    }

    public function test_password_protected_zip_is_encrypted(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);

        $this->actingAs($owner)->post(route('backup.store'), [
            'datasets' => ['menu'], 'password' => 's3cret-pass',
        ])->assertRedirect();
        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();

        $this->assertTrue($b->encrypted);
        $this->assertNull($b->password_enc); // cleared once the archive exists

        // Reading an encrypted entry without the password fails; with it, succeeds.
        $zip = new ZipArchive;
        $zip->open(storage_path('app/'.$b->path));
        $this->assertFalse(@$zip->getFromName('manifest.json'));
        $zip->setPassword('s3cret-pass');
        $this->assertNotFalse($zip->getFromName('manifest.json'));
        $zip->close();
    }

    public function test_csv_handles_nested_cast_columns(): void
    {
        // Regression: Menu.translations casts to a stdClass and Customer.tags to
        // an array. A real store populates these; the CSV writer must JSON-encode
        // such cells instead of trying to stringify an object (which threw
        // "Object of class stdClass could not be converted to string").
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        Menu::where('store_id', $r->id)->first()->update([
            'translations' => ['ar' => ['title' => 'برجر']],
        ]);
        Customer::create(['store_id' => $r->id, 'name' => 'Tagged', 'email' => 't@x.com', 'tags' => ['vip', 'regular']]);

        $this->actingAs($owner)->post(route('backup.store'), [
            'datasets' => ['menu', 'customers'], 'format' => 'csv',
        ])->assertRedirect();

        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();
        $this->assertSame('ready', $b->status); // did not throw on the object/array cells
        $this->assertStringContainsString('برجر', $this->zipRead($b, 'menu_items.csv'));
        $this->assertStringContainsString('vip', $this->zipRead($b, 'customers.csv'));
    }

    public function test_schedule_can_be_created_and_run(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);

        $this->actingAs($owner)->post(route('backup.schedule.store'), [
            'frequency' => 'weekly', 'datasets' => ['menu'], 'orders_window' => 'all',
        ])->assertRedirect();

        $sched = StoreBackupSchedule::where('store_id', $r->id)->firstOrFail();
        $this->assertTrue($sched->active);
        $sched->update(['next_run_at' => now()->subMinute()]); // make it due

        $this->artisan('backups:run-scheduled')->assertExitCode(0);

        $b = StoreBackup::where('store_id', $r->id)->where('schedule_id', $sched->id)->firstOrFail();
        $this->assertSame('ready', $b->status);
        $this->assertNotNull($this->zipRead($b, 'menu_items.json'));
        $this->assertTrue($sched->fresh()->next_run_at->isFuture()); // advanced
    }

    public function test_secret_settings_are_scrubbed(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        StoreSettings::set($r->id, 'square_access_token', 'sk_live_SECRETVALUE');
        StoreSettings::set($r->id, 'default_language', 'en');

        $this->actingAs($owner)->post(route('backup.store'), ['include_pii' => 0])->assertRedirect();
        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();

        $settings = $this->zipRead($b, 'settings.json');
        $this->assertStringContainsString('default_language', $settings);
        $this->assertStringNotContainsString('square_access_token', $settings);
        $this->assertStringNotContainsString('SECRETVALUE', $settings);
    }

    public function test_gateway_vault_secrets_are_scrubbed_from_settings(): void
    {
        // WI-A1: `restaurant_telr_authkey` / `restaurant_paypal_api_app_id` match
        // no SECRET_PATTERN but ARE live payment credentials. They live in the
        // encrypted vault and store_options() overlays them into the settings map;
        // scrubSettings must drop them by the authoritative vault list.
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        GatewaySecretVault::put($r->id, 'restaurant_telr_authkey', 'TELR-LEAK-VALUE');
        GatewaySecretVault::put($r->id, 'restaurant_paypal_api_app_id', 'PAYPAL-LEAK-VALUE');

        $this->actingAs($owner)->post(route('backup.store'), ['datasets' => ['settings']])->assertRedirect();
        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();

        $settings = $this->zipRead($b, 'settings.json');
        $this->assertStringNotContainsString('restaurant_telr_authkey', $settings);
        $this->assertStringNotContainsString('TELR-LEAK-VALUE', $settings);
        $this->assertStringNotContainsString('restaurant_paypal_api_app_id', $settings);
        $this->assertStringNotContainsString('PAYPAL-LEAK-VALUE', $settings);
    }

    public function test_the_customizer_theme_settings_ride_with_the_settings_dataset(): void
    {
        // Customizer v2 (WI-V2): a store's theme customizer settings are an owner
        // dataset the backup promised to cover — they must land in the ZIP with
        // the rest of the store's configuration.
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        StoreOption::updateStoreOption($r->id, 'store_template', 'crave');
        ThemeSetting::create([
            'store_id' => $r->id, 'template' => 'crave',
            'settings' => ['colors.primary' => '#abcdef', 'texts.hero_title' => 'Backed up'],
        ]);

        $this->actingAs($owner)->post(route('backup.store'), ['datasets' => ['settings']])->assertRedirect();
        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();

        $themeSettings = $this->zipRead($b, 'theme_settings.json');
        $this->assertStringContainsString('#abcdef', $themeSettings);
        $this->assertStringContainsString('colors.primary', $themeSettings);
    }

    public function test_every_vaulted_secret_key_is_scrubbed(): void
    {
        // Pin the whole vault list so a future added gateway key is auto-covered.
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        foreach (GatewaySecretVault::secretKeys() as $i => $name) {
            GatewaySecretVault::put($r->id, $name, 'VAULTLEAK'.$i);
        }

        $this->actingAs($owner)->post(route('backup.store'), ['datasets' => ['settings']])->assertRedirect();
        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();
        $settings = $this->zipRead($b, 'settings.json');

        foreach (GatewaySecretVault::secretKeys() as $i => $name) {
            $this->assertStringNotContainsString($name, $settings, "vault key {$name} leaked");
            $this->assertStringNotContainsString('VAULTLEAK'.$i, $settings, "value for {$name} leaked");
        }
    }

    public function test_nested_object_secrets_are_scrubbed(): void
    {
        // WI-A2: a secret nested inside a JSON-object option value survives the
        // top-level cast as a stdClass; scrub must recurse into objects.
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        StoreSettings::set($r->id, 'custom_meta', json_encode(['api_key' => 'DEEPLEAK1', 'nested' => ['token' => 'DEEPLEAK2']]));

        $this->actingAs($owner)->post(route('backup.store'), ['datasets' => ['settings']])->assertRedirect();
        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();
        $settings = $this->zipRead($b, 'settings.json');

        $this->assertStringNotContainsString('DEEPLEAK1', $settings);
        $this->assertStringNotContainsString('DEEPLEAK2', $settings);
    }

    public function test_csv_cells_are_neutralised_against_formula_injection(): void
    {
        // WI-A3: an attacker-controlled customer name must not start a formula.
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        Customer::create(['store_id' => $r->id, 'name' => '=HYPERLINK("http://evil","x")', 'email' => 'e@x.com']);

        $this->actingAs($owner)->post(route('backup.store'), [
            'datasets' => ['customers'], 'format' => 'csv',
        ])->assertRedirect();
        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();

        $csv = $this->zipRead($b, 'customers.csv');
        $this->assertStringContainsString("'=HYPERLINK", $csv);          // neutralised
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);       // never a raw formula cell
    }

    public function test_large_order_set_streams_without_raising_memory(): void
    {
        // WI-B1/B4: a big store must build with a bounded memory footprint and
        // without a whole-store whereIn over every order id.
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);

        $now = now();
        $orders = [];
        for ($i = 0; $i < 1500; $i++) {
            $orders[] = ['store_id' => $r->id, 'type' => 'delivery', 'status' => 'paid', 'created_at' => $now];
        }
        Order::insert($orders);
        $ids = Order::where('store_id', $r->id)->pluck('id');
        $items = [];
        foreach ($ids as $id) {
            $items[] = ['order_id' => $id, 'quantity' => 1];
        }
        OrderItem::insert($items);

        $this->actingAs($owner)->post(route('backup.store'), [
            'datasets' => ['orders'], 'format' => 'json',
        ])->assertRedirect();
        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();

        $this->assertSame('ready', $b->status);
        $this->assertSame(1500, count(json_decode($this->zipRead($b, 'orders.json'), true)));
        $this->assertSame(1500, count(json_decode($this->zipRead($b, 'order_items.json'), true)));
    }

    public function test_working_dir_is_removed_when_build_throws(): void
    {
        // WI-B2: the tmp working dir (holding partial PII dumps) must be removed
        // on EVERY exit path, not only success.
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        Customer::create(['store_id' => $r->id, 'name' => 'Partial', 'email' => 'p@x.com']);

        $backup = StoreBackup::create([
            'store_id' => $r->id, 'user_id' => $owner->id, 'token' => StoreBackup::newToken(),
            'status' => 'pending', 'datasets' => ['customers'], 'format' => 'json', 'include_pii' => true,
        ]);

        // A service that throws after the datasets are streamed (work dir now
        // holds a partial customer dump). The finally must still sweep it.
        $service = new class extends StoreBackupService
        {
            protected function afterDatasets(string $work): void
            {
                throw new \RuntimeException('boom mid-build');
            }
        };

        $work = storage_path('app/tmp/store-backup-'.$backup->token);
        $threw = false;
        try {
            $service->build($backup->fresh());
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'the throwing build should propagate');
        $this->assertDirectoryDoesNotExist($work, 'tmp working dir must be swept on failure');
    }

    public function test_encryption_unavailable_fails_closed(): void
    {
        // WI-A4: a password was requested but the server can't AES — the build
        // must FAIL, never ship a readable archive the owner thinks is protected.
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        $backup = StoreBackup::create([
            'store_id' => $r->id, 'user_id' => $owner->id, 'token' => StoreBackup::newToken(),
            'status' => 'pending', 'datasets' => ['menu'], 'format' => 'json', 'include_pii' => false,
            'encrypted' => true, 'password_enc' => Crypt::encryptString('s3cret-pass'),
        ]);

        $service = new class extends StoreBackupService
        {
            protected function serverCanEncrypt(): bool
            {
                return false;
            }
        };
        $service->build($backup->fresh());
        $backup->refresh();

        $this->assertSame('failed', $backup->status);
        $this->assertNull($backup->path, 'no archive may be written');
        $this->assertNull($backup->password_enc);
        $this->assertStringContainsString('cannot password-protect', $backup->error);
    }

    public function test_password_is_cleared_when_build_fails(): void
    {
        // WI-A5: a build failure must not leave the transient password behind.
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        $backup = StoreBackup::create([
            'store_id' => $r->id, 'user_id' => $owner->id, 'token' => StoreBackup::newToken(),
            'status' => 'pending', 'datasets' => ['menu'], 'format' => 'json', 'include_pii' => false,
            'encrypted' => true, 'password_enc' => Crypt::encryptString('s3cret-pass'),
        ]);

        $throwing = new class extends StoreBackupService
        {
            public function build(StoreBackup $backup): void
            {
                throw new \RuntimeException('boom');
            }
        };
        new GenerateStoreBackupJob($backup->id)->handle($throwing);
        $backup->refresh();

        $this->assertSame('failed', $backup->status);
        $this->assertNull($backup->password_enc);
    }

    public function test_order_pii_is_coarsened(): void
    {
        // WI-A6: pod_otp dropped; delivery coords rounded to ~3 dp.
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        $o = Order::create(['store_id' => $r->id, 'type' => 'delivery', 'status' => 'paid', 'created_at' => now()]);
        Order::where('id', $o->id)->update([
            'delivery_lat' => 25.1234567, 'delivery_lng' => 55.7654321, 'pod_otp' => '1234',
        ]);

        $this->actingAs($owner)->post(route('backup.store'), [
            'datasets' => ['orders'], 'format' => 'json',
        ])->assertRedirect();
        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();

        $orders = json_decode($this->zipRead($b, 'orders.json'), true);
        $this->assertArrayNotHasKey('pod_otp', $orders[0]);
        $this->assertEqualsWithDelta(25.123, (float) $orders[0]['delivery_lat'], 0.0001);
        $this->assertEqualsWithDelta(55.765, (float) $orders[0]['delivery_lng'], 0.0001);
    }

    public function test_heavy_queue_drain_is_scheduled(): void
    {
        // WI-B3: a separate heavy-queue fallback drain must be registered.
        $schedule = new Schedule;
        $kernel = app(Kernel::class);
        $m = new \ReflectionMethod($kernel, 'schedule');
        $m->setAccessible(true);
        $m->invoke($kernel, $schedule);

        $found = collect($schedule->events())->contains(
            fn ($e) => str_contains((string) ($e->command ?? ''), '--queue=heavy')
        );
        $this->assertTrue($found, 'a queue:work --queue=heavy drain must be scheduled');
    }

    public function test_old_backups_are_pruned_to_the_cap_on_create(): void
    {
        // WI-B4: per-store archive count is bounded.
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        for ($i = 0; $i < StoreBackup::MAX_PER_STORE; $i++) {
            StoreBackup::create([
                'store_id' => $r->id, 'user_id' => $owner->id, 'token' => StoreBackup::newToken(),
                'status' => 'ready', 'datasets' => ['menu'], 'format' => 'json', 'include_pii' => false,
                'expires_at' => now()->addDay(),
            ]);
        }

        $this->actingAs($owner)->post(route('backup.store'), ['datasets' => ['menu']])->assertRedirect();

        $this->assertSame(
            StoreBackup::MAX_PER_STORE,
            StoreBackup::where('store_id', $r->id)->count(),
            'create must prune older archives beyond the cap'
        );
    }

    public function test_new_dataset_files_are_registry_driven_and_pii_gated(): void
    {
        // WI-C1: selecting a non-PII group produces its files; a PII group's
        // files never appear unless that PII toggle is picked.
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);

        $this->actingAs($owner)->post(route('backup.store'), [
            'datasets' => ['inventory', 'financials'], 'format' => 'json',
        ])->assertRedirect();
        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();

        // inventory files present (even if empty arrays)
        $this->assertNotNull($this->zipRead($b, 'ingredients.json'));
        $this->assertNotNull($this->zipRead($b, 'recipe_items.json'));
        $this->assertNotNull($this->zipRead($b, 'branch_stocks.json'));
        $this->assertNotNull($this->zipRead($b, 'gl_accounts.json'));
        // PII datasets NOT selected → absent
        $this->assertNull($this->zipRead($b, 'suppliers.json'), 'suppliers is a separate PII toggle');
        $this->assertNull($this->zipRead($b, 'drivers.json'));
        $this->assertNull($this->zipRead($b, 'hotel_stays.json'));
    }

    public function test_new_dataset_scopes_to_this_store_only(): void
    {
        // WI-C1: a seeded row on ANOTHER of the owner's stores must not leak.
        $owner = User::factory()->create(['status' => 1]);
        $a = $this->storeFixture($owner, 'store-a');
        $bStore = $this->storeFixture($owner, 'store-b');
        Ingredient::forceCreate(['store_id' => $bStore->id, 'name' => 'SecretB', 'unit' => 'kg']);
        Ingredient::forceCreate(['store_id' => $a->id, 'name' => 'MineA', 'unit' => 'kg']);

        // Export store A (make it the current dashboard store via its own request)
        $this->actingAs($owner);
        session(['dashboard_store_id' => $a->id]);
        $this->post(route('backup.store'), ['datasets' => ['inventory'], 'format' => 'json'])->assertRedirect();
        $bk = StoreBackup::where('store_id', $a->id)->firstOrFail();

        $ingredients = $this->zipRead($bk, 'ingredients.json');
        $this->assertStringContainsString('MineA', $ingredients);
        $this->assertStringNotContainsString('SecretB', $ingredients, 'other store ingredient must not leak');
    }

    public function test_coupons_do_not_leak_across_the_owners_stores(): void
    {
        // WI-C2: coupons are owner-keyed; a coupon bound to store B's branch must
        // not appear in store A's export. Owner-wide coupons are tagged.
        $owner = User::factory()->create(['status' => 1]);
        $a = $this->storeFixture($owner, 'coupon-a');
        $bStore = $this->storeFixture($owner, 'coupon-b');
        $branchA = Branch::create(['store_id' => $a->id, 'name' => 'A main']);
        $branchB = Branch::create(['store_id' => $bStore->id, 'name' => 'B main']);
        Coupon::forceCreate(['user_id' => $owner->id, 'code' => 'AONLY', 'branch_id' => $branchA->id, 'percentage' => 10]);
        Coupon::forceCreate(['user_id' => $owner->id, 'code' => 'BONLY', 'branch_id' => $branchB->id, 'percentage' => 10]);
        Coupon::forceCreate(['user_id' => $owner->id, 'code' => 'WIDE', 'branch_id' => null, 'percentage' => 10]);

        $this->actingAs($owner);
        session(['dashboard_store_id' => $a->id]);
        $this->post(route('backup.store'), ['datasets' => ['coupons'], 'format' => 'json'])->assertRedirect();
        $bk = StoreBackup::where('store_id', $a->id)->firstOrFail();

        $coupons = $this->zipRead($bk, 'coupons.json');
        $this->assertStringContainsString('AONLY', $coupons);
        $this->assertStringContainsString('WIDE', $coupons);
        $this->assertStringContainsString('owner-wide', $coupons); // provenance tag
        $this->assertStringNotContainsString('BONLY', $coupons, 'store B coupon must not leak');
    }

    public function test_manifest_declares_included_excluded_and_disclaimer(): void
    {
        // WI-C4: honest manifest.
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);

        $this->actingAs($owner)->post(route('backup.store'), [
            'datasets' => ['menu', 'inventory'], 'format' => 'json',
        ])->assertRedirect();
        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();

        $m = json_decode($this->zipRead($b, 'manifest.json'), true);
        $this->assertSame(3, $m['schema']);
        $this->assertArrayHasKey('menu', $m['datasets_included']);
        $this->assertArrayHasKey('inventory', $m['datasets_included']);
        $this->assertFalse($m['datasets_included']['inventory']['pii']);
        $this->assertArrayHasKey('Allergy', $m['datasets_excluded']);
        $this->assertArrayHasKey('Credential', $m['datasets_excluded']);
        $this->assertNotEmpty($m['disclaimer']);
        $this->assertSame('all', $m['branch_scope']);
    }

    public function test_media_files_are_bundled_when_enabled(): void
    {
        // WI-D1: a real menu image on disk travels in the ZIP and is indexed.
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        $dir = storage_path('app/public/menu');
        @mkdir($dir, 0755, true);
        $physical = $dir.'/probe-backup.jpg';
        file_put_contents($physical, 'JPEGBYTES-PROBE');
        Menu::where('store_id', $r->id)->first()->update(['image' => 'probe-backup.jpg']);

        $this->actingAs($owner)->post(route('backup.store'), [
            'datasets' => ['menu', 'media'], 'format' => 'json',
        ])->assertRedirect();
        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();

        $this->assertSame('JPEGBYTES-PROBE', $this->zipRead($b, 'media/menu/probe-backup.jpg'));
        $index = json_decode($this->zipRead($b, 'media_index.json'), true);
        $this->assertSame('media/menu/probe-backup.jpg', $index['menu/probe-backup.jpg']);

        $m = json_decode($this->zipRead($b, 'manifest.json'), true);
        $this->assertTrue($m['media_included']['included']);
        $this->assertGreaterThanOrEqual(1, $m['media_included']['files']);

        @unlink($physical);
    }

    public function test_media_absent_when_disabled(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);

        $this->actingAs($owner)->post(route('backup.store'), [
            'datasets' => ['menu'], 'format' => 'json', // no 'media'
        ])->assertRedirect();
        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();

        $this->assertNull($this->zipRead($b, 'media_index.json'));
        $m = json_decode($this->zipRead($b, 'manifest.json'), true);
        $this->assertFalse($m['media_included']);
    }

    public function test_media_collector_truncates_at_cap(): void
    {
        // WI-D1: over the cap → truncated + omitted counted.
        $work = storage_path('app/tmp/media-cap-test-'.uniqid());
        @mkdir($work, 0755, true);
        $dir = storage_path('app/public/menu');
        @mkdir($dir, 0755, true);
        $f1 = $dir.'/cap1.jpg';
        $f2 = $dir.'/cap2.jpg';
        file_put_contents($f1, str_repeat('A', 100));
        file_put_contents($f2, str_repeat('B', 100));

        $c = new MediaCollector($work, 100); // room for one file only
        $c->add('menu', 'cap1.jpg', ['menu']);
        $c->add('menu', 'cap2.jpg', ['menu']);
        $info = $c->finish();

        $this->assertSame(1, $info['files']);
        $this->assertTrue($info['truncated']);
        $this->assertSame(1, $info['omitted']);

        @unlink($f1);
        @unlink($f2);
        array_map('unlink', glob($work.'/media/menu/*') ?: []);
        @unlink($work.'/media_index.json');
    }

    public function test_branch_export_keeps_all_branch_rows(): void
    {
        // Wave V / C3: a NULL-branch row ("all branches") must be included in a
        // single-branch export, and another branch's row excluded.
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        $branchA = Branch::create(['store_id' => $r->id, 'name' => 'A']);
        $branchB = Branch::create(['store_id' => $r->id, 'name' => 'B']);
        Expense::forceCreate(['store_id' => $r->id, 'branch_id' => null, 'category' => 'RENT_ALL', 'amount' => 100, 'date' => now()->toDateString()]);
        Expense::forceCreate(['store_id' => $r->id, 'branch_id' => $branchB->id, 'category' => 'BONLY', 'amount' => 50, 'date' => now()->toDateString()]);

        $backup = StoreBackup::create([
            'store_id' => $r->id, 'user_id' => $owner->id, 'token' => StoreBackup::newToken(),
            'status' => 'pending', 'datasets' => ['financials'], 'format' => 'json', 'branch_id' => $branchA->id, 'include_pii' => false,
        ]);
        app(StoreBackupService::class)->build($backup->fresh());
        $b = $backup->fresh();

        $expenses = $this->zipRead($b, 'expenses.json');
        $this->assertStringContainsString('RENT_ALL', $expenses, 'all-branch row must be kept');
        $this->assertStringNotContainsString('BONLY', $expenses, 'other-branch row must be excluded');
    }

    public function test_store_logo_is_bundled_from_logo_folder(): void
    {
        // Wave V / D1: the logo lives under store/logo/, not restaurant/main/.
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        $dir = storage_path('app/public/store/logo');
        @mkdir($dir, 0755, true);
        file_put_contents($dir.'/logo-probe.png', 'PNGLOGOBYTES');
        $r->update(['main_image' => 'logo-probe.png']);

        $this->actingAs($owner)->post(route('backup.store'), ['datasets' => ['menu', 'media'], 'format' => 'json'])->assertRedirect();
        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();

        $this->assertSame('PNGLOGOBYTES', $this->zipRead($b, 'media/restaurant/logo-probe.png'));
        @unlink($dir.'/logo-probe.png');
    }

    public function test_backup_page_renders_grouped_controls(): void
    {
        // WI-F1: the page renders with the registry-grouped controls, media
        // toggle, and import tab wired.
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);

        $res = $this->actingAs($owner)->get(route('backup.index'));
        $res->assertOk();
        $res->assertSeeHtml('ds-media');              // media-files toggle present
        $res->assertSeeHtml('ds-inventory');          // a new grouped dataset
        $res->assertSeeHtml(route('backup.import'));  // import surface wired
    }

    public function test_backup_reports_progress_and_completes_at_100(): void
    {
        // WI-F1: progress advances and lands at 100 on a ready archive.
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);

        $this->actingAs($owner)->post(route('backup.store'), ['datasets' => ['menu', 'settings']])->assertRedirect();
        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();

        $this->assertSame('ready', $b->status);
        $this->assertSame(100, (int) $b->progress);

        $res = $this->actingAs($owner)->getJson(route('backup.status', $b->token));
        $res->assertOk()->assertJsonPath('progress', 100);
    }

    public function test_owner_downloads_ready_backup(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        $this->actingAs($owner)->post(route('backup.store'), ['include_pii' => 0]);
        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();

        $res = $this->actingAs($owner)->get(route('backup.download', $b->token));
        $res->assertOk();
        $this->assertSame('application/zip', $res->headers->get('content-type'));
    }

    public function test_other_owner_cannot_download(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        $this->actingAs($owner)->post(route('backup.store'), ['include_pii' => 1]);
        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();

        $intruder = User::factory()->create(['status' => 1]);
        $this->storeFixture($intruder, 'intruder-store');
        // The intruder's own store is in context; the token belongs to someone else → not found.
        $this->actingAs($intruder)->get(route('backup.download', $b->token))->assertNotFound();
    }

    public function test_cleanup_removes_expired(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        $this->actingAs($owner)->post(route('backup.store'), ['include_pii' => 0]);
        $b = StoreBackup::where('store_id', $r->id)->firstOrFail();
        $path = storage_path('app/'.$b->path);
        $this->assertFileExists($path);

        $b->update(['expires_at' => now()->subDay()]);
        $this->artisan('backups:cleanup-store')->assertExitCode(0);

        $this->assertNull(StoreBackup::find($b->id));
        $this->assertFileDoesNotExist($path);
    }

    public function test_only_one_in_flight_backup_per_store(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = $this->storeFixture($owner);
        // Seed an already-processing backup so the guard trips.
        StoreBackup::create(['store_id' => $r->id, 'user_id' => $owner->id, 'token' => StoreBackup::newToken(), 'status' => 'processing', 'include_pii' => false]);

        $this->actingAs($owner)->post(route('backup.store'), ['include_pii' => 0])->assertRedirect();
        // No NEW backup created — still just the one processing.
        $this->assertSame(1, StoreBackup::where('store_id', $r->id)->count());
    }
}

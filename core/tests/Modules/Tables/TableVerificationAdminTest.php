<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Tables;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreTable;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\SyncDashboardStore;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Tables\Services\TableSessionService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PARITY-RUN T3 — the owner side: Settings → Tables → Verification.
 *
 * Mode picker, per-table codes, regenerate (one + all), the printable tent-card
 * sheet, tenant isolation, and the T10 cross-track rotation seam.
 */
class TableVerificationAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        // The kill-switch defaults OFF; the dashboard routes carry
        // feature:table_otp, so the screen only exists once it is on.
        config(['settings.admin_allow_table_otp' => true]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        /* The dashboard layout reads $activeTheme/$settings from a view()->composer
           ViewServiceProvider only registers when APP_INSTALLED is set, and
           phpunit.xml deliberately leaves it unset. Share the composer's output by
           hand — the same stub the storefront suites use. */
        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share([
            'activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => $settings, 'languages' => collect(), 'pages' => collect(),
        ]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
            SyncDashboardStore::class,
        ]);
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 5, 'table_qr' => '1', 'allow_ordering' => '1'],
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function store(User $owner): Store
    {
        $title = 'R'.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St',
            'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function table(Store $store, int $no, array $extra = []): StoreTable
    {
        return StoreTable::create(array_merge([
            'store_id' => $store->id, 'table_no' => $no, 'key' => Str::random(12),
        ], $extra));
    }

    // --- settings screen -------------------------------------------------

    public function test_the_owner_can_open_the_verification_screen(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $this->table($store, 1);

        $this->actingAs($owner)
            ->get(route('stores.tableVerify.index', $store->id))
            ->assertOk()
            ->assertSee('Verification mode')
            ->assertSee('Table codes');
    }

    public function test_another_owner_cannot(): void
    {
        $mine = $this->store($this->owner());
        $intruder = $this->owner();

        $this->actingAs($intruder)
            ->get(route('stores.tableVerify.index', $mine->id))
            ->assertNotFound();
    }

    public function test_saving_table_code_mode_seeds_a_code_on_every_table(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $a = $this->table($store, 1);
        $b = $this->table($store, 2);

        $this->assertNull($a->verify_code);

        $this->actingAs($owner)
            ->post(route('stores.tableVerify.mode', $store->id), ['mode' => TableSessionService::MODE_TABLE_CODE])
            ->assertRedirect();

        $this->assertSame(TableSessionService::MODE_TABLE_CODE, TableSessionService::rawMode($store->id));
        $this->assertMatchesRegularExpression('/^\d{4}$/', (string) $a->fresh()->verify_code);
        $this->assertMatchesRegularExpression('/^\d{4}$/', (string) $b->fresh()->verify_code);
    }

    public function test_an_unknown_mode_falls_back_to_off_rather_than_being_stored(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);

        $this->actingAs($owner)
            ->post(route('stores.tableVerify.mode', $store->id), ['mode' => 'facial-recognition'])
            ->assertRedirect();

        $this->assertSame(TableSessionService::MODE_OFF, TableSessionService::rawMode($store->id));
    }

    public function test_regenerate_changes_one_code_and_regenerate_all_changes_every_code(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $a = $this->table($store, 1, ['verify_code' => '1111']);
        $b = $this->table($store, 2, ['verify_code' => '2222']);

        $this->actingAs($owner)
            ->post(route('stores.tableVerify.rotate', $store->id), ['table_id' => $a->id])
            ->assertRedirect();

        $this->assertNotSame('1111', $a->fresh()->verify_code);
        $this->assertSame('2222', $b->fresh()->verify_code, 'Rotating one table must not touch its neighbours.');
        $this->assertNotNull($a->fresh()->verify_code_rotated_at);

        $this->actingAs($owner)
            ->post(route('stores.tableVerify.rotateAll', $store->id))
            ->assertRedirect();

        $this->assertNotSame('2222', $b->fresh()->verify_code);
    }

    public function test_the_tent_card_sheet_renders_a_card_per_table_with_its_code(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        TableSessionService::setMode((int) $store->id, TableSessionService::MODE_TABLE_CODE);
        $table = $this->table($store, 7, ['verify_code' => '4321']);

        $this->actingAs($owner)
            ->get(route('stores.tableVerify.tentCards', $store->id))
            ->assertOk()->assertSee('4321')->assertSeeHtml($table->key);
    }

    public function test_the_tent_card_sheet_omits_codes_when_the_mode_does_not_use_them(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        TableSessionService::setMode((int) $store->id, TableSessionService::MODE_STAFF_APPROVE);
        $this->table($store, 7, ['verify_code' => '4321']);

        $this->actingAs($owner)
            ->get(route('stores.tableVerify.tentCards', $store->id))
            ->assertOk()
            ->assertDontSee('4321');
    }

    // --- service surface -------------------------------------------------

    public function test_code_for_generates_once_and_is_then_stable(): void
    {
        $store = $this->store($this->owner());
        $table = $this->table($store, 1);

        $first = TableSessionService::codeFor($table);
        $this->assertMatchesRegularExpression('/^\d{4}$/', $first);
        $this->assertSame($first, TableSessionService::codeFor($table->fresh()));
    }

    public function test_resolve_table_never_returns_a_hotel_room(): void
    {
        $store = $this->store($this->owner());
        $room = $this->table($store, 101, ['area_type' => 'room']);
        $table = $this->table($store, 1, ['area_type' => 'table']);

        $this->assertNull(TableSessionService::resolveTable((int) $store->id, $room->key));
        $this->assertSame($table->id, TableSessionService::resolveTable((int) $store->id, $table->key)->id);
        // Legacy rows predate area_type entirely and must still resolve.
        $legacy = $this->table($store, 2);
        $this->assertSame($legacy->id, TableSessionService::resolveTable((int) $store->id, $legacy->key)->id);
    }

    // --- T10 cross-track seam --------------------------------------------

    /**
     * T10 (QR designer) will call onTableQrRotated() when it rotates a table's
     * QR, so the printed code never outlives the printed QR. Until T10 merges the
     * hook is inert, and it must stay null-safe forever: an unknown key, a null
     * key or a bad store are all no-ops, never exceptions — a QR rotation must
     * not fail because of the verification layer.
     */
    public function test_the_t10_qr_rotation_seam_rotates_a_known_table_and_no_ops_otherwise(): void
    {
        $store = $this->store($this->owner());
        $table = $this->table($store, 1, ['verify_code' => '1111']);

        $new = TableSessionService::onTableQrRotated($store->id, $table->key);
        $this->assertMatchesRegularExpression('/^\d{4}$/', (string) $new);
        $this->assertSame($new, $table->fresh()->verify_code);

        $this->assertNull(TableSessionService::onTableQrRotated($store->id, null));
        $this->assertNull(TableSessionService::onTableQrRotated($store->id, ''));
        $this->assertNull(TableSessionService::onTableQrRotated($store->id, 'no-such-key'));
        $this->assertNull(TableSessionService::onTableQrRotated(null, $table->key));
        $this->assertNull(TableSessionService::onTableQrRotated(999999, $table->key));
    }
}

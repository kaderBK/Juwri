<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-M14: the three hotel `orderByRaw('COALESCE(floor_label, "")')` calls used
 * `""` as a SQL string literal. Outside ANSI_QUOTES MySQL reads `""` as an empty
 * string; WITH ANSI_QUOTES it is an empty IDENTIFIER and the statement is a 1064
 * syntax error. sqlite (what the suite runs on) accepts both, so CI never sees
 * it. This class fixes the literal to `''` and lints the class shut so a fourth
 * site fails CI. Latent, not a live outage: Laravel's `'strict' => true` strips a
 * server-level ANSI_QUOTES per session — see the WI.
 */
class RawSqlQuotingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
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
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    /** @return array{0: User, 1: Store} */
    private function hotelStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'table_qr' => '1', 'hotel' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreSettings::set($store->id, 'hotel_mode', '1');

        return [$owner, $store];
    }

    private function room(Store $store, int $no, ?string $floor): void
    {
        StoreTable::create([
            'store_id' => $store->id, 'table_no' => $no, 'key' => Str::random(10),
            'area_type' => 'room', 'floor_label' => $floor,
        ]);
    }

    public function test_no_raw_sql_uses_double_quotes_as_a_string_literal(): void
    {
        $needles = [];
        foreach ([base_path('app'), base_path('database'), base_path('routes')] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $src = (string) file_get_contents($file->getPathname());
                if (preg_match_all(
                    "/(?:orderByRaw|whereRaw|selectRaw|havingRaw|groupByRaw|DB::raw|->raw)\(\s*'([^']*\"[^']*)'/",
                    $src,
                    $m
                )) {
                    foreach ($m[1] as $sql) {
                        $needles[] = $file->getRelativePathname().': '.$sql;
                    }
                }
            }
        }

        $this->assertSame([], $needles, implode("\n", array_merge(
            ['Raw SQL must quote string literals with \' , not " .'],
            ['Outside ANSI_QUOTES MySQL reads "" as an empty string; with ANSI_QUOTES it is an'],
            ['empty IDENTIFIER and the statement is a syntax error. sqlite (what this suite runs on)'],
            ['accepts both, so CI cannot see it. Offending sites:'],
            $needles
        )));
    }

    public function test_the_hotel_room_ordering_expression_still_groups_unfiled_rooms_first(): void
    {
        [, $store] = $this->hotelStore();
        $this->room($store, 301, null);
        $this->room($store, 302, '');
        $this->room($store, 101, 'Floor 1');
        $this->room($store, 201, 'Floor 2');

        // The controller's exact expression (post-fix).
        $order = StoreTable::where('store_id', $store->id)
            ->whereIn('area_type', ['room', 'zone'])
            ->orderByRaw("COALESCE(floor_label, '')")
            ->orderBy('table_no')
            ->pluck('table_no')
            ->map(fn ($n) => (int) $n)
            ->all();

        // NULL and '' fold together at the head (tied by COALESCE, broken by
        // table_no), then the named floors alphabetically. `floor_label IS NULL,
        // floor_label` would instead return [101, 201, 301, 302] — that is the
        // regression this pins against.
        $this->assertSame([301, 302, 101, 201], $order);
    }

    public function test_the_rooms_screen_renders(): void
    {
        [$owner] = $this->hotelStore();

        $this->actingAs($owner)->get(route('hotel.rooms'))->assertOk();
        $this->actingAs($owner)->get(route('hotel.stays'))->assertOk();
    }
}

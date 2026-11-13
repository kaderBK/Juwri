<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Rebuilt superadmin Users page: stats row, filter bar, and the
 * server-side DataTables ajax feed (same request contract as before).
 */
class AdminUsersPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'site_admin_logo' => 'logo.png', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png', 'currency_sign' => '$', 'currency_pos' => 1])
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
        view()->share([
            'admin_languages' => collect(),
            'notifications' => collect(),
            'totalUnreadNotifications' => 0,
            'totalUnapprovedComments' => 0,
        ]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        $this->registerSqliteMysqlShims();
    }

    /**
     * usersTableQuery() uses MySQL-only SQL functions; give the sqlite
     * test connection equivalent implementations so the real query runs.
     */
    private function registerSqliteMysqlShims(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $pdo = DB::connection()->getPdo();

        $pdo->sqliteCreateFunction('JSON_UNQUOTE', function ($value) {
            return $value === null ? null : trim((string) $value, '"');
        }, 1);

        // NB: do NOT shim JSON_EXTRACT. SQLite (3.9+, and the 3.53 CI/dev runtime)
        // has a native json_extract, and sqliteCreateFunction names are
        // case-insensitive — registering 'JSON_EXTRACT' OVERRIDES the built-in on
        // this process's shared :memory: PDO for every later test. The old shim
        // resolved `$.key` but not the quoted-label path `$."key"` that Laravel's
        // `->` JSON operator compiles to, so it silently broke json-column queries
        // (e.g. ResellerManagedController's meta->store fence) in any test that ran
        // after this one. usersTableQuery already emits native json_extract() on
        // sqlite (UserController::usersTableQuery), so no shim is needed.

        $pdo->sqliteCreateFunction('DATE_FORMAT', function ($date, $format) {
            if ($date === null) {
                return null;
            }
            $timestamp = is_numeric($date) ? (int) $date : strtotime((string) $date);
            if ($timestamp === false) {
                return null;
            }

            return date(strtr((string) $format, ['%Y' => 'Y', '%m' => 'm', '%d' => 'd', '%H' => 'H', '%i' => 'i', '%s' => 's']), $timestamp);
        }, 2);

        $pdo->sqliteCreateFunction('UNIX_TIMESTAMP', function ($date) {
            if ($date === null) {
                return null;
            }

            return is_numeric($date) ? (int) $date : (strtotime((string) $date) ?: null);
        }, 1);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin']);
    }

    public function test_admin_sees_stats_and_seeded_user_row(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create(['name' => 'Zulu Rowman', 'group_id' => 'trial', 'status' => 1]);

        $response = $this->actingAs($admin)->get(route('admin.users.index'));

        $response->assertStatus(200);
        $response->assertSee('Users (all time)'); // lang key "total_users"
        $response->assertSee('Paid subscribers');
        $response->assertSee('Free & trial');
        $response->assertSee('New last 30 days');
        $response->assertSee('Create'); // lang key "add_new"
        $response->assertSee('Bulk Import');
        // Computed stats rendered into the cards: admin + trial user.
        $response->assertSeeHtml('data-stat="total">2</span>');
        // R6: paid = live paid subscriptions. The legacy count included the ADMIN
        // (group_id '1' colliding with plan 1) — that bug is gone: paid is 0 here.
        $response->assertSeeHtml('data-stat="paid">0</span>');
        $response->assertSeeHtml('data-stat="free_trial">1</span>');
        $response->assertSeeHtml('data-stat="new_30d">2</span>');

        // v2: rows are server-rendered directly in the page.
        $response->assertSee('Zulu Rowman');

        // The legacy ajax feed must stay intact — exports depend on it.
        $ajax = $this->actingAs($admin)->get(
            route('admin.users.index', ['draw' => 1, 'start' => 0, 'length' => 25]),
            ['X-Requested-With' => 'XMLHttpRequest']
        );

        $ajax->assertOk();
        $ajax->assertSee('Zulu Rowman');
        $this->assertSame(2, $ajax->json('recordsTotal'));
        $this->assertContains($user->id, collect($ajax->json('data'))->pluck('DT_RowId')->all());
    }

    public function test_search_filter_narrows_results(): void
    {
        $admin = $this->makeAdmin();
        User::factory()->create(['name' => 'Alphacastle Unique', 'group_id' => 'trial', 'status' => 1]);
        User::factory()->create(['name' => 'Bravoharbor Distinct', 'group_id' => 'trial', 'status' => 1]);

        $response = $this->actingAs($admin)->get(
            route('admin.users.index', [
                'draw' => 1,
                'start' => 0,
                'length' => 25,
                'search' => ['value' => 'Alphacastle', 'regex' => 'false'],
            ]),
            ['X-Requested-With' => 'XMLHttpRequest']
        );

        $response->assertOk();
        $response->assertSee('Alphacastle Unique');
        $response->assertDontSee('Bravoharbor Distinct');
        $this->assertSame(1, $response->json('recordsFiltered'));
        $this->assertSame(3, $response->json('recordsTotal'));
    }

    public function test_non_admin_is_redirected(): void
    {
        $user = User::factory()->create(['group_id' => 'trial', 'status' => 1]);

        $this->actingAs($user)->get(route('admin.users.index'))->assertRedirect();
    }
}

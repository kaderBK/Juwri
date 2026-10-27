<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests;

use App\Providers\ViewServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Str;
use Juwri\Core\Editions\EditionPackages;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\SsrfGuard;
use Laravel\Passport\Passport;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use PDO;
use Tests\Support\EditionTestHooks;
use Tests\Support\StaticMemoStamps;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * When true (default), setUp() arms Http::preventStrayRequests() so any
     * unfaked outbound HTTP call throws. A test that genuinely must reach the
     * network — or that asserts on stray-request behaviour itself — can override
     * this to false. Prefer Http::fake() over flipping this.
     */
    protected bool $preventStrayRequests = true;

    protected function setUp(): void
    {
        /* P1-04: a test whose own setUp() throws after RefreshDatabase opened its transaction never reaches
           tearDown's rollback, and the shared in-memory sqlite PDO stays inside that transaction — every later
           test in the same worker then fails with "cannot start a transaction within a transaction", hiding
           the real failure behind thousands of cascaded ones. No test starts inside a transaction, so close
           any leftover one first. */
        foreach (RefreshDatabaseState::$inMemoryConnections as $pdo) {
            while ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            /* The same shared PDO kept `PRAGMA foreign_keys = OFF` from the few tests that switch it off for
               themselves (MenuPlacementAudit, StorefrontMenuBuildB2, StorefrontHiddenCategory), so every later test
               in that worker ran without foreign keys. Restore the configured state (can only change outside a
               transaction, i.e. here). */
            if ($pdo instanceof PDO && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                && filter_var(getenv('DB_FOREIGN_KEYS') === false ? true : getenv('DB_FOREIGN_KEYS'), FILTER_VALIDATE_BOOLEAN)) {
                $pdo->exec('PRAGMA foreign_keys = ON');
            }
        }

        parent::setUp();

        /* P1-17: defer() callbacks (post-response view counters, flexible-cache refreshes) run at once in tests, so
           a test sees their effect without depending on when the test client terminates the kernel. */
        $this->withoutDefer();

        /* Recycled application object ids must not let one test's static memos leak into the next. */
        StaticMemoStamps::reset();

        /* Process-global request state must not leak between tests of one worker. The storefront reads the visitor's language from
           the `$_COOKIE['Quick_user_lang_code']` superglobal (TranslatesForStorefront, StoreController's `?lang=` switch), which
           Laravel's app re-creation does not reset: a language-switch test left `ar` behind and the next test in that worker saw
           translated model names (`Menu::name` returned Arabic, so "Bananas" was missing). Seen only in parallel runs, where the
           order of tests within a worker varies. */
        $_COOKIE = [];

        $this->skipIfPrefixIsIncompatibleWithSqlite();
        $this->skipIfMysqlOnlyOnAnotherDriver();

        /* Parallel isolation: half a dozen test classes run `passport:keys
           --force`, which rewrites ONE shared keypair under storage/. Run with
           `artisan test --parallel` that is a race — worker A mints a token,
           worker B regenerates the keys, A's request then 401s on "Token
           signature mismatch". Give each worker its own key directory; serial
           runs (no token) get their own `serial` directory, seeded on demand, so a
           Passport test passes alone / in any order on a checkout where nobody ever
           ran `passport:keys` ("Invalid key supplied"). Each test re-points Passport
           here, undoing any key dir a previous test loaded. */
        if (class_exists(Passport::class)) {
            $token = ParallelTesting::token() ?: 'serial';
            $keyDir = storage_path('framework/testing/passport-'.$token);
            File::ensureDirectoryExists($keyDir);
            Passport::loadKeysFrom($keyDir);

            /* Seed the worker's pair once: tests that never run `passport:keys`
               themselves used to inherit the shared storage/ keypair, and would
               otherwise meet an empty directory ("Invalid key supplied"). */
            if (! File::exists($keyDir.'/oauth-private.key')) {
                $this->artisan('passport:keys', ['--force' => true])->run();
            }
        }

        /* T6 (testing): arm the stray-request guard so any unfaked outbound HTTP
           call (AI provider chain, gateway SDK verifies, webhook fan-out) throws
           instead of silently hitting the live network — a determinism hole that
           lets a test pass on a live network and flake when it is down. Tests that
           legitimately need an outbound call must Http::fake() it explicitly; do
           NOT disable the guard to make one pass (CONVENTIONS §no-global-setting).

           Opt-out: a test whose subject genuinely cannot be faked (e.g. it asserts
           on the HTTP client's own stray-request behaviour) can set
           $preventStrayRequests = false. Keep this list empty in spirit — every
           opt-out is a test that was never deterministic. */
        if ($this->preventStrayRequests) {
            Http::preventStrayRequests();
        }

        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        /* M0-5 (monetization): FeatureUpsell memoises per key, and its cache is a
           STATIC that survives RefreshDatabase — correct in production (one
           request, one process) but leaky across tests in one process. Before
           M0-5 nothing in a request path read it after a DB reset, so the leak
           was invisible; now EnsureEntitled asks FeatureUpsell::sellable(), so a
           key seeded sellable in one test could look sellable in the next with a
           different DB. Clearing it here makes every test see only its own
           catalog state. */
        /* P1-13 13i: FeatureUpsell is in the Enterprise package — its test hook does the reset (and any other per-test
           reset an installed edition needs). */
        foreach (EditionTestHooks::all() as $hook) {
            $hook::setUp();
        }
    }

    /**
     * P1-13 13i part 2b: a test (or one method of a mostly-Community test class) whose subject is plan, billing,
     * reseller or marketplace behaviour of an installed edition package: it runs in the private suite and is skipped in the
     * Community mirror, where no package is installed (the mirror lists these skips as its known coverage gap).
     */
    protected function requiresEditionPackage(string $why = 'plan, billing, reseller or marketplace behaviour of an edition package'): void
    {
        if (EditionPackages::all() === []) {
            $this->markTestSkipped('Needs an edition package: '.$why);
        }
    }

    /**
     * Make the `sqlite-prefix-skip` group enforce itself.
     *
     * Laravel compiles whereJsonContains() on sqlite to
     * `... from json_each(<field>) where "json_each"."value" is ?`, and it builds
     * that right-hand side with Grammar::wrap('json_each.value'), which treats
     * `json_each` as a real table and glues the connection's table prefix onto
     * it — so with DB_PREFIX=qrtest_ the query asks for a column on a table named
     * qrtest_json_each that does not exist. The query itself is portable: MySQL
     * (what production runs) compiles the same builder call to JSON_CONTAINS,
     * which never names the virtual table, so this is a sqlite-only framework
     * artifact and not a production bug.
     *
     * CI already keeps those tests out of the prefixed lane with
     * `--exclude-group sqlite-prefix-skip` while running them for real in the
     * unprefixed lane. A local run has no such flag, and a dev .env carries the
     * production DB_PREFIX=qrtest_, so the same tests came back as a confusing
     * "no such column: qrtest_json_each.value" — or, where the caller swallows
     * the exception, as a silent wrong answer. Honour the group at runtime so the
     * lane a run is actually in decides, not the command line.
     */
    private function skipIfPrefixIsIncompatibleWithSqlite(): void
    {
        if (! in_array('sqlite-prefix-skip', $this->groups(), true)) {
            return;
        }

        $connection = $this->app['db']->connection();

        if ($connection->getDriverName() === 'sqlite' && $connection->getTablePrefix() !== '') {
            $this->markTestSkipped(
                'whereJsonContains() is broken by a table prefix on sqlite (Grammar::wrap prefixes the '
                .'json_each virtual table). Production is MySQL/JSON_CONTAINS and is unaffected; this test '
                .'runs for real in the unprefixed lane.'
            );
        }
    }

    /**
     * H14: make the `mysql` group enforce itself.
     *
     * A handful of production paths only exist on MySQL: the driver-forked SQL in
     * Admin\UserController (UNIX_TIMESTAMP / JSON_UNQUOTE / DATE_FORMAT),
     * PlatformMetrics' mysql-gated backup alert, and PosService::refund()'s
     * lockForUpdate — which compiles to NOTHING on sqlite, so a refund-cap test
     * on sqlite proves arithmetic and never mutual exclusion. Those tests are
     * tagged #[Group('mysql')] and run in the nightly MySQL-8 lane
     * (.github/workflows/nightly.yml, job `test-mysql`).
     *
     * Honour the group at runtime, exactly like sqlite-prefix-skip above, so the
     * lane a run is actually in decides — not the command line. That keeps a
     * plain `php artisan test` (sqlite, per phpunit.xml) green for every
     * developer and every existing CI job, with no --exclude-group flag to
     * remember anywhere.
     */
    private function skipIfMysqlOnlyOnAnotherDriver(): void
    {
        if (! in_array('mysql', $this->groups(), true)) {
            return;
        }

        $driver = $this->app['db']->connection()->getDriverName();

        if ($driver !== 'mysql') {
            $this->markTestSkipped(
                'This test asserts MySQL-only behaviour (driver forks, row locks, mysql-gated '
                ."alerts) and the current connection is '{$driver}'. It runs for real in the "
                .'nightly MySQL-8 lane (.github/workflows/nightly.yml, job test-mysql).'
            );
        }
    }

    /**
     * WI-M12 — the ONE definition of the layout view context a test renders against.
     *
     * phpunit.xml pins APP_INSTALLED=false, so ViewServiceProvider::boot() skips all
     * eight of its view()->composer() registrations and no view ever receives
     * $settings / $activeTheme / $languages. 200+ test files worked around that by
     * hand-rolling a double; most an anonymous class with a __get that returns null
     * for anything unset.
     *
     * Production does NOT hand a view that object. It hands array_to_object() over
     * the options table -- a plain stdClass whose JSON-valued rows are already
     * decoded (ViewServiceProvider). QrDesignerController still carries the comment
     * from the time this divergence 500'd the real dashboard while the suite was green.
     *
     * So this builds the context through the SAME expression the composer uses.
     *
     * @param  array<string,mixed>  $options  option_name => option_value overrides
     */
    protected function shareLayoutGlobals(array $options = [], bool $admin = false): void
    {
        $rows = collect(array_merge([
            'theme_color' => '#111',
            'site_title' => 'TestApp',
            'meta_description' => 'T',
            'social_share_image' => 'default.png',
            'userlangsel' => 0,
            'include_language_code' => 0,
            'site_favicon' => 'favicon.png',
            // The admin layout + dashboard/settings pages read these too
            // (main.blade.php, includes/sidebar.blade.php, dashboard/index.blade.php,
            // settings/*.blade.php) without a null-coalesce, so a missing key 500s
            // instead of rendering a blank default like production does. Values
            // match the options-table seed defaults (create_options_table migration).
            'site_admin_logo' => 'adminlogo.png',
            'site_logo' => 'classic-theme_logo.png',
            'site_logo_footer' => 'classic-theme_footer_logo.png',
            'currency_sign' => '$',
            'currency_code' => 'USD',
            'currency_pos' => '1',
            'admin_email' => 'test@gmail.com',
            'default_user_plan' => 'free',
            'timezone' => 'Asia/Kolkata',
            'blog_comment_approval' => '1',
            'blog_comment_user' => '1',
        ], $options));

        // Identical to ViewServiceProvider -- JSON rows decode, then the whole map
        // becomes a stdClass. Do not "simplify" to (object) $rows.
        foreach ($rows as $key => $value) {
            $rows[$key] = Str::isJson((string) $value) ? json_decode((string) $value) : $value;
        }

        $shared = [
            'settings' => array_to_object($rows),
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'languages' => collect(),
            'pages' => collect(),
        ];

        if ($admin) {
            // Mirrors the three admin composers.
            $shared += [
                'admin_languages' => collect(),
                'notifications' => collect(),
                'totalUnreadNotifications' => 0,
                'totalUnapprovedComments' => 0,
            ];
        }

        view()->share($shared);
    }

    /**
     * WI-M12 — render against the REAL view composers instead of a fixture.
     *
     * Copies the idiom proven by WildcardComposerQueryCountTest. Two traps:
     *  1. ViewServiceProvider::boot() branches on is_admin_url(), which reads
     *     request()->path() AT BOOT. Providers boot once per test, not once per
     *     request, so a test can only exercise ONE branch -- pass $path to choose
     *     it, and never assert on both in one method.
     *  2. boot() ADDS composers; calling this twice in one test registers the
     *     wildcard composer twice and doubles its query count. Call it once.
     */
    protected function bootRealViewComposers(?string $path = null): void
    {
        if ($path !== null) {
            $this->app->instance('request', Request::create($path));
        }

        config(['app.installed' => true]);

        new ViewServiceProvider($this->app)->boot();
    }

    /**
     * P1-04 5b: stores.account_id and every store row's account_id are NOT NULL, so a fixture that writes rows for a
     * made-up store id (`'store_id' => 1` with no store) needs that store to exist. Creates each missing store with
     * exactly that id and its own owner (the owner's account comes from the Post saving hook).
     */
    protected function seedStores(int ...$ids): void
    {
        foreach ($ids as $id) {
            if (DB::table('stores')->where('id', $id)->exists()) {
                continue;
            }
            $owner = User::factory()->create();
            $store = new Store;
            $store->forceFill([
                'id' => $id, 'user_id' => $owner->id, 'slug' => 'seeded-store-'.$id, 'color' => '#111',
                'title' => 'Store '.$id, 'sub_title' => 's', 'description' => 'd', 'main_image' => 'd.png',
                'cover_image' => 'd.png', 'address' => 'a', 'phone' => '1',
            ])->save();
        }
    }

    protected function tearDown(): void
    {
        SsrfGuard::$resolver = null; // P6-68: test DNS seam never leaks
        /*
         * Symfony stores trusted-host patterns in static request state. Tests
         * that temporarily boot the application as "production" legitimately
         * activate TrustHosts, but without this reset the patterns leak into
         * later test applications and reject their synthetic tenant domains.
         */
        Request::setTrustedHosts([]);

        /*
         * Livewire (pulled in by Pulse) keeps "a component rendered this request" in a STATIC flag that only its
         * own testing helpers reset. A test that renders the Pulse dashboard left it set, so every later test's
         * HTML response got Livewire's assets auto-injected (ResellerParityC1Test saw an extra <style> only in a
         * broad run). Reset it with the application, like the trusted hosts above.
         */
        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        SupportAutoInjectedAssets::$forceAssetInjection = false;

        parent::tearDown();
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\AbuseHoldWall;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StaffMember;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\AbuseRateLimit;
use Juwri\Core\Http\Middleware\EnsureEntitled;
use Juwri\Core\Http\Middleware\EnsureFeatureEnabled;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ordering\Models\CustomerNotification;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\Modules\Integrations\OwnerApiIdorCensusTest;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-59 — generated cross-store IDOR sweep, read from the live router.
 *
 * For EVERY non-superadmin route that is authenticated as an owner (web), staff (session or
 * bearer) or storefront customer (session or bearer) and takes a path parameter, the sweep
 * builds two worlds (A = attacker, B = victim), seeds B's row for each parameter (the table is
 * read from the controller's type-hint, the `Model::…$id` lookup in its body, or the parameter
 * name), logs in as A and hits B's id. Store parameters are pinned to A's own store when the
 * route also names a child record (the classic nested IDOR); otherwise they point at B.
 *
 * A route LEAKS when, after A's request:
 *  - B's target row was changed or deleted, or
 *  - a new row was written into B's store in any seeded table, or
 *  - a 2xx response body carries B's unique marker text.
 *
 * Plan / entitlement / throttle / abuse gates are lifted so a 403 from a plan never hides a
 * missing ownership check. Owner API v1 (ApiTokenAuth) is out of scope here — it has its own
 * census ({@see OwnerApiIdorCensusTest}).
 *
 * Classified non-leaks live in ALLOW (route => reason). Findings: the internal IDOR sweep notes.
 * Set IDOR_SWEEP_REPORT=1 to print every row (route, status, verdict) as JSON.
 */
class CrossStoreIdorSweepTest extends TestCase
{
    use RefreshDatabase;

    /** Route-name (or "METHOD uri") => why a flagged hit is not a cross-store leak. */
    /**
     * Route-name (or "METHOD uri") => why a flagged hit is not a cross-store leak.
     *
     * @return array<string,string>
     */
    private static function allow(): array
    {
        return [];
    }

    /** Parameter names that address a store. */
    private const STORE_PARAMS = ['restaurant', 'storeId', 'restaurant_id', 'store', 'storeId', 'store_id'];

    /** Parameters that are not record ids (enum-ish path segments, public tokens). */
    private const NON_ID = ['action', 'provider', 'slug', 'hash', 'token', 'manage_token', 'track_token', 'key',
        'app', 'appId', 'dataset', 'course'];

    /**
     * Hard-coded route-name => param => [table, key] mappings for routes the generic tableFor()
     * heuristic could not resolve. The table exists in core/database/migrations (verified by grep
     * for each entry) and the key column is present on it. When the table cannot be seeded for
     * the victim world the existing probe path reports the route as "unseedable" (still safe).
     *
     * @var array<string, array<string, array{0: string, 1: string}>>
     */
    private const PARAM_TABLE_HINTS = [
        'orders.split.pay' => ['share' => ['check_payments', 'id']],
        'apps.install' => ['appId' => ['apps', 'id']],
        'apps.uninstall' => ['appId' => ['apps', 'id']],
        'apps.embed' => ['appId' => ['apps', 'id']],
        'apps.sessionToken' => ['appId' => ['apps', 'id']],
        'user.store.apps.reconsent' => ['app' => ['apps', 'id']],
        'partner.apps.edit' => ['app' => ['apps', 'id']],
        'partner.apps.update' => ['app' => ['apps', 'id']],
        'partner.apps.rotate-secret' => ['app' => ['apps', 'id']],
        'partner.apps.media' => ['app' => ['apps', 'id']],
        'partner.apps.icon' => ['app' => ['apps', 'id']],
        'pos.tables.unmerge' => ['group' => ['table_merge_groups', 'id']],
        'pos.print.test' => ['profile' => ['printer_profiles', 'id']],
        'ai.menu-actions.act' => ['action' => ['ai_menu_actions', 'id']],
        'hotel.pms.services.post' => ['stay' => ['hotel_stays', 'id']],
        'hotel.pms.events.settle' => ['event' => ['hotel_events', 'id']],
        'backup.status' => ['token' => ['store_backups', 'token']],
        'backup.download' => ['token' => ['store_backups', 'token']],
        'backup.destroy' => ['token' => ['store_backups', 'token']],
        'backup.import.confirm' => ['token' => ['store_imports', 'token']],
        'backup.import.status' => ['token' => ['store_imports', 'token']],
        'backup.import.destroy' => ['token' => ['store_imports', 'token']],
        'transfer.accept' => ['token' => ['store_transfers', 'token']],
        'transfer.decline' => ['token' => ['store_transfers', 'token']],
        'transfer.cancel' => ['token' => ['store_transfers', 'token']],
        'user.store.install.app' => ['appId' => ['apps', 'id']],
        'user.store.buy.app.card' => ['appId' => ['apps', 'id']],
        'reseller.blogAutopilot.topics.draft' => ['topic' => ['blog_topics', 'id']],
        'reseller.blogAutopilot.topics.reject' => ['topic' => ['blog_topics', 'id']],
    ];

    /**
     * Route-name => reason for routes whose path param is not a tenant record id (provider names,
     * template keys, dataset/action names, marketplace slugs, public help-center slugs, customer
     * routes whose only param is the store (data keyed to the logged-in customer), reseller invite
     * tokens that are secrets by design). The probe short-circuits these routes with verdict
     * "not-a-record"; they are counted separately in the report (NEVER as "unresolved" and NEVER
     * as "safe"). Only routes whose controller confirms no tenant record is addressed by the
     * param belong here.
     *
     * @var array<string, string>
     */
    private const NOT_A_RECORD = [
        'social.connect' => 'provider name (e.g. google, facebook); not a record id',
        'social.disconnect' => 'provider name (e.g. google, facebook); not a record id',
        'theme.studio.section' => 'sid is a regex-validated layout section key within the store\'s own theme_settings; no DB row',
        'user.store.apps.show' => 'marketplace app slug; the public catalog detail, not a tenant record',
        'user.store.themes.show' => 'marketplace theme slug; the public catalog detail, not a tenant record',
        'user.store.buy.theme' => 'marketplace theme slug; purchase target is a public listing, not a tenant record',
        'stores.posbridge.disconnect' => 'pos-bridge provider name (square/foodics/...); not a record id',
        'stores.dispatch.couriers.save' => 'courier provider name; not a record id',
        'stores.dispatch.couriers.disconnect' => 'courier provider name; not a record id',
        'backup.account.download' => 'token is a file-system archive name in the owner\'s own account folder; no DB row',
        'feature.locked' => 'feature key (e.g. hotel, pos_enabled); not a record id',
        'pos.terminal.status' => 'uuid is the gateway-side charge client reference passed to a payment terminal; no DB row',
        'marketplace.review' => 'marketplace theme slug; the review target is a public listing, not a tenant record',
        'apps.store.review' => 'marketplace app slug; the review target is a public listing, not a tenant record',
        'reseller.help.raw' => 'public reseller help-center slug; not a tenant record',
        'reseller.help.show' => 'public reseller help-center slug; not a tenant record',
        'publicView.loyalty.balance' => 'customer route scoped to the logged-in customer; data keyed to that customer, not a tenant record',
        'publicView.loyalty.redeem' => 'customer route scoped to the logged-in customer; data keyed to that customer, not a tenant record',
        'publicView.googleWallet.loyaltyJwt' => 'customer route scoped to the logged-in customer; data keyed to that customer, not a tenant record',
        'publicView.notifications.index' => 'customer route scoped to the logged-in customer; data keyed to that customer, not a tenant record',
        'publicView.notifications.read' => 'customer route scoped to the logged-in customer; data keyed to that customer, not a tenant record',
        'publicView.notifications.unread' => 'customer route scoped to the logged-in customer; data keyed to that customer, not a tenant record',
        'publicView.myOrders.index' => 'customer route scoped to the logged-in customer; data keyed to that customer, not a tenant record',
        'publicView.wallet.balance' => 'customer route scoped to the logged-in customer; data keyed to that customer, not a tenant record',
        'publicView.wallet.topup' => 'customer route scoped to the logged-in customer; data keyed to that customer, not a tenant record',
        'publicView.wallet.topup.start' => 'customer route scoped to the logged-in customer; data keyed to that customer, not a tenant record',
        'customer.follow' => 'customer-token route scoped to the logged-in customer; data keyed to that customer, not a tenant record',
        'customer.unfollow' => 'customer-token route scoped to the logged-in customer; data keyed to that customer, not a tenant record',
        'integrations.switch.template' => 'dataset name (e.g. menus, orders); a static CSV template lookup, not a record id',
        'reseller.emailTemplates.builder' => 'reseller email template key; the builder page address is a catalog key, not a tenant record',
        'reseller.emailTemplates.builder.save' => 'reseller email template key; the builder save address is a catalog key, not a tenant record',
        'reseller.emailTemplates.builder.publish' => 'reseller email template key; the builder publish address is a catalog key, not a tenant record',
        'reseller.emailTemplates.builder.preview' => 'reseller email template key; the builder preview address is a catalog key, not a tenant record',
        'reseller.emailTemplates.builder.assets' => 'reseller email template key; the builder assets address is a catalog key, not a tenant record',
        'reseller.emailTemplates.builder.ai' => 'reseller email template key; the builder AI address is a catalog key, not a tenant record',
        'reseller.emailTemplates.builder.template' => 'reseller email template key; the builder template switch is a catalog key, not a tenant record',
        'reseller.team.accept' => 'invite token for a reseller team seat; secret by design, not a tenant record',
        'reseller.team.accept.store' => 'invite token for a reseller team seat; secret by design, not a tenant record',
    ];

    private const MARK = 'ZQLEAKB';

    /** @var array<string,array<string,mixed>> */
    private array $worlds = [];

    /** @var array<string,?int> "world:table" => seeded row id */
    private array $seeded = [];

    private int $victimSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureFeatureEnabled::class,
            EnsureEntitled::class, ThrottleRequests::class, AbuseRateLimit::class, AbuseHoldWall::class]);
    }

    private function deferForeignKeys(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('IDOR sweep fixtures rely on sqlite (deferred FKs, sqlite_master); route scoping is driver-independent.');
        }
        DB::statement('PRAGMA defer_foreign_keys = ON');
    }

    public function test_no_route_lets_store_a_reach_store_bs_records(): void
    {
        // Generic fixtures seed parents best-effort; FK checks are deferred to a commit that never comes
        // (RefreshDatabase rolls the test's transaction back).
        $this->deferForeignKeys();
        $rows = $this->sweep();

        $counts = [
            'safe' => 0, 'LEAK' => 0, 'unresolved' => 0, 'not-a-record' => 0, 'error' => 0, 'skipped-domain' => 0,
        ];
        foreach ($rows as $r) {
            $counts[$r['verdict']] = ($counts[$r['verdict']] ?? 0) + 1;
        }

        if (getenv('IDOR_SWEEP_REPORT')) {
            fwrite(STDOUT, "\nIDOR_SWEEP_COUNTS ".json_encode($counts, JSON_UNESCAPED_SLASHES)."\n");
            fwrite(STDOUT, "\nIDOR_SWEEP_JSON ".json_encode($rows, JSON_UNESCAPED_SLASHES)."\n");
        }

        $this->assertGreaterThan(300, count($rows), 'the sweep must actually cover the route table');
        // The generic tableFor() heuristic is intentionally lossy; PARAM_TABLE_HINTS covers the
        // routes it cannot resolve. The unresolved count must stay small enough to inspect by hand.
        $this->assertLessThanOrEqual(10, $counts['unresolved'],
            'Too many routes the sweep could not resolve; extend PARAM_TABLE_HINTS to cover them.');
        $leaks = array_filter($rows, fn ($r) => $r['verdict'] === 'LEAK' && ! array_key_exists($r['route'], self::allow()));
        $this->assertSame([], array_values(array_map(fn ($r) => $r['route'].' ['.$r['method'].' '.$r['uri'].'] '.$r['why'], $leaks)),
            'Cross-store IDOR: store A reached store B\'s record. Scope the lookup to the acting owner/staff/customer.');
    }

    public function test_the_sweep_flags_a_deliberately_unscoped_route(): void
    {
        // Anti-vacuity: an owner route that deletes ANY order by id must be reported as a leak.
        Route::middleware(['web', 'auth'])->delete('__idor_probe/{order}', [IdorProbeController::class, 'destroy'])->name('__idor.probe');
        Route::getRoutes()->refreshNameLookups();
        $this->deferForeignKeys();
        $this->worlds['A'] = $this->world('A');
        $this->worlds['B'] = $this->world('B');

        $route = Route::getRoutes()->getByName('__idor.probe');
        $this->assertNotNull($route);
        $row = $this->probe($route, (string) $this->actor($route));

        $this->assertSame('LEAK', $row['verdict'], json_encode($row, JSON_UNESCAPED_SLASHES) ?: '');
        $this->assertStringContainsString('deleted orders#', $row['why']);
    }

    public function test_customer_notifications_unread_is_scoped_to_acting_customer(): void
    {
        $this->deferForeignKeys();
        $this->worlds['A'] = $this->world('A');
        $this->worlds['B'] = $this->world('B');

        $customerB = $this->worlds['B']['customer'];
        $storeB = $this->worlds['B']['store'];
        for ($i = 0; $i < 2; $i++) {
            CustomerNotification::create([
                'customer_id' => $customerB->getKey(),
                'store_id' => $storeB->id,
                'type' => 'order_status',
                'title' => 'Ready',
                'body' => 'Order ready',
            ]);
        }

        // Guest cannot read store B's unread notification count.
        $this->getJson(route('publicView.notifications.unread', $storeB->id))->assertUnauthorized();

        // Store A's customer hitting store B's URL gets only their own unread count (0), never B's (2).
        $this->actingAs($this->worlds['A']['customer'], 'customer')
            ->getJson(route('publicView.notifications.unread', $storeB->id))
            ->assertOk()
            ->assertExactJson(['unread' => 0]);

        // Positive control: store B's customer sees their own 2 unread notifications.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($customerB, 'customer')
            ->getJson(route('publicView.notifications.unread', $storeB->id))
            ->assertOk()
            ->assertExactJson(['unread' => 2]);
    }

    /**
     * BL-036 review: a staff member holding EVERY grantable permission (PERMISSION_CATALOG opens many
     * owner pages to staff) never reaches a record of ANOTHER store of the same owner. The victim world
     * S is a sibling store of A's owner, so a lookup scoped only by user_id would leak.
     */
    public function test_staff_never_reach_a_sibling_store_of_the_same_owner(): void
    {
        $this->deferForeignKeys();
        $this->worlds['A'] = $this->world('A');
        $this->worlds['S'] = $this->world('S', $this->worlds['A']['owner']);
        $this->worlds['A']['staff']->forceFill(['permissions' => array_fill_keys(StaffMember::PERMISSION_KEYS, true)])->save();
        $perm = new ReflectionMethod(EnsureStaffDashboardPermission::class, 'permissionForRoute');

        $rows = [];
        $queryLeaks = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $mw = array_values(array_filter($route->middleware(), 'is_string'));
            if (! str_contains($route->getActionName(), '@') || ! $route->getName()
                || ! (in_array('staff.dashboard', $mw, true) || str_contains(implode(' ', $mw), 'EnsureStaffDashboardPermission'))
                || $perm->invoke(new EnsureStaffDashboardPermission, $route->getName()) === false) {
                continue;
            }
            if ($route->parameterNames()) {
                $rows[] = $this->probe($route, 'sibling-staff');

                continue;
            }
            // Pages that pick their store from the query string: point them at the sibling store.
            if (in_array('GET', $route->methods(), true) && ! $route->getDomain()) {
                $sid = $this->worlds['S']['store']->id;
                try {
                    $this->as('sibling-staff');
                    $res = $this->get('/'.ltrim($route->uri(), '/').'?store_id='.$sid.'&store='.$sid.'&storeId='.$sid.'&restaurant_id='.$sid);
                    if ($res->getStatusCode() < 300 && str_contains((string) $res->getContent(), $this->worlds['S']['tag'])) {
                        $queryLeaks[] = $route->getName().' ?store_id=sibling shows the sibling store';
                    }
                } catch (\Throwable) {
                    // a page that cannot render without more input is not a leak
                } finally {
                    $this->app['auth']->forgetGuards();
                    $this->flushSession();
                }
            }
        }
        $this->assertSame([], $queryLeaks, 'Staff of store A opened a sibling store through the query string.');

        if ($file = getenv('IDOR_SIBLING_FILE')) {
            file_put_contents($file, json_encode($rows, JSON_UNESCAPED_SLASHES));
        }
        $this->assertGreaterThan(100, count($rows), 'the sibling sweep must cover the staff route table');
        // Coupons are account-level (no store_id): the `coupons` permission manages the owner's account-wide
        // coupons, as it did before BL-036; the generic seeder only tags the row with the sibling's marker.
        $leaks = array_filter($rows, fn ($r) => $r['verdict'] === 'LEAK' && ! str_starts_with($r['route'], 'stores.coupons.'));
        $this->assertSame([], array_values(array_map(fn ($r) => $r['route'].' ['.$r['method'].' '.$r['uri'].'] '.$r['why'], $leaks)),
            'Staff of store A reached a sibling store of the same owner. Scope the lookup through dashboard_store_ids().');
    }

    /** @return list<array<string,mixed>> */
    private function sweep(): array
    {
        $this->worlds['A'] = $this->world('A');
        $this->worlds['B'] = $this->world('B');
        // Same-store victim for customer routes: another customer of A's store.
        $this->worlds['C'] = $this->worlds['A'];
        $this->worlds['C']['customer'] = $this->customer($this->worlds['A']['store']->id, 'C');
        $this->worlds['C']['tag'] = self::MARK.'C';

        $rows = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $actor = $this->actor($route);
            if ($actor === null || ! $route->parameterNames()) {
                continue;
            }
            $rows[] = $this->probe($route, $actor);
        }

        return $rows;
    }

    private function actor(RoutingRoute $r): ?string
    {
        $mw = array_values(array_filter($r->middleware(), 'is_string'));
        $flat = implode(' ', $mw);
        if (in_array('admin', $mw, true) || str_contains($flat, 'AdminMiddleware') || str_starts_with($r->uri(), 'admin/')
            || str_contains($flat, 'ApiTokenAuth')
            || str_contains($flat, 'api.token')) {
            return null;
        }
        if (! str_contains($r->getActionName(), '@')) {
            return null;
        }
        foreach ($mw as $m) {
            $m = str_replace('Illuminate\\Auth\\Middleware\\Authenticate', 'auth', $m);
            if ($m === 'auth:customer' || str_contains($m, 'CustomerTokenAuth') || $m === 'customer.token') {
                return str_contains($m, 'Token') || str_contains($m, 'token') ? 'customer-token' : 'customer';
            }
            if (str_contains($m, 'StaffTokenAuth') || $m === 'staff.token') {
                return 'staff-token';
            }
            if (str_contains($m, 'DriverTokenAuth') || $m === 'driver.token') {
                return 'driver-token';
            }
            if ($m === 'auth:staff') {
                return 'staff';
            }
            if ($m === 'auth' || $m === 'auth:web' || $m === 'auth:staff,web' || $m === 'staff.dashboard'
                || str_contains($m, 'EnsureStaffDashboardPermission')) {
                return 'owner';
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function probe(RoutingRoute $route, string $actor): array
    {
        $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
        $name = $route->getName() ?: ($method.' '.$route->uri());
        $row = ['route' => $name, 'method' => $method, 'uri' => $route->uri(), 'actor' => $actor,
            'action' => $route->getActionName(), 'status' => null, 'verdict' => 'safe', 'why' => '', 'params' => []];

        // Short-circuit: routes whose only path param is NOT a tenant record id. The probe never
        // dispatches these; they are counted separately as "not-a-record" in the report.
        if (array_key_exists($name, self::NOT_A_RECORD)) {
            foreach ($route->parameterNames() as $p) {
                $row['params'][$p] = 'not-a-record';
            }
            $row['verdict'] = 'not-a-record';
            $row['why'] = self::NOT_A_RECORD[$name];

            return $row;
        }

        $victim = in_array($actor, ['customer', 'customer-token'], true) ? 'C' : ($actor === 'sibling-staff' ? 'S' : 'B');
        [$class, $fn] = explode('@', $route->getActionName());
        $names = $route->parameterNames();
        $hasChild = (bool) array_diff($names, self::STORE_PARAMS, self::NON_ID);

        $args = [];
        $targets = []; // table => id (B rows we watch)
        foreach ($names as $p) {
            if (in_array($p, self::STORE_PARAMS, true) || ($p === 'id' && $this->idIsStore($class, $fn, $route))) {
                $w = ($hasChild && $p !== 'id') || $victim === 'C' ? 'A' : $victim;
                $args[$p] = $this->worlds[$w]['store']->id;
                $row['params'][$p] = 'stores:'.$w;
                if ($w === $victim && $victim !== 'C') {
                    $targets['stores'] = $this->worlds[$w]['store']->id;
                }

                continue;
            }
            if ($p === 'slug') {
                $args[$p] = $this->worlds[$victim === 'C' ? 'A' : $victim]['store']->slug;
                $row['params'][$p] = 'slug';

                continue;
            }
            // Hard-coded table/key overrides win over the tableFor() heuristic for routes the
            // generic reflection + body scan could not resolve (see PARAM_TABLE_HINTS).
            if (isset(self::PARAM_TABLE_HINTS[$name][$p])) {
                [$table, $key] = self::PARAM_TABLE_HINTS[$name][$p];
            } else {
                [$table, $key] = $this->tableFor($class, $fn, $p);
            }
            if ($table === null) {
                if (in_array($p, ['id', 'post', 'storeId', 'menu'], true)) {
                    // Legacy dashboards name the store `post` / `storeId` / `menu` / `id` and scope in a helper.
                    $args[$p] = $this->worlds[$victim === 'C' ? 'A' : $victim]['store']->id;
                    $row['params'][$p] = 'stores?';
                    if ($victim !== 'C') {
                        $targets['stores'] = $this->worlds[$victim]['store']->id;
                    }

                    continue;
                }
                $args[$p] = 1;
                $row['params'][$p] = 'unresolved';

                continue;
            }
            $id = $this->seedRow($table, $victim);
            if ($id === null) {
                $args[$p] = 999999;
                $row['params'][$p] = $table.':unseedable';

                continue;
            }
            $args[$p] = $key === 'id' ? $id : (DB::table($table)->where('id', $id)->value($key) ?? $id);
            $targets[$table] = $id;
            $row['params'][$p] = $table;
        }

        if (! $targets) {
            $row['verdict'] = 'unresolved';

            return $row;
        }

        $uri = '/'.ltrim($route->uri(), '/');
        foreach ($args as $k => $v) {
            $uri = preg_replace('/\{'.preg_quote($k, '/').'\??\}/', rawurlencode((string) $v), $uri);
        }
        $uri = preg_replace('/\{\w+\?\}/', '', $uri);
        if ($route->getDomain()) {
            $row['verdict'] = 'skipped-domain';

            return $row;
        }

        $before = $this->snapshot($targets);
        $counts = $this->storeCounts($victim);
        $level = DB::transactionLevel();
        DB::beginTransaction();
        try {
            $this->as($actor);
            $response = $this->call($method, $uri, ['_probe' => self::MARK], [], [], $this->transformHeadersToServerVars($this->defaultHeaders));
            $row['status'] = $response->getStatusCode();
            $body = (string) $response->getContent();
            $after = $this->snapshot($targets);
            $countsAfter = $this->storeCounts($victim);

            $why = [];
            foreach ($before as $t => $snap) {
                if ($t === 'stores' && $row['status'] >= 300) {
                    continue;
                }
                if (($after[$t] ?? null) !== $snap) {
                    $why[] = $after[$t] === null ? "deleted {$t}#".$targets[$t] : "mutated {$t}#".$targets[$t].' '.$this->diff($snap, $after[$t]);
                }
            }
            foreach ($countsAfter as $t => $n) {
                if ($n > ($counts[$t] ?? 0)) {
                    $why[] = "wrote new {$t} row into victim";
                }
            }
            if ($row['status'] < 300 && str_contains($body, $this->worlds[$victim]['tag'])) {
                $why[] = 'response shows victim marker';
            }
            if ($why) {
                $row['verdict'] = 'LEAK';
                $row['why'] = implode('; ', $why);
            } elseif ($row['status'] >= 500) {
                $row['verdict'] = 'error';
                $row['why'] = Str::limit(strip_tags((string) ($response->exception?->getMessage() ?? '')), 160);
            }
        } catch (\Throwable $e) {
            $row['verdict'] = 'error';
            $row['why'] = Str::limit($e->getMessage(), 160);
        } finally {
            while (DB::transactionLevel() > $level) {
                DB::rollBack();
            }
            $this->app['auth']->forgetGuards();
            $this->flushSession();
            $this->defaultHeaders = [];
        }

        return $row;
    }

    private function as(string $actor): static
    {
        $a = $this->worlds['A'];
        $this->withSession(['dashboard_store_id' => $a['store']->id, 'dashboard_branch_id' => 0]);

        return match ($actor) {
            'owner' => $this->actingAs($a['owner'], 'web'),
            'staff' => $this->actingAs($a['owner'], 'web')->actingAs($a['staff'], 'staff')
                ->withSession(['staff_session_id' => $a['staff']->id, 'dashboard_store_id' => $a['store']->id]),
            // BL-036: the staff dashboard session exactly as StaffLoginController leaves it (web = the owner).
            'sibling-staff' => $this->actingAs($a['staff']->fresh(), 'staff')->actingAs($a['owner'], 'web')
                ->withSession(['staff_session_id' => $a['staff']->id, 'dashboard_store_id' => $a['store']->id]),
            'customer' => $this->actingAs($a['customer'], 'customer'),
            'staff-token' => $this->withToken($a['staffToken']),
            'customer-token' => $this->withToken($a['customerToken']),
            default => $this->withToken($a['driverToken']),
        };
    }

    /** @return array<string,mixed> */
    private function world(string $w, ?User $owner = null): array
    {
        $tag = self::MARK.$w;
        if ($owner === null) {
            $plan = TestPlans::create(['name' => 'P '.Str::random(6), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
                'lifetime_price' => 1, 'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1]);
            $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
                'name' => "Owner {$tag}", 'email' => Str::lower($w.Str::random(8)).'@idor.test']);
        }
        $store = Store::create(['user_id' => $owner->id, 'slug' => 'idor-'.Str::lower($w.Str::random(6)), 'color' => '#111',
            'title' => "Store {$tag}", 'sub_title' => $tag, 'description' => $tag, 'main_image' => 'd.png', 'cover_image' => 'd.png',
            'address' => $tag, 'phone' => '1']);
        $branch = Branch::create(['store_id' => $store->id, 'name' => "Branch {$tag}", 'position' => 0]);
        $staff = StaffMember::create(['owner_id' => $owner->id, 'store_id' => $store->id, 'name' => "Staff {$tag}",
            'email' => Str::lower($w.Str::random(6)).'@idor.test', 'password' => bcrypt('x'), 'role' => 'manager', 'active' => 1]);
        $customer = $this->customer($store->id, $w);

        $sibling = isset($this->worlds['A']) && $this->worlds['A']['owner']->id === $owner->id;
        $resellerId = ! $sibling
            ? $this->insertRow('resellers', ['owner_user_id' => $owner->id, 'active' => 1, 'billing_status' => 'active',
                'slug' => 'idor-'.Str::lower($w.Str::random(6))], $tag)
            : $this->worlds['A']['resellerId'];
        $raw = ['staff' => Str::random(40), 'customer' => Str::random(40), 'driver' => Str::random(40)];
        DB::table('staff_tokens')->insert(['staff_id' => $staff->id, 'token' => hash('sha256', $raw['staff']), 'name' => 'idor',
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('customer_tokens')->insert(['customer_id' => $customer->getKey(), 'token' => hash('sha256', $raw['customer']), 'name' => 'idor',
            'created_at' => now(), 'updated_at' => now()]);
        $driverId = $this->insertRow('drivers', ['store_id' => $store->id, 'token' => hash('sha256', $raw['driver']), 'active' => 1, 'status' => 'active'], $tag);

        return ['tag' => $tag, 'owner' => $owner, 'store' => $store, 'branch' => $branch, 'staff' => $staff, 'customer' => $customer,
            'driverId' => $driverId, 'resellerId' => $resellerId, 'staffToken' => $raw['staff'], 'customerToken' => $raw['customer'], 'driverToken' => $raw['driver']];
    }

    private function customer(int $storeId, string $w): Model
    {
        $cls = config('auth.providers.customers.model');

        return $cls::forceCreate(['store_id' => $storeId, 'name' => 'Cust '.self::MARK.$w,
            'email' => Str::lower($w.Str::random(8)).'@idor.test', 'password' => bcrypt('x')]);
    }

    private function idIsStore(string $class, string $fn, RoutingRoute $route): bool
    {
        [$table] = $this->tableFor($class, $fn, 'id');

        return $table === 'stores';
    }

    /** @return array{0:?string,1:string} [table, route key column] */
    private function tableFor(string $class, string $fn, string $param): array
    {
        try {
            $rm = new ReflectionMethod($class, $fn);
        } catch (\Throwable) {
            return [null, 'id'];
        }
        foreach ($rm->getParameters() as $p) {
            $t = $p->getType();
            if ($p->getName() === $param && $t instanceof ReflectionNamedType && ! $t->isBuiltin()
                && is_subclass_of($t->getName(), Model::class)) {
                $m = new ($t->getName());

                return [$m->getTable(), $m->getRouteKeyName()];
            }
        }
        if (in_array($param, self::NON_ID, true)) {
            return [null, 'id'];
        }

        // `Model::…($param` / `Model::where(…, $param` in the method body, resolved through the file's imports.
        $file = (string) $rm->getFileName();
        $src = (string) file_get_contents($file);
        $body = implode('', array_slice(file($file) ?: [], $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));
        $var = preg_quote('$'.$param, '/');
        if (preg_match_all('/\b([A-Z]\w+)::(?:query\(\)|withoutGlobalScopes\(\)|withTrashed\(\)|on\w*\([^)]*\))?[^;]*?'.$var.'\b/', $body, $mm)) {
            foreach ($mm[1] as $short) {
                $fq = $this->resolveClass($short, $src, $rm->getDeclaringClass()->getNamespaceName());
                if ($fq && is_subclass_of($fq, Model::class)) {
                    $m = new $fq;
                    $col = preg_match('/[\'"](uuid|token|code|public_id)[\'"]\s*,\s*'.$var.'\b/', $body, $c) ? $c[1] : 'id';

                    return [$m->getTable(), $col];
                }
            }
        }
        // One hop: `$this->helper(…$param…)` whose body names `Model::`.
        if (preg_match_all('/\$this->(\w+)\([^;]*?'.$var.'\b/', $body, $hh)) {
            foreach (array_unique($hh[1]) as $helper) {
                try {
                    $hm = new ReflectionMethod($class, $helper);
                } catch (\Throwable) {
                    continue;
                }
                $hb = implode('', array_slice(file((string) $hm->getFileName()) ?: [], $hm->getStartLine() - 1, $hm->getEndLine() - $hm->getStartLine() + 1));
                if (preg_match_all('/\b([A-Z]\w+)::(?:query|where|find|withoutGlobalScopes|withTrashed)/', $hb, $m2)) {
                    foreach ($m2[1] as $short) {
                        $fq = $this->resolveClass($short, (string) file_get_contents((string) $hm->getFileName()), $hm->getDeclaringClass()->getNamespaceName());
                        if ($fq && is_subclass_of($fq, Model::class)) {
                            return [(new $fq)->getTable(), 'id'];
                        }
                    }
                }
            }
        }
        if ($param === 'id') {
            return [null, 'id'];
        }

        $aliases = ['item' => 'menus', 'item_id' => 'menus', 'itemId' => 'menus', 'menu' => 'menus', 'product' => 'menus',
            'order' => 'orders', 'orderId' => 'orders', 'staff' => 'restaurant_staff', 'variantId' => 'menu_variants'];
        if (isset($aliases[$param]) && Schema::hasTable($aliases[$param])) {
            return [$aliases[$param], 'id'];
        }
        $base = Str::snake(preg_replace('/(_id|Id)$/', '', $param));
        foreach ([Str::plural($base), $base, 'restaurant_'.Str::plural($base), 'store_'.Str::plural($base)] as $t) {
            if (Schema::hasTable($t)) {
                return [$t, $param === 'uuid' ? 'uuid' : 'id'];
            }
        }

        return [null, 'id'];
    }

    private function resolveClass(string $short, string $src, string $ns): ?string
    {
        if (preg_match('/^use\s+([\w\\\\]+\\\\'.$short.')\s*;/m', $src, $m)) {
            return class_exists($m[1]) ? $m[1] : null;
        }
        if (preg_match('/^use\s+([\w\\\\]+)\s+as\s+'.$short.'\s*;/m', $src, $m)) {
            return class_exists($m[1]) ? $m[1] : null;
        }

        return class_exists($ns.'\\'.$short) ? $ns.'\\'.$short : null;
    }

    /** One row of $table owned by world $w (cached). */
    private function seedRow(string $table, string $w, int $depth = 0): ?int
    {
        $key = $w.':'.$table;
        if (array_key_exists($key, $this->seeded)) {
            return $this->seeded[$key];
        }
        if ($table === 'stores') {
            return $this->worlds[$w]['store']->id;
        }
        $world = $this->worlds[$w];
        $fixed = ['users' => $world['owner']->id, 'resellers' => $world['resellerId'], 'branches' => $world['branch']->id,
            'restaurant_staff' => $world['staff']->id, (new ($world['customer']::class))->getTable() => $world['customer']->id];
        if (isset($fixed[$table])) {
            return $this->seeded[$key] = $fixed[$table];
        }
        if ($table === 'drivers') {
            return $this->seeded[$key] = $w === 'C' ? $this->worlds['A']['driverId'] : $world['driverId'];
        }
        $this->seeded[$key] = null; // cycle guard
        try {
            $id = $this->insertRow($table, [], $world['tag'], $w, $depth);
        } catch (\Throwable) {
            $id = null;
        }

        return $this->seeded[$key] = $id;
    }

    /**
     * Insert a row into $table filling every column generically; ownership columns point at world $w.
     *
     * @param  array<string,mixed>  $given
     */
    private function insertRow(string $table, array $given, string $tag, ?string $w = null, int $depth = 0): ?int
    {
        if (! Schema::hasTable($table)) {
            return null;
        }
        $world = $w ? $this->worlds[$w] : null;
        $checks = $this->checkValues($table);
        $cols = Schema::getColumns($table);
        $row = array_intersect_key($given, array_flip(array_column($cols, 'name')));
        foreach ($cols as $c) {
            $n = $c['name'];
            if (array_key_exists($n, $row) || $c['auto_increment'] || $n === 'id' && str_contains(strtolower($c['type_name']), 'int')) {
                continue;
            }
            $owner = $world ? match ($n) {
                'store_id', 'restaurant_id' => $world['store']->id,
                'account_id' => $world['store']->fresh()?->account_id ?? $world['owner']->id,
                'user_id', 'owner_id', 'owner_user_id', 'created_by', 'author_id' => $world['owner']->id,
                'reseller_id' => $world['resellerId'] ?? null,
                'customer_id' => $world['customer']->id,
                'staff_id', 'restaurant_staff_id' => $world['staff']->id,
                'branch_id' => $world['branch']->id,
                'driver_id' => $world['driverId'],
                default => null,
            } : null;
            if ($owner !== null) {
                $row[$n] = $owner;

                continue;
            }
            if ($c['nullable'] && ! in_array($n, ['name', 'title', 'note', 'notes', 'description', 'customer_name', 'label', 'body', 'subject'], true)) {
                continue;
            }
            if ($c['default'] !== null && ! in_array($n, ['name', 'title'], true)) {
                continue;
            }
            $type = strtolower($c['type_name']);
            if (isset($checks[$n])) {
                $row[$n] = $checks[$n];
            } elseif (str_ends_with($n, '_id') && $w && $depth < 2) {
                $base = substr($n, 0, -3);
                $parent = collect([Str::plural($base), $base, $base === 'menu' || $base === 'item' ? 'menus' : null])
                    ->filter()->first(fn ($t) => Schema::hasTable($t));
                $row[$n] = $parent ? ($this->seedRow($parent, $w, $depth + 1) ?? 999999) : 999999;
            } elseif (str_contains($type, 'int') || str_contains($type, 'numeric') || str_contains($type, 'real') || str_contains($type, 'float')
                || str_contains($type, 'double') || str_contains($type, 'decimal')) {
                $row[$n] = 1;
            } elseif (str_contains($type, 'bool') || $type === 'tinyint') {
                $row[$n] = 1;
            } elseif (str_contains($type, 'date') || str_contains($type, 'time')) {
                $row[$n] = now()->addDay()->toDateTimeString();
            } elseif (str_contains($type, 'json')) {
                $row[$n] = '{}';
            } elseif ($n === 'uuid' || str_ends_with($n, '_uuid')) {
                $row[$n] = (string) Str::uuid();
            } elseif (str_contains($n, 'email')) {
                $row[$n] = Str::lower(Str::random(8)).'@idor.test';
            } else {
                $row[$n] = $tag.' '.Str::random(6);
            }
        }
        // Victim ids live far from the attacker's so a store-id / record-id collision can never pass as "denied".
        $idCol = collect($cols)->firstWhere('name', 'id');
        if ($w !== null && $w !== 'A' && $idCol && str_contains(strtolower($idCol['type_name']), 'int') && ! isset($row['id'])) {
            $row['id'] = 800000 + (++$this->victimSeq);
        }
        foreach (['created_at', 'updated_at'] as $ts) {
            if (Schema::hasColumn($table, $ts) && ! isset($row[$ts])) {
                $row[$ts] = now();
            }
        }

        return (int) DB::table($table)->insertGetId($row);
    }

    /** @return array<string,string> column => first allowed value from a sqlite CHECK (col IN (...)) */
    private function checkValues(string $table): array
    {
        $sql = (string) DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql');
        $out = [];
        if (preg_match_all('/check\s*\(\s*"?(\w+)"?\s+in\s*\(\s*\'([^\']*)\'/i', $sql, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $out[$x[1]] = $x[2];
            }
        }

        return $out;
    }

    /**
     * @param  array<string,int>  $targets
     * @return array<string,?array<string,mixed>>
     */
    private function snapshot(array $targets): array
    {
        $out = [];
        foreach ($targets as $t => $id) {
            $r = DB::table($t)->where('id', $id)->first();
            if ($r !== null) {
                $r = (array) $r;
                unset($r['updated_at'], $r['last_used_at'], $r['last_seen_at'], $r['views'], $r['view_count']);
            }
            $out[$t] = $r;
        }

        return $out;
    }

    /** @return array<string,int> rows owned by the victim world in every seeded table */
    private function storeCounts(string $victim): array
    {
        $out = [];
        $world = $this->worlds[$victim];
        foreach ($this->seeded as $k => $id) {
            [$ww, $t] = explode(':', $k, 2);
            if ($ww !== $victim || $id === null || in_array($t, ['users', 'branches', 'restaurant_staff', 'customers', 'drivers'], true)) {
                continue;
            }
            $col = $victim === 'C' ? 'customer_id' : 'store_id';
            if (Schema::hasColumn($t, $col)) {
                $out[$t] = DB::table($t)->where($col, $victim === 'C' ? $world['customer']->id : $world['store']->id)->count();
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    private function diff(array $a, array $b): string
    {
        $d = [];
        foreach ($a as $k => $v) {
            if (($b[$k] ?? null) != $v) {
                $d[] = $k;
            }
        }

        return '('.implode(',', $d).')';
    }
}

/** Deliberately unscoped fixture controller for the sweep's anti-vacuity test. */
class IdorProbeController
{
    public function destroy(int $order): JsonResponse
    {
        DB::table('orders')->where('id', $order)->delete(); // no tenant scope, no owner check

        return response()->json(['ok' => true]);
    }
}

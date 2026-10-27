<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Illuminate\Auth\GenericUser;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\FileSessionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\StartSession;
use Juwri\Core\Session\MergingSessionManager;
use Juwri\Core\Session\MergingStore;
use PHPUnit\Framework\Attributes\DataProvider;
use SessionHandlerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * P6-146: a slow GET that started on the guest session id finishes after the
 * login response (which regenerated the id) and used to send Set-Cookie with
 * the OLD id, so the browser fell back to the guest session. Now the old id is
 * tombstoned at regenerate, the slow request sees it at save time, skips the
 * write and sends no session cookie. The tombstone never points at the new id,
 * so an old id can never reach the new session (fixation protection intact).
 */
class SessionStaleCookieAfterLoginTest extends TestCase
{
    private string $dir;

    private Repository $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/juwri-stale-cookie-'.bin2hex(random_bytes(4));
        @mkdir($this->dir, 0777, true);
        $this->cache = new Repository(new ArrayStore);
        if (! Schema::hasTable('stale_sessions')) {
            Schema::create('stale_sessions', function ($t) {
                $t->string('id')->primary();
                $t->foreignId('user_id')->nullable();
                $t->string('ip_address', 45)->nullable();
                $t->text('user_agent')->nullable();
                $t->longText('payload');
                $t->integer('last_activity');
            });
        }
        // This class does not refresh the database: on MySQL/PG the table (and the fixed planted id's old tombstone
        // row) survives from an earlier run in the same worker DB and turned the planted-id test into a "long dead"
        // tombstone case (int/p5n). Start every test on an empty table.
        DB::table('stale_sessions')->delete();
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);
        Schema::dropIfExists('stale_sessions');
        parent::tearDown();
    }

    /** file, database, and the cache handler redis uses (RedisSessionHandler extends CacheBasedSessionHandler). */
    public static function storeDrivers(): array
    {
        return ['file' => ['file'], 'database' => ['database'], 'cache/redis' => ['cache']];
    }

    public static function httpDrivers(): array
    {
        return ['file' => ['file'], 'database' => ['database']];
    }

    private function handler(string $driver): SessionHandlerInterface
    {
        return match ($driver) {
            'file' => new FileSessionHandler(new Filesystem, $this->dir, 120),
            'database' => new DatabaseSessionHandler(DB::connection(), 'stale_sessions', 120, $this->app),
            default => new CacheBasedSessionHandler($this->cache, 120),
        };
    }

    private function start(string $driver, string $id): MergingStore
    {
        $s = new MergingStore('juwri_session', $this->handler($driver), $id);
        $s->start();

        return $s;
    }

    private function guest(string $driver): string
    {
        $s = $this->start($driver, Str::random(40));
        $s->put('_token', 'guest-token');
        $s->put('cart', ['sku' => 1]);
        $s->save();

        return $s->getId();
    }

    #[DataProvider('storeDrivers')]
    public function test_a_slow_request_on_the_pre_login_id_is_superseded_and_writes_nothing(string $driver): void
    {
        $old = $this->guest($driver);
        $slow = $this->start($driver, $old);                 // slow GET starts
        $login = $this->start($driver, $old);                // POST /login
        $login->put('login_web_x', 5);
        $login->regenerate();                                // keeps old data by default — tombstoned instead
        $login->save();
        $new = $login->getId();
        $this->assertNotSame($old, $new);
        $this->assertFalse($login->wasSuperseded());

        $slow->setPreviousUrl('https://x.test/page');
        $slow->save();
        $this->assertTrue($slow->wasSuperseded(), 'the slow request must know its id was replaced');

        $this->assertSame(5, $this->start($driver, $new)->get('login_web_x'));
        $this->assertSame(['sku' => 1], $this->start($driver, $new)->get('cart'));
        $back = $this->start($driver, $old);
        $this->assertNull($back->get('login_web_x'), 'the old id never reaches the new session');
        $this->assertNull($back->get('cart'), 'the old id keeps no data either');
    }

    #[DataProvider('storeDrivers')]
    public function test_destroying_regenerate_and_logout_also_supersede(string $driver): void
    {
        foreach (['migrate', 'invalidate'] as $how) {
            $old = $this->guest($driver);
            $slow = $this->start($driver, $old);
            $other = $this->start($driver, $old);
            $how === 'migrate' ? $other->migrate(true) : $other->invalidate();
            $other->save();
            $slow->save();
            $this->assertTrue($slow->wasSuperseded(), $how);
        }
    }

    #[DataProvider('storeDrivers')]
    public function test_a_normal_save_is_not_superseded(string $driver): void
    {
        $old = $this->guest($driver);
        $a = $this->start($driver, $old);
        $b = $this->start($driver, $old);
        $b->put('locale', 'ar');
        $b->save();
        $a->save();
        $this->assertFalse($a->wasSuperseded());
        $this->assertFalse($b->wasSuperseded());
        $this->assertSame('ar', $this->start($driver, $old)->get('locale'));
    }

    #[DataProvider('storeDrivers')]
    public function test_a_long_dead_tombstone_gets_a_fresh_id_never_the_old_or_new_one(string $driver): void
    {
        $old = $this->guest($driver);
        $login = $this->start($driver, $old);
        $login->put('login_web_x', 5);
        $login->regenerate();
        $login->save();
        $this->handler($driver)->write($old, serialize([MergingStore::TOMBSTONE_KEY => now()->getTimestamp() - 3600]));

        $late = $this->start($driver, $old);
        $this->assertNotSame($old, $late->getId());
        $this->assertNotSame($login->getId(), $late->getId());
        $this->assertNull($late->get('login_web_x'));
        $this->assertFalse($late->wasSuperseded());
    }

    public function test_the_database_tombstone_row_names_no_user(): void
    {
        $user = new GenericUser(['id' => 77]);
        $old = $this->guest('database');
        $this->actingAs($user);
        $login = $this->start('database', $old);
        $login->regenerate();
        $login->save();

        $row = DB::table('stale_sessions')->where('id', $old)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->user_id, 'a tombstone is not a device of the user');
        $this->assertSame(77, (int) DB::table('stale_sessions')->where('id', $login->getId())->value('user_id'));
    }

    #[DataProvider('storeDrivers')]
    public function test_regenerating_on_a_fresh_tombstone_keeps_it_and_stays_superseded(string $driver): void
    {
        $old = $this->guest($driver);
        $login = $this->start($driver, $old);
        $login->regenerate();
        $login->save();

        $stale = $this->start($driver, $old);
        $this->assertTrue($stale->wasSuperseded());
        $stale->invalidate();                                 // e.g. a CSRF-exempt logout or a remember-me re-login
        $stale->save();
        $this->assertTrue($stale->wasSuperseded(), 'no cookie may replace the login cookie');
        $this->assertTrue($this->start($driver, $old)->wasSuperseded(), 'the tombstone survives');
    }

    // ---- through the real middleware ----

    private function useDriver(string $driver): void
    {
        config([
            'session.driver' => $driver,
            'session.files' => $this->dir,
            'session.table' => 'stale_sessions',
            'session.cookie' => 'juwri_session',
            'session.encrypt' => false,
            'session.lottery' => [0, 100],
            'session.block' => false, // no route here; locking is orthogonal (P6-136)
        ]);
    }

    /** One HTTP request through a fresh StartSession + manager (own store instance, shared storage). */
    private function request(?string $cookie, \Closure $app): Response
    {
        $request = Request::create('/page', 'GET', [], $cookie ? ['juwri_session' => $cookie] : []);
        $mw = new StartSession(new MergingSessionManager($this->app));

        return $mw->handle($request, function (Request $r) use ($app) {
            $app($r);

            return new Response('ok');
        });
    }

    private function sessionCookie(Response $response): ?Cookie
    {
        foreach ($response->headers->getCookies() as $c) {
            if ($c->getName() === 'juwri_session') {
                return $c;
            }
        }

        return null;
    }

    #[DataProvider('httpDrivers')]
    public function test_the_slow_response_carries_no_stale_session_cookie(string $driver): void
    {
        $this->useDriver($driver);
        $first = $this->request(null, fn (Request $r) => $r->session()->put('cart', ['sku' => 1]));
        $old = $this->sessionCookie($first)?->getValue();
        $this->assertNotNull($old, 'a normal request sets the cookie');

        $loginResponse = null;
        $slowResponse = $this->request($old, function (Request $r) use ($old, &$loginResponse) {
            // while the slow GET runs, the login POST completes
            $loginResponse = $this->request($old, function (Request $l) {
                $l->session()->put('login_web_x', 5);
                $l->session()->regenerate();
            });
            $r->session()->put('_previous', ['url' => 'https://x.test/page']);
        });

        $new = $this->sessionCookie($loginResponse)?->getValue();
        $this->assertNotNull($new);
        $this->assertNotSame($old, $new);
        $this->assertNull($this->sessionCookie($slowResponse), 'the slow response must not send the old id back');

        // the browser keeps $new: still logged in
        $after = $this->request($new, function (Request $r) {
            $this->assertSame(5, $r->session()->get('login_web_x'));
        });
        $this->assertSame($new, $this->sessionCookie($after)?->getValue(), 'normal requests still refresh the cookie');

        // a request still carrying the old id (sent before the login response landed) gets nothing and sends nothing
        $stale = $this->request($old, function (Request $r) {
            $this->assertNull($r->session()->get('login_web_x'));
        });
        $this->assertNull($this->sessionCookie($stale));
    }

    #[DataProvider('httpDrivers')]
    public function test_a_planted_id_is_useless_after_login_fixation_still_blocked(string $driver): void
    {
        $this->useDriver($driver);
        $planted = str_repeat('p', 40);                       // attacker-chosen id planted in the victim browser
        $this->request($planted, fn (Request $r) => $r->session()->put('x', 1));
        $login = $this->request($planted, function (Request $r) {
            $r->session()->put('login_web_x', 5);
            $r->session()->regenerate();
        });
        $new = $this->sessionCookie($login)?->getValue();
        $this->assertNotSame($planted, $new);

        // the attacker replays the planted id, now or much later: never the victim's session, never the new id
        $now = $this->request($planted, fn (Request $r) => $this->assertNull($r->session()->get('login_web_x')));
        $this->assertNull($this->sessionCookie($now));
        $this->travel(5)->minutes();
        $later = $this->request($planted, fn (Request $r) => $this->assertNull($r->session()->get('login_web_x')));
        $fresh = $this->sessionCookie($later)?->getValue();
        $this->assertNotNull($fresh);
        $this->assertNotSame($planted, $fresh);
        $this->assertNotSame($new, $fresh);
    }
}

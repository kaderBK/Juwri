<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Illuminate\Cookie\CookieJar;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Session\CookieSessionHandler;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\FileSessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Session\MergingEncryptedStore;
use Juwri\Core\Session\MergingSessionManager;
use Juwri\Core\Session\MergingStore;
use PHPUnit\Framework\Attributes\DataProvider;
use SessionHandlerInterface;
use Tests\TestCase;

/**
 * P6-136: two requests sharing one session cookie, interleaved exactly as a
 * browser does it — a slow GET (page load / dashboard poll) loads the session,
 * a faster request writes it (locale switch, flash, cart line, login-step
 * marker) and saves, then the slow GET saves. With Laravel's stock Store the
 * slow save writes back its stale copy and the fast request's write is lost
 * (asserted below as the reproduction); MergingStore keeps it. Run against the
 * real file and database handlers (file = .env.example default, database =
 * the shared-hosting profile's choice under J40).
 */
class SessionConcurrentWriteRaceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/juwri-session-race-'.bin2hex(random_bytes(4));
        @mkdir($this->dir, 0777, true);
        if (! Schema::hasTable('race_sessions')) {
            Schema::create('race_sessions', function ($t) {
                $t->string('id')->primary();
                $t->foreignId('user_id')->nullable();
                $t->string('ip_address', 45)->nullable();
                $t->text('user_agent')->nullable();
                $t->longText('payload');
                $t->integer('last_activity');
            });
        }
        // No RefreshDatabase here: on MySQL/PostgreSQL the table and its rows outlive a test (the ids below are fixed),
        // so a row left by an earlier data set (destroyed/tombstoned session) changed the next one's outcome.
        DB::table('race_sessions')->delete();
    }

    protected function tearDown(): void
    {
        DB::table('race_sessions')->delete();
        (new Filesystem)->deleteDirectory($this->dir);
        parent::tearDown();
    }

    public static function drivers(): array
    {
        return ['file' => ['file'], 'database' => ['database']];
    }

    private function handler(string $driver): SessionHandlerInterface
    {
        return $driver === 'file'
            ? new FileSessionHandler(new Filesystem, $this->dir, 120)
            : new DatabaseSessionHandler(DB::connection(), 'race_sessions', 120, $this->app);
    }

    /** One "request": a fresh store + handler instance on the shared storage, started on $id. */
    private function startRequest(string $driver, string $id, bool $merging): Store
    {
        $store = $merging
            ? new MergingStore('juwri_session', $this->handler($driver), $id)
            : new Store('juwri_session', $this->handler($driver), $id);
        $store->start();

        return $store;
    }

    private function seedSession(string $driver, bool $merging): string
    {
        $s = $this->startRequest($driver, str_repeat('a', 40), $merging);
        $s->put('locale', 'en');
        $s->put('login_web_x', 7);
        $s->save();

        return $s->getId();
    }

    #[DataProvider('drivers')]
    public function test_stock_store_loses_a_concurrent_write(string $driver): void
    {
        $id = $this->seedSession($driver, false);

        $slow = $this->startRequest($driver, $id, false);          // GET /dashboard (or a poll) starts
        $fast = $this->startRequest($driver, $id, false);          // GET /lang/ar
        $fast->put('locale', 'ar');
        $fast->flash('status', 'Saved');
        $fast->save();
        $slow->setPreviousUrl('https://x.test/dashboard');    // what every page GET changes
        $slow->save();                                        // stale full write

        $after = $this->startRequest($driver, $id, false);
        $this->assertSame('en', $after->get('locale'), 'reproduction: stock Store loses the locale switch');
        $this->assertNull($after->get('status'), 'reproduction: stock Store loses the flash');
    }

    #[DataProvider('drivers')]
    public function test_merging_store_keeps_concurrent_locale_flash_and_cart(string $driver): void
    {
        $id = $this->seedSession($driver, true);

        $slow = $this->startRequest($driver, $id, true);
        $fast = $this->startRequest($driver, $id, true);
        $fast->put('locale', 'ar');
        $fast->flash('status', 'Saved');
        $fast->put('cart', ['item-1' => 2]);
        $fast->save();
        $slow->setPreviousUrl('https://x.test/dashboard');
        $slow->put('dashboard_tab', 'orders');                // the slow request's own write also lands
        $slow->save();

        $next = $this->startRequest($driver, $id, true);           // the redirect target
        $this->assertSame('ar', $next->get('locale'));
        $this->assertSame('Saved', $next->get('status'));
        $this->assertSame(['item-1' => 2], $next->get('cart'));
        $this->assertSame('orders', $next->get('dashboard_tab'));
        $this->assertSame('https://x.test/dashboard', $next->previousUrl());
        $this->assertSame(7, $next->get('login_web_x'));
        $next->save();

        // The flash still ages out normally: shown once, gone on the request after.
        $later = $this->startRequest($driver, $id, true);
        $this->assertNull($later->get('status'));
    }

    #[DataProvider('drivers')]
    public function test_a_flash_written_while_the_slow_request_ages_its_own_flash_is_not_stranded(string $driver): void
    {
        $id = $this->seedSession($driver, true);
        $prev = $this->startRequest($driver, $id, true);
        $prev->flash('old_notice', 'one');
        $prev->save();

        $slow = $this->startRequest($driver, $id, true);           // shows old_notice, ages it
        $fast = $this->startRequest($driver, $id, true);
        $fast->flash('status', 'Saved');
        $fast->save();
        $slow->save();

        $next = $this->startRequest($driver, $id, true);
        $this->assertSame('Saved', $next->get('status'));
        $next->save();
        $later = $this->startRequest($driver, $id, true);
        $this->assertNull($later->get('status'), 'the other request\'s flash must still age out');
        $this->assertNull($later->get('old_notice'));
    }

    #[DataProvider('drivers')]
    public function test_the_same_flash_key_flashed_again_while_the_slow_request_ages_it_survives(string $driver): void
    {
        $id = $this->seedSession($driver, true);
        $first = $this->startRequest($driver, $id, true);
        $first->flash('status', 'Saved en');
        $first->save();

        $slow = $this->startRequest($driver, $id, true);       // ages "Saved en" away
        $fast = $this->startRequest($driver, $id, true);
        $fast->flash('status', 'Saved ar');                    // same key, new value
        $fast->save();
        $slow->save();

        $next = $this->startRequest($driver, $id, true);
        $this->assertSame('Saved ar', $next->get('status'));
        $next->save();
        $this->assertNull($this->startRequest($driver, $id, true)->get('status'));
    }

    #[DataProvider('drivers')]
    public function test_removals_by_the_slow_request_apply_and_untouched_keys_survive(string $driver): void
    {
        $id = $this->seedSession($driver, true);
        $slow = $this->startRequest($driver, $id, true);
        $fast = $this->startRequest($driver, $id, true);
        $fast->put('locale', 'fr');
        $fast->save();
        $slow->forget('login_web_x');
        $slow->save();

        $after = $this->startRequest($driver, $id, true);
        $this->assertFalse($after->has('login_web_x'));
        $this->assertSame('fr', $after->get('locale'));
    }

    #[DataProvider('drivers')]
    public function test_a_slow_request_never_resurrects_a_session_destroyed_meanwhile(string $driver): void
    {
        $id = $this->seedSession($driver, true);
        $slow = $this->startRequest($driver, $id, true);
        $logout = $this->startRequest($driver, $id, true);
        $logout->invalidate();                                 // logout: new id, old one destroyed
        $logout->save();
        $slow->setPreviousUrl('https://x.test/dashboard');
        $slow->save();

        $old = $this->startRequest($driver, $id, true);
        $this->assertNull($old->get('login_web_x'), 'the signed-out session id must stay signed out');
    }

    #[DataProvider('drivers')]
    public function test_regenerate_and_flush_still_write_the_whole_session(string $driver): void
    {
        $id = $this->seedSession($driver, true);
        $login = $this->startRequest($driver, $id, true);
        $login->migrate(true);
        $login->put('login_web_x', 9);
        $login->save();
        $this->assertSame(9, $this->startRequest($driver, $login->getId(), true)->get('login_web_x'));

        $flush = $this->startRequest($driver, $login->getId(), true);
        $flush->flush();
        $flush->put('only', 1);
        $flush->save();
        $after = $this->startRequest($driver, $login->getId(), true);
        $this->assertNull($after->get('locale'));
        $this->assertSame(1, $after->get('only'));
    }

    #[DataProvider('drivers')]
    public function test_a_concurrent_logout_key_removal_and_token_rotation_are_not_undone(string $driver): void
    {
        $id = $this->seedSession($driver, true);
        $slow = $this->startRequest($driver, $id, true);
        $oldToken = $slow->token();
        $fast = $this->startRequest($driver, $id, true);
        $fast->forget('login_web_x');                          // Auth::logout() without a new id
        $fast->regenerateToken();
        $fast->save();
        $slow->setPreviousUrl('https://x.test/dashboard');
        $slow->save();

        $after = $this->startRequest($driver, $id, true);
        $this->assertFalse($after->has('login_web_x'), 'a slow request must not bring the login key back');
        $this->assertNotSame($oldToken, $after->token(), 'a slow request must not restore the old CSRF token');
        $this->assertSame($fast->token(), $after->token());
    }

    #[DataProvider('drivers')]
    public function test_the_encrypted_store_decrypts_the_re_read_and_encrypts_the_write(string $driver): void
    {
        $handler = fn () => $this->handler($driver);
        $start = function (string $id) use ($handler) {
            $s = new MergingEncryptedStore('juwri_session', $handler(), $this->app['encrypter'], $id);
            $s->start();

            return $s;
        };
        $seed = $start(str_repeat('b', 40));
        $seed->put('locale', 'en');
        $seed->save();
        $id = $seed->getId();

        $slow = $start($id);
        $fast = $start($id);
        $fast->put('locale', 'ar');
        $fast->save();
        $slow->put('secret_marker', 'plain-text-marker');
        $slow->save();

        $raw = (string) $handler()->read($id);
        $this->assertStringNotContainsString('plain-text-marker', $raw, 'the merged write must be encrypted');
        $this->assertStringNotContainsString('"ar"', $raw);
        $after = $start($id);
        $this->assertSame('ar', $after->get('locale'));
        $this->assertSame('plain-text-marker', $after->get('secret_marker'));
    }

    public function test_the_cookie_driver_keeps_the_stock_full_write(): void
    {
        $jar = new CookieJar;
        $handler = new CookieSessionHandler($jar, 1, false);
        $id = str_repeat('c', 40);
        $payload = serialize(['_token' => str_repeat('t', 40), 'login_web_x' => 7, '_flash' => ['old' => [], 'new' => []]]);
        $handler->setRequest(Request::create('/', 'GET', [], [$id => json_encode(['data' => $payload, 'expires' => now()->addSeconds(30)->getTimestamp()])]));

        $store = new MergingStore('juwri_session', $handler, $id);
        $store->start();
        $this->assertSame(7, $store->get('login_web_x'));
        $store->put('locale', 'ar');
        $this->travel(2)->minutes();                          // the request cookie expires while the request runs
        $store->save();

        $queued = $jar->queued($id);
        $this->assertNotNull($queued, 'the cookie driver must still write the session');
        $data = unserialize(json_decode($queued->getValue(), true)['data']);
        $this->assertSame('ar', $data['locale']);
        $this->assertSame(7, $data['login_web_x']);
    }

    public function test_the_app_session_manager_builds_merging_stores(): void
    {
        $this->assertInstanceOf(MergingSessionManager::class, $this->app['session']);
        $this->assertInstanceOf(MergingStore::class, $this->app['session']->driver('array'));

        config(['session.encrypt' => true]);
        $manager = new MergingSessionManager($this->app);
        $this->assertInstanceOf(MergingEncryptedStore::class, $manager->driver('array'));
    }
}

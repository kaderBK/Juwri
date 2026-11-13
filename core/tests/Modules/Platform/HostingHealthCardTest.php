<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Services\HostingHealthCard;
use App\Support\HostingPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Tests\Feature\Admin\InteractsWithAdminPanel;
use Tests\TestCase;

/** P6-138: the admin "Hosting health" card: each warning with a faked host, the 5-minute cache, and platform-admin-only access. */
class HostingHealthCardTest extends TestCase
{
    use InteractsWithAdminPanel;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $host = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdminPanel();
        config(['hosting.profile_explicit' => true, 'mail.default' => 'log', 'queue.default' => 'database', 'cache.default' => 'array', 'session.driver' => 'array', 'app.url' => 'https://shop.example.org']);
        Cache::flush();
        $this->host = ['php' => '8.4.3', 'age' => 30, 'backlog' => null, 'failed' => 0, 'exposed' => [], 'mail' => [], 'calls' => 0];
        $test = $this;
        $this->app->instance(HostingPreflight::class, new class($test) extends HostingPreflight
        {
            public function __construct(private HostingHealthCardTest $t) {}

            protected function phpVersion(): string
            {
                $this->t->host('calls', $this->t->host('calls') + 1);

                return $this->t->host('php');
            }

            protected function schedulerAge(): ?int
            {
                return $this->t->host('age');
            }

            protected function queueBacklogAge(): ?int
            {
                return $this->t->host('backlog');
            }

            protected function failedJobsCount(): int
            {
                return $this->t->host('failed');
            }

            protected function exposedPaths(): ?array
            {
                return $this->t->host('exposed');
            }

            protected function mailAuthStatus(): ?array
            {
                return $this->t->host('mail');
            }

            protected function mailDomain(): string
            {
                return 'shop.example.org';
            }
        });
    }

    /** Test double accessor (the anonymous probe class lives outside this class's scope). */
    public function host(string $key, mixed $set = null): mixed
    {
        if (func_num_args() === 2) {
            $this->host[$key] = $set;
        }

        return $this->host[$key];
    }

    /** @return list<string> */
    private function codes(): array
    {
        return array_column(app(HostingHealthCard::class)->get()['warnings'], 'code');
    }

    private function fresh(): void
    {
        app(HostingHealthCard::class)->forget();
    }

    public function test_a_healthy_host_shows_no_warnings(): void
    {
        $this->assertSame([], $this->codes());
    }

    public function test_hosting_profile_unset(): void
    {
        config(['hosting.profile_explicit' => false]);
        $this->assertSame(['profile_unset'], $this->codes());
    }

    public function test_web_php_older_than_8_4_is_read_at_request_time(): void
    {
        $this->host['php'] = '8.3.9';
        $card = app(HostingHealthCard::class)->get();
        $this->assertSame(['php_old'], array_column($card['warnings'], 'code'));
        $this->assertStringContainsString('8.3.9', $card['warnings'][0]['title']);
    }

    public function test_scheduler_heartbeat_older_than_five_minutes_or_missing(): void
    {
        $this->host['age'] = 301;
        $this->assertSame(['scheduler_stale'], $this->codes());
        $this->fresh();
        $this->host['age'] = null;
        $this->assertSame(['scheduler_stale'], $this->codes());
        $this->fresh();
        $this->host['age'] = 300;
        $this->assertSame([], $this->codes());
    }

    public function test_web_cron_only_row_shows_the_cli_cron_line_and_clears_after_one_cli_tick(): void
    {
        scheduler_stamp_heartbeat(console: false, processes: false);
        $card = app(HostingHealthCard::class)->get();
        $this->assertSame(['web_cron_only'], array_column($card['warnings'], 'code'));
        $this->assertSame(___('Some background tasks (module jobs, large backups) are not running'), $card['warnings'][0]['title']);
        $this->assertStringContainsString('cd '.base_path().' && /opt/cpanel/ea-php84/root/usr/bin/php artisan schedule:run', $card['warnings'][0]['fix']);
        $this->assertStringEndsWith('HOSTING.md#cron-and-scheduler', $card['warnings'][0]['url']);

        scheduler_stamp_heartbeat(console: true);
        $this->fresh();
        $this->assertSame([], $this->codes());
    }

    public function test_a_stale_scheduler_is_reported_as_stale_not_as_web_cron_only(): void
    {
        scheduler_stamp_heartbeat(console: false, processes: false);
        $this->host['age'] = 400;
        $this->assertSame(['scheduler_stale'], $this->codes());
    }

    public function test_queue_backlog_over_ten_minutes_and_failed_jobs(): void
    {
        $this->host['backlog'] = 601;
        $this->assertSame(['queue_backlog'], $this->codes());
        $this->fresh();
        $this->host['backlog'] = 600;
        $this->host['failed'] = 3;
        $card = app(HostingHealthCard::class)->get();
        $this->assertSame(['failed_jobs'], array_column($card['warnings'], 'code'));
        $this->assertStringContainsString('3', $card['warnings'][0]['title']);
    }

    public function test_core_reachable_from_the_web(): void
    {
        $this->host['exposed'] = ['core/.env'];
        $card = app(HostingHealthCard::class)->get();
        $this->assertSame(['core_exposed'], array_column($card['warnings'], 'code'));
        $this->assertStringContainsString('core/.env', $card['warnings'][0]['title']);
    }

    public function test_mail_spf_and_dmarc_missing(): void
    {
        $this->host['mail'] = ['spf', 'dmarc'];
        $this->assertSame(['mail_spf', 'mail_dmarc'], $this->codes());
    }

    public function test_each_warning_has_a_fix_line_and_a_hosting_guide_link(): void
    {
        config(['hosting.profile_explicit' => false]);
        $this->host = array_merge($this->host, ['php' => '8.3.1', 'age' => null, 'backlog' => 999, 'failed' => 1, 'exposed' => ['core/'], 'mail' => ['spf', 'dmarc']]);
        $warnings = app(HostingHealthCard::class)->get()['warnings'];
        $this->assertCount(8, $warnings);
        foreach ($warnings as $w) {
            $this->assertNotSame('', $w['fix'], $w['code']);
            $this->assertMatchesRegularExpression('~^/deploy/HOSTING\.md#[a-z-]+$~', $w['url'], $w['code']);
        }
    }

    public function test_the_real_probes_flag_exposed_core_and_skip_when_self_requests_fail(): void
    {
        $this->app->instance(HostingPreflight::class, new HostingPreflight);
        Http::fake([
            'shop.example.org/core/.env' => Http::response("APP_NAME=x\nAPP_KEY=base64:abc\n", 200),
            'shop.example.org/core/composer.json' => Http::response('Not found', 404),
        ]);
        $result = (new HostingPreflight)->healthWarnings(true);
        $exposed = array_values(array_filter($result['warnings'], fn ($w) => $w['code'] === 'core_exposed'));
        $this->assertCount(1, $exposed);
        $this->assertSame('core/.env', $exposed[0]['params']['paths']);
        $this->assertNotContains('web_exposure', $result['skipped']);

        Http::fake(fn () => throw new ConnectionException('no route to self'));
        $result = (new HostingPreflight)->healthWarnings(true);
        $this->assertContains('web_exposure', $result['skipped']);
        $this->assertNotContains('core_exposed', array_column($result['warnings'], 'code'));

        config(['app.url' => 'http://localhost']);
        $this->assertContains('web_exposure', (new HostingPreflight)->healthWarnings(true)['skipped']);
    }

    public function test_database_drivers_whose_table_is_missing(): void
    {
        $this->app->instance(HostingPreflight::class, new HostingPreflight);
        Http::fake(fn () => throw new ConnectionException('skip'));
        config([
            'session.driver' => 'database', 'session.table' => 'missing_sessions',
            'cache.default' => 'database', 'cache.stores.database.driver' => 'database', 'cache.stores.database.table' => 'missing_cache',
            'queue.connections.database.table' => 'missing_jobs',
        ]);
        $codes = (new HostingPreflight)->healthWarnings(true)['warnings'];
        $missing = collect($codes)->where('code', 'driver_table_missing')->pluck('params.table', 'params.driver')->all();
        $this->assertSame(['session' => 'missing_sessions', 'cache' => 'missing_cache', 'queue' => 'missing_jobs'], $missing);

        config(['session.driver' => 'file', 'cache.default' => 'array', 'queue.default' => 'sync']);
        $this->assertNotContains('driver_table_missing', array_column((new HostingPreflight)->healthWarnings(true)['warnings'], 'code'));
    }

    public function test_the_real_queue_probes_read_the_jobs_and_failed_jobs_tables(): void
    {
        $this->app->instance(HostingPreflight::class, new HostingPreflight);
        Http::fake(fn () => throw new ConnectionException('skip'));
        \DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time() - 1200, 'created_at' => time() - 1200]);
        \DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
        $codes = array_column((new HostingPreflight)->healthWarnings(true)['warnings'], 'code');
        $this->assertContains('queue_backlog', $codes);
        $this->assertContains('failed_jobs', $codes);
    }

    public function test_results_are_cached_for_five_minutes(): void
    {
        $card = app(HostingHealthCard::class);
        $card->get();
        $card->get();
        $this->assertSame(1, $this->host['calls'], 'second call is served from the cache');
        $this->host['php'] = '8.2.0';
        $this->assertSame([], array_column($card->get()['warnings'], 'code'), 'a change shows up only after the cache expires');
        $this->travel(HostingHealthCard::TTL_SECONDS + 1)->seconds();
        $this->assertSame(['php_old'], array_column($card->get()['warnings'], 'code'));
        $this->assertSame(300, HostingHealthCard::TTL_SECONDS);
    }

    public function test_a_broken_cache_store_does_not_break_the_card(): void
    {
        Cache::shouldReceive('remember')->andThrow(new \RuntimeException('no cache table'));
        $this->host['php'] = '8.1.0';
        $this->assertSame(['php_old'], $this->codes());
    }

    public function test_platform_admin_sees_the_card_on_the_dashboard(): void
    {
        $this->host['php'] = '8.3.9';
        $this->actingAs($this->admin())->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('id="hosting-health"', false)
            ->assertSee('data-hosting-warning="php_old"', false)
            ->assertSee('/deploy/HOSTING.md#php-version', false);
    }

    public function test_a_store_owner_cannot_see_the_card(): void
    {
        $this->host['php'] = '8.3.9';
        $response = $this->actingAs($this->plainUser())->get(route('admin.dashboard'));
        $this->assertContains($response->getStatusCode(), [302, 403], 'a store owner is turned away from the admin dashboard');
        $this->assertStringNotContainsString('hosting-health', $response->getContent());
        $this->assertSame(0, $this->host['calls'], 'the probes never ran for a non-admin');
    }

    public function test_a_guest_cannot_see_the_card(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect();
        $this->assertSame(0, $this->host['calls']);
    }

    public function test_every_card_string_is_translated_in_all_locales(): void
    {
        preg_match_all("/___\\('((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(app_path('Services/HostingHealthCard.php')), $m);
        $strings = array_unique(array_map(fn ($s) => str_replace("\\'", "'", $s), $m[1]));
        $strings = array_merge($strings, ['Hosting health', 'How to fix', 'Hosting guide', 'No hosting problems found.']);
        $this->assertGreaterThan(20, count($strings));
        foreach ($strings as $english) {
            $key = 'lang.'.Str::slug($english, '_');
            $this->assertTrue(Lang::has($key, 'en'), "en: {$english}");
            $placeholders = fn (string $s) => (function () use ($s) {
                preg_match_all('/:[a-z]+/', $s, $p);
                sort($p[0]);

                return $p[0];
            })();
            foreach (['ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'] as $locale) {
                $this->assertTrue(Lang::has($key, $locale, false), "{$locale}: {$english}");
                $translated = Lang::get($key, [], $locale, false);
                $this->assertNotSame($english, $translated, "{$locale} is English: {$english}");
                $this->assertSame($placeholders($english), $placeholders($translated), "{$locale} placeholders: {$english}");
            }
        }
    }

    public function test_every_anchor_the_card_links_to_exists_in_the_hosting_guide(): void
    {
        $guide = (string) file_get_contents(base_path('../deploy/HOSTING.md'));
        $anchors = array_unique(array_values((new \ReflectionClassConstant(HostingHealthCard::class, 'ANCHORS'))->getValue()));
        $this->assertNotEmpty($anchors);

        foreach ($anchors as $anchor) {
            $this->assertMatchesRegularExpression(
                '/<a id="'.preg_quote($anchor, '/').'"><\/a>/',
                $guide,
                "deploy/HOSTING.md has no <a id=\"{$anchor}\"></a>"
            );
        }
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\LegacyCompat\Providers\LegacyCompatServiceProvider;
use Juwri\Modules\LegacyCompat\Support\QrIdDecoder;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * J52b: old printed storefront links with the module ON (LEGACYCOMPAT-SPEC §3 rows `?qr-id=`, `?table=`, `?rt=`).
 * Synthetic fixtures and passphrases only.
 */
class LegacyStorefrontEntryTest extends TestCase
{
    use RefreshDatabase;

    private const PASS_A = 'synthetic-passphrase-a';

    private const PASS_B = 'Synthetic Passphrase B #2';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);

        config([
            'legacy_compat.enabled' => true,
            'legacy_compat.qr_passphrases' => [self::PASS_A, self::PASS_B],
            'abuse.table_key_misses_per_minute' => 5,
            'abuse.table_key_misses_per_minute_network' => 5,
        ]);
        LegacyCompatServiceProvider::activate($this->app);
    }

    /** @param array<string, mixed> $planSettings */
    private function store(array $planSettings = [], int $ownerStatus = 1): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => array_merge(['scan_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999, 'table_qr' => '1'], $planSettings),
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => $ownerStatus, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'store-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'Store', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
    }

    private function table(Store $store, string $key, string $areaType = 'table'): mixed
    {
        return $store->tables()->create(['table_no' => random_int(1, 999), 'key' => $key, 'seats' => 2, 'area_type' => $areaType]);
    }

    private static function qr(string $plain, string $pass = self::PASS_A): string
    {
        return urlencode(QrIdDecoder::encode($plain, $pass));
    }

    public function test_a_matching_code_counts_one_scan_and_302s_to_the_clean_url_with_no_store(): void
    {
        $store = $this->store();

        $response = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone) Mobile/15E148', 'Accept-Language' => 'fr-FR,fr;q=0.9'])
            ->get('/'.$store->slug.'?qr-id='.self::qr($store->slug).'&table=T9&src=fly%3Cer&rt=abc');

        $response->assertStatus(302)->assertRedirect(url('/'.$store->slug).'?table=T9&src=fly%3Cer&rt=abc');
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringNotContainsString('public', $cache);
        $this->assertStringNotContainsString('max-age', $cache);

        $view = $store->views()->sole();
        $this->assertSame('Mobile', $view->device);
        $this->assertSame('fr', $view->lang);
        $this->assertSame('flyer', $view->source);
    }

    public function test_both_passphrases_match_and_each_client_counts_once_per_window(): void
    {
        $store = $this->store();
        $a = self::qr($store->slug, self::PASS_A);
        $b = self::qr($store->slug, self::PASS_B);

        // Always a redirect, but one counted scan per IP + store per window (forged codes cannot flood the cap).
        $this->get('/'.$store->slug.'?qr-id='.$a)->assertStatus(302)->assertRedirect(url('/'.$store->slug));
        $this->get('/'.$store->slug.'?qr-id='.$b)->assertStatus(302);
        $this->get('/'.$store->slug.'?qr-id='.$a)->assertStatus(302);
        $this->assertSame(1, $store->views()->count());

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->get('/'.$store->slug.'?qr-id='.$b)->assertStatus(302);
        $this->assertSame(2, $store->views()->count(), 'another client counts');

        $this->travel(31)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('/'.$store->slug.'?qr-id='.$a)->assertStatus(302);
        $this->assertSame(3, $store->views()->count(), 'the same client counts again after the window');
    }

    /** N7: X-Forwarded-For is not trusted by default, so a forged header cannot mint extra scans. */
    public function test_a_forged_forwarded_for_header_does_not_make_a_new_client(): void
    {
        $store = $this->store();

        foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3'] as $fake) {
            $this->withHeaders(['X-Forwarded-For' => $fake])->get('/'.$store->slug.'?qr-id='.self::qr($store->slug))->assertStatus(302);
        }
        $this->assertSame(1, $store->views()->count());
        $this->assertSame('127.0.0.1', $store->views()->sole()->ip);
    }

    public function test_head_requests_count_nothing_and_are_not_redirected(): void
    {
        $store = $this->store();

        $this->call('HEAD', '/'.$store->slug.'?qr-id='.self::qr($store->slug))->assertOk();
        $this->assertSame(0, $store->views()->count());
    }

    public function test_unrelated_params_are_kept_and_nothing_is_added(): void
    {
        $store = $this->store();

        $location = (string) $this->get('/'.$store->slug.'?utm_campaign=x%20y&qr-id='.self::qr($store->slug).'&branch_id=4&item=9&src=door')
            ->assertStatus(302)->headers->get('Location');

        $this->assertSame(url('/'.$store->slug).'?src=door&utm_campaign=x%20y&branch_id=4&item=9', $location);
        $this->assertStringNotContainsString('qr-id', $location);
    }

    public function test_a_numeric_id_code_on_the_numeric_path_counts(): void
    {
        $store = $this->store();

        $this->get('/'.$store->id.'?qr-id='.self::qr((string) $store->id))
            ->assertStatus(302)->assertRedirect(url('/'.$store->id));
        $this->assertSame(1, $store->views()->count());
    }

    /** Coordinator negative test: a code forged for store B used on store A's slug is no scan, served direct. */
    public function test_another_stores_code_is_served_direct_without_a_scan(): void
    {
        $a = $this->store();
        $b = $this->store();

        $this->get('/'.$a->slug.'?qr-id='.self::qr($b->slug))->assertOk();
        $this->assertSame(0, $a->views()->count());
        $this->assertSame(0, $b->views()->count());
    }

    public function test_bad_codes_are_served_direct_without_a_scan(): void
    {
        $store = $this->store();

        foreach (['!!!', 'Zm9v', self::qr(strtoupper($store->slug)), self::qr($store->slug, 'not-configured')] as $bad) {
            $this->get('/'.$store->slug.'?qr-id='.$bad)->assertOk();
        }
        $this->get('/'.$store->slug.'?qr-id[]=x')->assertOk();

        $this->assertSame(0, $store->views()->count());
    }

    public function test_a_hidden_store_counts_nothing(): void
    {
        $store = $this->store([], 0);

        $this->get('/'.$store->slug.'?qr-id='.self::qr($store->slug))->assertNotFound();
        $this->assertSame(0, $store->views()->count());
    }

    public function test_a_scan_over_the_monthly_limit_serves_the_menu_and_counts_nothing(): void
    {
        $this->requiresEditionPackage('a bounded scan limit is an edition plan setting');
        $store = $this->store(['scan_limit' => 1]);
        $store->views()->create(['date' => now(), 'ip' => '1.1.1.1']);

        $this->get('/'.$store->slug.'?qr-id='.self::qr($store->slug))->assertOk();
        $this->assertSame(1, $store->views()->count());
    }

    /** A qr-id grants nothing: the table comes only from a key of this store, never from the code. */
    public function test_the_code_never_sets_a_table_or_a_room_token(): void
    {
        $store = $this->store();

        $this->get('/'.$store->slug.'?qr-id='.self::qr($store->slug))->assertStatus(302);
        $this->assertNull(session('hotel_room_token'));

        $this->get('/'.$store->slug)->assertOk()->assertViewHas('table', null);
    }

    public function test_a_known_table_key_opens_the_table_after_the_scan(): void
    {
        $store = $this->store();
        $table = $this->table($store, 'tk-'.Str::random(12));

        $this->get('/'.$store->slug.'?qr-id='.self::qr($store->slug).'&table='.$table->key)
            ->assertStatus(302)->assertRedirect(url('/'.$store->slug).'?table='.$table->key);
        $this->get('/'.$store->slug.'?table='.$table->key)->assertOk()
            ->assertViewHas('table', fn ($t) => $t && (int) $t->id === (int) $table->id);
    }

    public function test_an_unknown_or_other_stores_table_key_shows_the_menu_without_a_table(): void
    {
        $store = $this->store();
        $other = $this->store();
        $foreign = $this->table($other, 'tk-'.Str::random(12));

        $this->get('/'.$store->slug.'?table=no-such-key')->assertOk()->assertViewHas('table', null);
        $this->get('/'.$store->slug.'?table='.$foreign->key)->assertOk()->assertViewHas('table', null);
    }

    /** Guessing table keys is cut off per store (session + client network): over the limit, no lookup, plain menu. */
    public function test_table_key_guessing_is_cut_off_without_an_oracle(): void
    {
        $store = $this->store();
        $table = $this->table($store, 'tk-'.Str::random(12));

        for ($i = 0; $i < 5; $i++) {
            $this->get('/'.$store->slug.'?table=guess-'.$i)->assertOk()->assertViewHas('table', null);
        }
        // Over the limit even a real key is not looked up: same answer as a wrong key, no 429 to probe.
        $this->get('/'.$store->slug.'?table='.$table->key)->assertOk()->assertViewHas('table', null);

        // Per store: the same client still gets its table at another store.
        $other = $this->store();
        $otherTable = $this->table($other, 'tk-'.Str::random(12));
        $this->get('/'.$other->slug.'?table='.$otherTable->key)->assertOk()
            ->assertViewHas('table', fn ($t) => $t && (int) $t->id === (int) $otherTable->id);
    }

    public function test_known_keys_do_not_use_up_the_limit(): void
    {
        $store = $this->store();
        $table = $this->table($store, 'tk-'.Str::random(12));

        for ($i = 0; $i < 8; $i++) {
            $this->get('/'.$store->slug.'?table='.$table->key)->assertOk()
                ->assertViewHas('table', fn ($t) => $t && (int) $t->id === (int) $table->id);
        }
    }

    /** B1: an array `table[]=` is never a key, counts as a miss and toward the limit. */
    public function test_an_array_table_param_is_a_stripped_miss(): void
    {
        $store = $this->store();
        $table = $this->table($store, 'tk-'.Str::random(12));

        $this->get('/'.$store->slug.'?table[]='.$table->key)->assertOk()->assertViewHas('table', null);
        for ($i = 0; $i < 4; $i++) {
            $this->get('/'.$store->slug.'?table[]=guess-'.$i)->assertOk();
        }
        $this->get('/'.$store->slug.'?table='.$table->key)->assertOk()->assertViewHas('table', null);
    }

    /** B1: a `table` in a GET request body is never read (the page reads the query string only). */
    public function test_a_table_in_the_request_body_is_ignored(): void
    {
        $store = $this->store();
        $table = $this->table($store, 'tk-'.Str::random(12));
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html'];

        $this->call('GET', '/'.$store->slug, [], [], [], $server, (string) json_encode(['table' => $table->key]))
            ->assertOk()->assertViewHas('table', null);
    }

    public function test_the_room_token_is_carried_through_the_scan_and_stashed_by_the_page(): void
    {
        $store = $this->store();
        $room = $this->table($store, 'rk-'.Str::random(12), 'room');
        $token = str_repeat('ab', 20);

        $this->get('/'.$store->slug.'?qr-id='.self::qr($store->slug).'&table='.$room->key.'&rt='.$token)
            ->assertStatus(302)->assertRedirect(url('/'.$store->slug).'?table='.$room->key.'&rt='.$token);
        $this->get('/'.$store->slug.'?table='.$room->key.'&rt='.$token)->assertOk();

        $this->assertSame($token, session('hotel_room_token'));
    }

    public function test_post_requests_are_never_redirected(): void
    {
        $store = $this->store();

        $status = $this->post('/'.$store->slug.'?qr-id='.self::qr($store->slug))->getStatusCode();
        $this->assertNotSame(302, $status);
        $this->assertSame(0, $store->views()->count());
    }

    public function test_switching_off_at_runtime_stops_decoding(): void
    {
        $store = $this->store();
        config(['legacy_compat.enabled' => false]);

        $this->get('/'.$store->slug.'?qr-id='.self::qr($store->slug))->assertOk();
        $this->assertSame(0, $store->views()->count());
    }
}

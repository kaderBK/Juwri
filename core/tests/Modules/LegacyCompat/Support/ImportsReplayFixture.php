<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat\Support;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacyMbcFixture;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacySourceFixture as Source;
use Juwri\Modules\LegacyCompat\Providers\LegacyCompatServiceProvider;
use Juwri\Modules\LegacyCompat\Support\LegacyCompatRoutes;
use Tests\Support\TestPlans;

/**
 * M7-B: the shared set-up of the replay tests — the synthetic legacy source in a throw-away in-memory connection, the
 * import into the test database, one active plan for every imported owner, the two old qr-id passphrases, the old
 * room-token key and (for the module-on test) the module itself.
 *
 * The values are the invented ones of the synthetic set (J19); nothing of a real install is used.
 */
trait ImportsReplayFixture
{
    /** The source connection of the synthetic legacy set (in memory, per test). */
    private const REPLAY_SOURCE = 'legacy_replay_source';

    /** @var array<string, mixed> the manifest of the synthetic legacy set */
    protected array $replayManifest = [];

    /**
     * Write the synthetic legacy source, import it into the test database and return its manifest.
     *
     * @return array<string, mixed> the manifest LegacyMbcFixture::populate() returns
     */
    protected function importReplaySource(): array
    {
        config([
            'database.connections.'.self::REPLAY_SOURCE => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
            ],
            'legacy_compat.enabled' => true,
            'legacy_compat.import.enabled' => true,
            'legacy_compat.cutover.owner_signoff' => true,
            'legacy_compat.import.target_host' => 'localhost',
            'legacy_compat.import.source_timezone' => 'Asia/Riyadh',
            'legacy_compat.import.source_app_key' => '',
            'legacy_compat.import.enabled_gateways' => ['stripe', 'paypal'],
        ]);
        DB::purge(self::REPLAY_SOURCE);

        $source = (new Source(DB::connection(self::REPLAY_SOURCE)))->createSchema();
        $manifest = LegacyMbcFixture::populate($source);

        $exit = Artisan::call('legacy:import', ['--source' => self::REPLAY_SOURCE]);
        $output = Artisan::output();
        $this->assertSame(0, $exit, 'the import of the synthetic source failed: '.$output);

        return $this->replayManifest = $manifest;
    }

    /** The first store of the synthetic set that gets cases (the reserved slugs do not). */
    protected function firstReplayedStore(): Store
    {
        foreach ($this->replayManifest['stores'] ?? [] as $store) {
            if (($store['active'] ?? false) !== true) {
                continue;
            }
            if (in_array((string) $store['slug'], ReplayUrlGenerator::RESERVED_SLUGS, true)) {
                continue;
            }

            return Store::findOrFail((int) $store['id']);
        }

        $this->fail('the synthetic set has no store to replay');
    }

    /**
     * One active plan, and every imported owner on it: an active owner on an active plan is what the storefront page
     * needs to show a store at all.
     */
    protected function planImportedOwners(): object
    {
        $plan = TestPlans::create([
            'name' => 'Replay', 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => [
                'scan_limit' => 99999, 'menu_limit' => 99999, 'category_limit' => 99999,
                'table_qr' => '1', 'hotel' => '1', 'allow_ordering' => '1',
            ],
            'status' => 1,
        ]);

        DB::table('users')->update([
            'group_id' => (string) $plan->id,
            'status' => 1,
            'email_verified_at' => now(),
        ]);

        return $plan;
    }

    /** The two old qr-id passphrases (the frozen vector file) and the old room-token key with its grace period. */
    protected function configureReplaySecrets(): void
    {
        config([
            'legacy_compat.qr_passphrases' => ReplayUrlGenerator::passphrases(),
            'legacy_compat.room_token_legacy_keys' => [Crypt::encryptString(LegacyMbcFixture::OLD_APP_KEY)],
            'legacy_compat.room_token_legacy_until' => now()->addMonths(6)->toDateTimeString(),
        ]);
    }

    /** The storefront page itself: locale, theme, language row and the middleware this flow does not need. */
    protected function bootReplayStorefront(): void
    {
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
    }

    /** The module itself: the storefront middleware, the room-charge decorator and the legacy route file. */
    protected function activateReplayModule(): void
    {
        LegacyCompatServiceProvider::activate($this->app);
        LegacyCompatRoutes::register();
        Route::getRoutes()->refreshNameLookups();
    }
}

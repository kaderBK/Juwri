<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Core\Support\ModuleSeeders;
use Juwri\Modules\Storefront\Support\DemoOwner\DemoCredentials;
use Juwri\Modules\Storefront\Support\DemoOwner\DemoStores;
use Juwri\Modules\Storefront\Support\DemoOwner\DemoWipe;
use RuntimeException;
use Throwable;

/**
 * J62-1: the owner hands-on demo — `php artisan juwri:demo-seed`.
 *
 * Fills the local database with synthetic demo data (J19: seeders only, never a foreign dump) so
 * the owner can click through the product: the demo stores the plan fixes, the logins to reach
 * them, and the credentials file that tells the owner what they are.
 *
 * Local only: the command refuses to run outside local / testing / dev and writes nothing when
 * it refuses. `--fresh` removes the demo-owned rows first (the demo stores and the demo logins)
 * and leaves every other store and login alone.
 *
 * The logins never reach the console: the command prints the path of the credentials file (mode
 * 0600) and the storefront URLs, nothing else.
 */
class DemoSeed extends Command
{
    protected $signature = 'juwri:demo-seed {--fresh : wipe the demo-tagged rows first}';

    protected $description = 'Fill the local database with the synthetic owner demo (stores, catalogue, logins)';

    /** The only environments the demo may run in. */
    private const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'dev'];

    /** The seeders that already exist, by short name (found through the module registry). */
    private const EXISTING_SEEDERS = [
        'ThemePackDemoSeeder',
        'KitDemoSeeder',
        'HotelDemoSeeder',
        'VerticalShowcaseSeeder',
        'PharmacyDemoSeeder',
        'PosV2DemoSeeder',
        'FusionResellerDemoSeeder',
    ];

    /** The demo's own seeders (the accounts, catalogue and activity steps), by short name. */
    private const DEMO_SEEDERS = [
        'DemoAccountsSeeder',
        'DemoCatalogSeeder',
        'DemoActivitySeeder',
    ];

    /**
     * A demo seeder may land under another name (the plan calls the accounts step "DemoOwnerSeeder").
     * The first name that exists wins, so both spellings run the same step exactly once.
     *
     * @var array<string, list<string>>
     */
    private const SEEDER_ALIASES = [
        'DemoAccountsSeeder' => ['DemoOwnerSeeder'],
    ];

    public function handle(DemoWipe $wipe, DemoStores $stores, DemoCredentials $credentials): int
    {
        if (! $this->allowedHere()) {
            $this->error('juwri:demo-seed refuses to run in the "'.$this->laravel->environment().'" environment (allowed: '.implode(', ', self::ALLOWED_ENVIRONMENTS).') — nothing was written.');

            return self::FAILURE;
        }

        $failed = [];

        if ((bool) $this->option('fresh')) {
            $this->wipe($wipe);
        }

        foreach ([...self::EXISTING_SEEDERS, ...self::DEMO_SEEDERS] as $shortName) {
            $this->runStep($shortName, $failed);
        }

        $this->tagDemoStores($stores);

        if ($failed !== []) {
            $this->error('The demo is incomplete: '.implode(' | ', $failed).' — the credentials file was not written.');

            return self::FAILURE;
        }

        $this->writeCredentials($credentials, $stores);

        return self::SUCCESS;
    }

    /** The demo is a local thing: never in production, never on an unknown environment. */
    private function allowedHere(): bool
    {
        return in_array($this->laravel->environment(), self::ALLOWED_ENVIRONMENTS, true);
    }

    /** `--fresh`: the demo-owned rows and nothing else. */
    private function wipe(DemoWipe $wipe): void
    {
        $result = $wipe->run();

        $this->info('step --fresh: '.$this->plural(count($result['stores']), 'demo store').' erased, '
            .$this->plural(count($result['users']), 'demo login').' removed.');

        foreach ($result['kept'] as $kept) {
            $this->warn('step --fresh: kept '.$kept);
        }
    }

    /**
     * Run one seeder and report it. A seeder that is not part of this installation (the demo's own
     * seeders land step by step) is skipped, not failed.
     *
     * @param  list<string>  $failed
     */
    private function runStep(string $shortName, array &$failed): void
    {
        $class = $this->resolve($shortName);
        if ($class === null) {
            $this->warn('step '.$shortName.': skipped (not part of this installation)');

            return;
        }

        try {
            // A savepoint per step: on PostgreSQL a failed statement aborts the surrounding transaction, so a
            // failed step must roll back to its own savepoint for the following steps to keep working.
            // Platform seeding: no tenant scope (a scope left by an earlier step or the request would hide the
            // demo's own rows from its upserts and make a second run insert duplicates).
            DB::transaction(fn () => TenantContext::bypass(fn () => $this->runSeeder($class)));
            $this->info('step '.$shortName.': ok');
        } catch (Throwable $e) {
            $failed[] = $shortName.': '.$e->getMessage();
            $this->warn('step '.$shortName.': failed — '.$e->getMessage());
        }
    }

    /** The seeder class of a short name: the module registry first, the demo's own namespace second. */
    private function resolve(string $shortName): ?string
    {
        $names = [$shortName, ...(self::SEEDER_ALIASES[$shortName] ?? [])];

        foreach ($names as $name) {
            $registered = ModuleSeeders::find($name);
            if ($registered !== null) {
                return $registered;
            }

            $candidate = 'Juwri\\Modules\\Storefront\\Database\\Seeders\\'.$name;
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Run a seeder. The seeder's own output is muted on purpose: the command prints the summary
     * lines, the credentials path and the storefront URLs — and nothing that looks like a secret.
     */
    private function runSeeder(string $class): void
    {
        $seeder = app($class);
        if (! $seeder instanceof Seeder) {
            throw new RuntimeException($class.' is not a seeder.');
        }

        $seeder->setContainer($this->laravel);
        $seeder->__invoke();
    }

    /** Mark the demo stores as demo-owned, so `--fresh` finds them again. */
    private function tagDemoStores(DemoStores $stores): void
    {
        foreach ($stores->existing() as $store) {
            $stores->tag($store);
        }
    }

    /** The logins of the demo and the file they are written to. */
    private function writeCredentials(DemoCredentials $credentials, DemoStores $stores): void
    {
        // The seeders record their own logins; the file is written once, here.
        $this->line($credentials->write());

        foreach ($this->storefrontUrls($stores) as $url) {
            $this->line($url);
        }
    }

    /**
     * The storefronts of the demo stores that exist.
     *
     * @return list<string>
     */
    private function storefrontUrls(DemoStores $stores): array
    {
        $urls = [];

        foreach ($stores->slugs() as $slug) {
            $urls[] = $this->storefrontUrl($slug);
        }

        return $urls;
    }

    private function storefrontUrl(string $slug): string
    {
        try {
            return route('publicView', $slug);
        } catch (Throwable) {
            return url('/'.$slug);
        }
    }

    private function plural(int $count, string $noun): string
    {
        return $count.' '.$noun.($count === 1 ? '' : 's');
    }
}

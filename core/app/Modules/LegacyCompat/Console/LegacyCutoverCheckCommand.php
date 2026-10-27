<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\LegacyCompat\Import\ImportGuard;
use Juwri\Modules\LegacyCompat\Import\PlanMemberships;
use Juwri\Modules\LegacyCompat\Support\LegacyCompatRoutes;
use Juwri\Modules\LegacyCompat\Support\LegacyRoomTokenKeys;
use Throwable;

/**
 * J60: the cut-over checklist of the old-platform import (the runbook's pre-flight). `php artisan legacy:cutover-check
 * --source=legacy_import_source` prints one row per check and exits non-zero as soon as one check FAILS; a WARN is
 * reported but does not fail the run.
 *
 * READ-ONLY: the command writes nothing anywhere - no file, no row, no cache, no configuration change that leaves the
 * process. It only reads configuration, the source connection and the target's row counts.
 *
 * The detail column never carries a secret: an app key, a password, a host credential or a token is reported as
 * "set" / "missing" / a count only, never as a value. The command reports; it never fixes.
 */
class LegacyCutoverCheckCommand extends Command
{
    protected $signature = 'legacy:cutover-check
        {--source=legacy_import_source : Source connection name}';

    protected $description = 'Read-only cut-over checklist of the old-platform import (writes nothing).';

    /** The result of one check. */
    private const PASS = 'PASS';

    private const FAIL = 'FAIL';

    private const WARN = 'WARN';

    public function handle(): int
    {
        $checks = [
            'owner sign-off' => $this->checkOwnerSignoff(),
            'module on' => $this->checkModule(),
            'target host' => $this->checkTargetHost(),
            'import switch' => $this->checkImportSwitch(),
            'source connection' => $this->checkSourceConnection(),
            'source prefix' => $this->checkSourcePrefix(),
            'source time zone' => $this->checkSourceTimezone(),
            'source app key' => $this->checkSourceAppKey(),
            'plan map' => $this->checkPlanMap(),
            'media' => $this->checkMedia(),
            'room tokens' => $this->checkRoomTokens(),
            'google wallet' => $this->checkGoogleWallet(),
            'hosts' => $this->checkHosts(),
            'target empty' => $this->checkTargetEmpty(),
            'route cache' => $this->checkRouteCache(),
        ];

        $rows = [];
        $failed = false;
        foreach ($checks as $check => [$status, $detail]) {
            $rows[] = [$check, $status, $detail];
            if ($status === self::FAIL) {
                $failed = true;
            }
        }

        $this->line('HARD GATE: no production dry run, read-only freeze or DNS step until the owner has signed off the hands-on demo, the feature parity matrix and the SEO parity report.');
        $this->table(['Check', 'Result', 'Detail'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * J62 hard gate: the owner's sign-off of the hands-on demo, the feature parity matrix and the SEO parity report.
     *
     * @return array{0: string, 1: string}
     */
    private function checkOwnerSignoff(): array
    {
        if (ImportGuard::signoffReason() !== null) {
            return [self::FAIL, 'owner sign-off missing (demo, feature parity matrix, SEO parity report): legacy_compat.cutover.owner_signoff'];
        }

        return [self::PASS, 'signed off'];
    }

    /**
     * The module switch: with it off no legacy route exists and the import refuses.
     *
     * @return array{0: string, 1: string}
     */
    private function checkModule(): array
    {
        if (! LegacyCompatRoutes::enabled()) {
            return [self::FAIL, 'the LegacyCompat module is off (legacy_compat.enabled)'];
        }

        return [self::PASS, 'on'];
    }

    /**
     * J60: a fresh-install host must never receive migrated data, so the target's own host is checked before
     * anything else. The host list itself is never printed.
     *
     * @return array{0: string, 1: string}
     */
    private function checkTargetHost(): array
    {
        $reasons = ImportGuard::targetHostReasons();
        if ($reasons !== []) {
            return [self::FAIL, implode('; ', $reasons)];
        }

        return [self::PASS, 'this host may receive migrated data'];
    }

    /**
     * The import switch: the module alone does not open the import.
     *
     * @return array{0: string, 1: string}
     */
    private function checkImportSwitch(): array
    {
        if (! (bool) config('legacy_compat.import.enabled', false)) {
            return [self::FAIL, 'the import switch is off (legacy_compat.import.enabled)'];
        }

        return [self::PASS, 'on'];
    }

    /**
     * The source connection: configured, not the target database and readable. The connection is built exactly as
     * {@see LegacyImportCommand} builds it, so the check reads the connection the import would read.
     *
     * @return array{0: string, 1: string}
     */
    private function checkSourceConnection(): array
    {
        $name = $this->sourceName();
        $this->buildSourceConnection($name);

        if (! is_array(config('database.connections.'.$name))) {
            return [self::FAIL, 'the source connection ['.$name.'] is not configured'];
        }

        try {
            $source = DB::connection($name);
            $target = DB::connection();
            if ($name === $target->getName() || ImportGuard::sameDatabase($source->getConfig(), $target->getConfig())) {
                return [self::FAIL, 'the source connection points at the target database'];
            }
            $source->select('select 1');
        } catch (Throwable $e) {
            // Safe text only: the exception CLASS, never its message (a database message can carry host names,
            // credentials and row values).
            return [self::FAIL, 'the source connection cannot be read ('.$e::class.')'];
        }

        return [self::PASS, 'the source connection is configured, readable and not the target'];
    }

    /**
     * The source table prefix: empty is allowed but only for unprefixed source tables.
     *
     * @return array{0: string, 1: string}
     */
    private function checkSourcePrefix(): array
    {
        $prefix = trim((string) config('legacy_compat.import.connection.prefix', ''));
        if ($prefix === '') {
            return [self::WARN, 'no table prefix; correct only if the source tables are unprefixed'];
        }

        return [self::PASS, 'table prefix: '.$prefix];
    }

    /**
     * The source time zone: required and never guessed (source DATETIME values carry no zone).
     *
     * @return array{0: string, 1: string}
     */
    private function checkSourceTimezone(): array
    {
        $zone = trim((string) config('legacy_compat.import.source_timezone', ''));
        if ($zone === '') {
            return [self::FAIL, 'the source time zone is not set (legacy_compat.import.source_timezone)'];
        }
        if (! in_array($zone, timezone_identifiers_list(), true)) {
            return [self::FAIL, 'the source time zone is not a valid zone name'];
        }

        return [self::PASS, 'set'];
    }

    /**
     * The source app key: without it the encrypted gateway secrets stay in the old install.
     *
     * @return array{0: string, 1: string}
     */
    private function checkSourceAppKey(): array
    {
        $key = trim((string) config('legacy_compat.import.source_app_key', ''));
        if ($key !== '') {
            return [self::PASS, 'set'];
        }

        return [self::WARN, 'encrypted gateway secrets will not be imported'];
    }

    /**
     * The plan map: not empty, and every plan it names exists in this install. Only ids are reported (a plan name
     * is data of the old install, a plan id is not).
     *
     * @return array{0: string, 1: string}
     */
    private function checkPlanMap(): array
    {
        $map = PlanMemberships::map();
        if ($map === []) {
            return [self::FAIL, 'the plan map is empty (legacy_compat.import.plan_map)'];
        }

        try {
            $target = DB::connection();
            // An install without a plans table (Community) can only map to the free plan.
            $hasPlans = $target->getSchemaBuilder()->hasTable('plans');

            $missing = [];
            foreach ($map as $source => $plan) {
                if ($plan === null) {
                    continue; // the free plan: no plan row needed.
                }
                if (! $hasPlans || ! $target->table('plans')->where('id', $plan)->exists()) {
                    $missing[] = 'source '.$source.' > plan '.$plan;
                }
            }
        } catch (Throwable $e) {
            // Safe text only: the exception CLASS, never its message.
            return [self::FAIL, 'the plans table cannot be read ('.$e::class.')'];
        }

        if ($missing !== []) {
            return [self::FAIL, 'the plan map names plans this install does not have: '.implode(', ', $missing)];
        }

        return [self::PASS, count($map).' source plan(s) mapped'];
    }

    /**
     * The media copy settings: both disk names exist in config/filesystems.php and the manifest's directory exists.
     * Only the NAMES of the missing settings are listed.
     *
     * @return array{0: string, 1: string}
     */
    private function checkMedia(): array
    {
        $disks = (array) config('filesystems.disks', []);
        $missing = [];

        foreach (['from_disk', 'to_disk'] as $setting) {
            $disk = trim((string) config('legacy_compat.media.'.$setting, ''));
            if ($disk === '' || ! array_key_exists($disk, $disks)) {
                $missing[] = 'media.'.$setting;
            }
        }

        $manifest = trim((string) config('legacy_compat.media.manifest', ''));
        if ($manifest === '') {
            $missing[] = 'media.manifest';
        } elseif (! is_dir(dirname($manifest))) {
            $missing[] = 'media.manifest (directory)';
        }

        if ($missing !== []) {
            return [self::FAIL, 'missing: '.implode(', ', $missing)];
        }

        return [self::PASS, 'the disks and the manifest are configured'];
    }

    /**
     * The old room-token keys: every configured key must decrypt with the current app key, and the grace period
     * (J56) must still run. The keys themselves are never printed.
     *
     * @return array{0: string, 1: string}
     */
    private function checkRoomTokens(): array
    {
        $keys = (array) config('legacy_compat.room_token_legacy_keys', []);
        if ($keys === []) {
            return [self::WARN, 'old room QRs will not charge to room'];
        }

        $usable = (new LegacyRoomTokenKeys)->count();
        if ($usable !== count($keys)) {
            return [self::FAIL, (count($keys) - $usable).' of '.count($keys).' keys do not decrypt'];
        }

        $ends = LegacyRoomTokenKeys::graceEndsAt();
        if ($ends === null) {
            return [self::FAIL, 'the room-token grace end date is not an absolute date'];
        }
        if (! $ends->greaterThan(now())) {
            return [self::FAIL, 'the room-token grace period ended on '.$ends->format('Y-m-d')];
        }

        return [self::PASS, 'the keys decrypt; old room tokens are accepted until '.$ends->format('Y-m-d')];
    }

    /**
     * The Google Wallet pass ids (J59k): off (the defaults) or fully configured. Half configured fails: the pass ids
     * would change halfway through the imported range and every saved pass would stop updating.
     *
     * @return array{0: string, 1: string}
     */
    private function checkGoogleWallet(): array
    {
        $issuer = trim((string) config('legacy_compat.google_wallet.issuer_id', ''));
        $max = (int) config('legacy_compat.google_wallet.max_store_id', 0);
        $issuerOk = $issuer !== '' && preg_match('/^[0-9]{1,32}$/D', $issuer) === 1;

        if ($issuer === '' && $max === 0) {
            return [self::PASS, 'off'];
        }
        if ($issuerOk && $max > 0) {
            return [self::WARN, 'on: confirm the issuer with the owner (J59k)'];
        }

        return [self::FAIL, 'half configured'];
    }

    /**
     * The old platform's hosts: without them the old printed links do not reach the imported stores.
     *
     * @return array{0: string, 1: string}
     */
    private function checkHosts(): array
    {
        $hosts = (array) config('legacy_compat.hosts', []);
        if ($hosts === []) {
            return [self::WARN, 'no old platform host is configured (legacy_compat.hosts)'];
        }

        return [self::PASS, count($hosts).' host(s) configured'];
    }

    /**
     * The target tables the importer writes must be empty: a row this importer did not write could collide with an
     * imported id. The first five reasons are listed (table names and counts only).
     *
     * @return array{0: string, 1: string}
     */
    private function checkTargetEmpty(): array
    {
        try {
            $reasons = ImportGuard::targetReasons(DB::connection());
        } catch (Throwable $e) {
            // Safe text only: the exception CLASS, never its message (a database message can carry the host and the
            // database name).
            return [self::FAIL, 'the target tables cannot be read ('.$e::class.')'];
        }

        if ($reasons !== []) {
            return [self::FAIL, implode('; ', array_slice($reasons, 0, 5))];
        }

        return [self::PASS, 'the target tables are empty'];
    }

    /**
     * A cached route file keeps the old paths after the import: it has to be rebuilt.
     *
     * @return array{0: string, 1: string}
     */
    private function checkRouteCache(): array
    {
        if (app()->routesAreCached()) {
            return [self::WARN, 'routes are cached: rebuild after import (php artisan route:cache)'];
        }

        return [self::PASS, 'not cached'];
    }

    /** The source connection name: --source, or the env-built connection by default. */
    private function sourceName(): string
    {
        $name = $this->option('source');
        $name = is_string($name) ? trim($name) : '';

        return $name !== '' ? $name : LegacyImportCommand::SOURCE_CONNECTION;
    }

    /**
     * The env-built source connection, registered exactly as {@see LegacyImportCommand} registers it: only when the
     * connection is not configured yet AND the env host is not empty.
     */
    private function buildSourceConnection(string $name): void
    {
        if ($name !== LegacyImportCommand::SOURCE_CONNECTION || is_array(config('database.connections.'.$name))) {
            return;
        }

        $connection = (array) config('legacy_compat.import.connection', []);
        $host = $connection['host'] ?? null;
        if (is_string($host) && $host !== '') {
            config(['database.connections.'.LegacyImportCommand::SOURCE_CONNECTION => $connection]);
        }
    }
}

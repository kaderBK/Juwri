<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * WI-R3 — lint the static-memo app-scope guard idiom.
 *
 * The memo-leak the auditor claimed was REFUTED: every store-keyed static memo in
 * core/app already clears itself under a per-class spl_object_id(app()) stamp. What
 * is NOT enforced is the idiom's SHAPE — nothing fails CI when a new store-keyed
 * static memo is added to a class with no stamp (the exact 2026-07-31 regression:
 * POS totals came out halved, 24 -> 12, in an unrelated test class), or when a new
 * memo is added to an already-guarded class but forgotten in scopeMemo().
 *
 * This scans SOURCE TEXT, not reflection: a static declared in a trait is reported
 * by ReflectionProperty::getDeclaringClass() against the *using* class, so a
 * reflection walk would miss the trait file and double-count the users. A file scan
 * sees each declaration exactly once, in the file that owns it.
 *
 * inspectSource() is pure (text in, findings out) so the tests can feed it
 * synthetic sources.
 */
final class StaticMemoGuardLint
{
    /** Class-body static declarations only. The visibility keyword is the discriminator:
     *  a FUNCTION-level `static $cache = [];` carries no private/protected/public, so this
     *  regex excludes function statics by construction (they die with the request anyway). */
    public const DECL = '/^\s+(?:private|protected|public)\s+static\s+(?:\??[A-Za-z_\\\\|]+\s+)?\$(\w+)\s*=/m';

    /**
     * FQCNs that legitimately hold a class-body static that is NOT a store-keyed memo,
     * each with the reason it must not be app-scope-rebound. Verified 2026-08-05.
     *
     * @var array<string,string>
     */
    public const ALLOWLIST = [
        'Juwri\\Modules\\Storefront\\Services\\Rendering\\ThemeOutputSanitizer' => 'per-render stack of held platform-tag HTML (begin/finish/abort around one Liquid render, P6-263), emptied after every render, not DB rows',
        'Juwri\\Modules\\Integrations\\Jobs\\DeliverWebhookJob' => 'test seam: a fake HTTP transport closure set by tests (P6-223), not DB rows',
        'Juwri\\Core\\Support\\ModuleFactories' => 'lazy index of factory class names by model short name, not DB rows (P1-11)',
        'Juwri\\Core\\Support\\ModuleSeeders' => 'registry of module seeder classes, filled by providers at boot (P1-11), not DB rows',
        'Juwri\\Core\\Support\\LocalizedRoutes' => 'locale codes the routes were registered for, fixed for the life of the process by design (routes are built once at boot under Octane, P2-16b), not DB rows',
        'Juwri\\Core\\Support\\ModuleRoutes' => 'registry of route files per module slot, filled by providers at boot (P1-09), not DB rows',
        'Juwri\\Core\\Http\\Hosts\\HostMap' => 'boot-time registries of module callables keyed by name (extra storefront hosts, store-host resolvers, pending grace; J52b M8), not DB rows: each closure re-reads config / the database per call, and the registering provider calls forgetModuleSources() at every boot, so no registration outlives its application',
        'Juwri\\Core\\Support\\TrustedHostSources' => 'boot-time registry of host sources keyed by name (P1-08a), not DB rows',
        'Juwri\\Core\\Http\\Middleware\\SetTenantContext' => 'boot-time registry of non-tenant gate middleware class names, filled by ee providers (BL-043), not DB rows',
        'Juwri\\Core\\Services\\Tenancy\\TenantJobClasses' => 'boot-time registry of queued class names modules classify (P1-08b), not DB rows',
        'Juwri\\Core\\Services\\Tenancy\\StoreDataRegistry' => 'boot-time registry of module store-data declarations keyed by module / table name (P1-21), not DB rows',
        'Juwri\\Core\\Support\\ModuleMap' => 'memo of the merged module map: read from dependencies.php and the installed edition manifests (files), never from database rows (P1-13 13i)',
        'Juwri\\Core\\Editions\\EditionPackages' => 'memo of the installed edition packages: read from Composer metadata and their manifest files, never from database rows (P1-13 13i)',
        'Juwri\\Core\\Support\\ScheduleSlots' => 'boot-time registry of console schedule definitions per Kernel slot, filled by ee providers (P1-13), not DB rows',
        'Juwri\\Core\\Editions\\Limits\\StoreLimitGuard' => 'depth counter of the seeder bypass (db:seed / StoreLimitGuard::bypass), not DB rows',
        'Juwri\\Core\\Services\\SsrfGuard' => 'test-only DNS resolver seam (P6-68), null in production and reset in TestCase::tearDown, not DB rows',
        'Juwri\\Core\\Support\\Hooks' => 'boot-time registry of callables per named slot, filled by ee providers (P1-13), not DB rows',
        'Juwri\\Core\\Http\\Middleware\\EditionSlot' => 'boot-time registry of middleware classes per Kernel slot, filled by ee providers (P1-13), not DB rows',
        'Juwri\\Core\\Http\\Middleware\\EnforceHostSplit' => 'boot-time list of ee middleware classes that mark dashboard routes, filled by ee providers (P1-13 13f), not DB rows',
        'Juwri\\Core\\Providers\\QueueRoutingServiceProvider' => 'boot-time map of ee job classes to queue names, filled by ee providers (P1-13 13f), not DB rows',
        'App\\Services\\Backup\\StoreBackupService' => 'its only static is the boot-time registry of ee-owned backup tables (extend(), P1-13), not DB rows',
        'Juwri\\Core\\Settings\\SettingsVersion' => 'monotonic counter of store-settings changes that derived memos (StoreOption) compare with; must survive app resets so a stamp is never reused, never a memo of data',
        'Juwri\\Core\\Support\\RequestEpoch' => 'the counter that IS the per-request stamp the other memos compare with (P2 item 16): bumped by the Octane listener, never a memo of data',
        'Juwri\\Core\\Services\\Tenancy\\TenantDatabaseSession' => 'weak maps keyed by the connection / PDO object: their lifetime is the connection\'s (a persistent Octane connection keeps its hook), and the tenant state is reconciled before every query, so no request id is involved (P2 item 16)',
        'Juwri\\Core\\Services\\Tenancy\\TenantTables' => 'memo of the Community table list merged with the installed edition packages\' tenant_tables files (P1-23): read from files, never from database rows; EditionPackages::fake() calls flush()',
        'Juwri\\Core\\Services\\Tenancy\\TenantTablesGenerator' => 'no memo of its own: the "static $merged" the lint sees is template text of the generated TenantTables.php it writes (P1-23)',
        'Juwri\\Core\\Legal\\CookieRegistry' => 'memo of the installed edition packages\' cookie lists (P1-23): read from manifest files, never from database rows; EditionPackages::fake() calls flush()',
        'Juwri\\Core\\Pci\\PaymentPageProbes' => 'boot-time registry of page-key => renderer closures filled by service providers (P1-12 PCI 11.6.1), not DB rows; a rebind would discard it on the first post-boot read',
        'Juwri\\Core\\Pci\\PaymentScriptInventory' => 'memo of the installed edition packages\' payment_scripts files (P1-12 PCI): read from manifest files, never from database rows; EditionPackages::fake() calls flush()',
        'Juwri\\Core\\Support\\LegacyClassAliases' => 'the generated old-name -> class map read from disk (P1-08); no database id ever enters it',
        'Juwri\\Modules\\Hotel\\Services\\Hotel\\Pms\\PmsManager' => 'driver registry keyed by provider name, filled by a service provider at boot; a rebind would discard it on the first post-boot read',
        'Juwri\\Modules\\Channels\\Services\\PosBridge\\PosBridgeRegistry' => 'boot-time driver registry keyed by provider name',
        'Juwri\\Modules\\Messaging\\Services\\WhatsApp\\WhatsAppProviderFactory' => 'boot-time driver registry keyed by provider name',
        'Juwri\\Modules\\Delivery\\Services\\Couriers\\CourierRegistry' => 'boot-time driver registry keyed by provider name',
        'Juwri\\Modules\\Channels\\Services\\Aggregators\\AggregatorRegistry' => 'boot-time driver registry keyed by provider name',
        'Juwri\\Modules\\Agents\\Services\\Telephony\\TelephonyRegistry' => 'boot-time driver registry keyed by provider name',
        'Juwri\\Modules\\Accounting\\Services\\Accounting\\Gl\\PostingRules' => 'callable registry keyed by rule name (register()), not DB rows',
        'Juwri\\Modules\\Storefront\\Services\\Theme\\FontPairs' => 'disk/config data',
        'Juwri\\Modules\\Content\\Services\\LockedTemplates\\Icons' => 'disk data: the icon library JSON, re-read when its mtime changes',
        'Juwri\\Modules\\Content\\Services\\LockedTemplates\\Renderer' => 'QR images keyed by the site URL string; no database id ever enters it',
        'Juwri\\Modules\\Storefront\\Services\\Theme\\ThemeKeys' => 'disk/config data',
        'Juwri\\Modules\\Storefront\\Services\\Theme\\SchemaV2' => 'disk/config data',
        'App\\Services\\ThemeCustomizerService' => 'customizer schema, not DB rows',
        'Juwri\\Modules\\Fiscal\\Services\\FiscalRegistry' => 'keyed by ISO country code (for() resolves a store to a country first), holds profile objects; no store id ever enters it',
        'Juwri\\Modules\\Content\\Services\\DevDocs\\DocsCorpus' => 'keyed by corpus path, reads resource_path(docs/dev) from disk',
        'App\\Models\\Concerns\\SyncsAggregatorAvailability' => 'a bool suppression flag for a withoutX() block, not a memo',
        'Juwri\\Modules\\Menu\\Models\\Concerns\\FiresMenuWebhook' => 'a bool suppression flag for a withoutX() block, not a memo',
        'Juwri\\Modules\\Payments\\Services\\StorePayments' => 'a settle-handler registry keyed by transaction method (registered by module providers at boot); no store id ever enters it',
        'Juwri\\Modules\\Storefront\\Services\\ViewModel\\ViewModelJson' => 'the kit view-model JSON schema read from a file next to the class; no store id ever enters it',
        'Juwri\\Core\\Support\\TablerIcons' => 'disk data: icon SVG bodies keyed by icon file path, no store or database id enters it (P6-36)',
        'Juwri\\Core\\Money\\Concerns\\HasMinorMoney' => 'P6-49: the casts the trait adds, keyed by model class name; derived from a class-level constant spec, so no store or database id ever enters it',
    ];

    /** In-memory test doubles whose statics ARE the fixture; each test resets them. */
    public const ALLOWLIST_PATTERN = '/^(App|Juwri\\\\Modules\\\\[A-Za-z]+)\\\\Services\\\\.*\\\\Fake[A-Za-z]+Client$/';

    private const STAMP = '/^(memoApp|cacheApp)$/';

    /**
     * @return list<array{kind:string, file:string, name?:string, reason?:string}>
     */
    public static function inspectSource(string $relPath, string $source): array
    {
        if (! preg_match_all(self::DECL, $source, $m)) {
            return [];
        }
        $names = $m[1];

        $fqcn = self::fqcn($source);
        if ($fqcn !== null && self::isAllowlisted($fqcn)) {
            return [[
                'kind' => 'skipped',
                'file' => $relPath,
                'reason' => self::ALLOWLIST[$fqcn] ?? 'Fake*Client test double',
            ]];
        }

        $memos = array_values(array_filter($names, fn ($n) => ! preg_match(self::STAMP, $n)));
        if ($memos === []) {
            return [];
        }

        if (! self::hasStamp($source)) {
            return [[
                'kind' => 'missing-stamp',
                'file' => $relPath,
                'name' => implode(', ', $memos),
                'reason' => $relPath.' declares static memo(s) ['.implode(', ', $memos)
                    .'] but carries no RequestEpoch::id() (or spl_object_id(app())) guard',
            ]];
        }

        $guardText = self::guardText($source);
        $findings = [];
        foreach ($memos as $name) {
            if (! self::isReset($guardText, $name)) {
                $findings[] = [
                    'kind' => 'unclear-sibling',
                    'file' => $relPath,
                    'name' => $name,
                    'reason' => $relPath.'::$'.$name.' is not reset by the app-scope guard'
                        .' — add it to scopeMemo() (or to the flush() the guard calls)',
                ];
            }
        }

        return $findings;
    }

    /**
     * @return list<array{kind:string, file:string, name?:string, reason?:string}>
     */
    public static function scanApp(): array
    {
        $root = app_path();
        $findings = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $rel = 'app/'.ltrim(str_replace($root, '', $file->getPathname()), '/');
            $found = self::inspectSource($rel, (string) file_get_contents($file->getPathname()));
            foreach ($found as $f) {
                if (($f['kind'] ?? '') === 'skipped') {
                    continue;
                }
                $findings[] = $f;
            }
        }

        return $findings;
    }

    private static function fqcn(string $source): ?string
    {
        $ns = null;
        if (preg_match('/^namespace\s+([^;]+);/m', $source, $m)) {
            $ns = trim($m[1]);
        }
        if (! preg_match('/^(?:final\s+|abstract\s+)*(?:class|trait|enum)\s+(\w+)/m', $source, $m)) {
            return null;
        }

        return $ns === null ? $m[1] : $ns.'\\'.$m[1];
    }

    private static function isAllowlisted(string $fqcn): bool
    {
        return array_key_exists($fqcn, self::ALLOWLIST)
            || preg_match(self::ALLOWLIST_PATTERN, $fqcn) === 1;
    }

    /**
     * The body of the method holding spl_object_id(app()), plus the bodies of any
     * self::x()/static::x() methods it calls (one hop) — so a guard that delegates
     * its resets to flush()/forget() resolves correctly.
     */
    private static function guardText(string $source): string
    {
        $bodies = self::methodBodies($source);
        $guardName = array_find_key($bodies, fn ($body) => self::hasStamp($body));
        if ($guardName === null) {
            return $source; // fall back to the whole file rather than under-report
        }

        $text = $bodies[$guardName];
        if (preg_match_all('/(?:self|static)::(\w+)\s*\(/', $text, $m)) {
            foreach (array_unique($m[1]) as $callee) {
                if (isset($bodies[$callee])) {
                    $text .= "\n".$bodies[$callee];
                }
            }
        }

        return $text;
    }

    /**
     * @return array<string,string> method name => body text (between its braces)
     */
    private static function methodBodies(string $source): array
    {
        $bodies = [];
        if (! preg_match_all('/function\s+(\w+)\s*\([^)]*\)[^{;]*\{/', $source, $m, PREG_OFFSET_CAPTURE)) {
            return $bodies;
        }
        foreach ($m[0] as $i => $match) {
            $name = $m[1][$i][0];
            $bracePos = $match[1] + strlen($match[0]) - 1; // position of the opening {
            $bodies[$name] = self::braceBody($source, $bracePos);
        }

        return $bodies;
    }

    private static function braceBody(string $source, int $openBrace): string
    {
        $depth = 0;
        $len = strlen($source);
        for ($i = $openBrace; $i < $len; $i++) {
            $c = $source[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $openBrace, $i - $openBrace + 1);
                }
            }
        }

        return substr($source, $openBrace);
    }

    /** The guard stamp: the application id (tests, php-fpm) or its Octane-safe successor RequestEpoch::id(). */
    private static function hasStamp(string $text): bool
    {
        return str_contains($text, 'spl_object_id(app())') || str_contains($text, 'RequestEpoch::id()');
    }

    private static function isReset(string $guardText, string $name): bool
    {
        return str_contains($guardText, 'self::$'.$name.' =')
            || str_contains($guardText, 'static::$'.$name.' =');
    }
}

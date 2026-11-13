<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Legal;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

/**
 * P1-23: the published legal documents (config/legal.php `documents`) — keys, versions with effective dates,
 * paths and labels. A version whose effective date has come is in force; a later one is an announced change
 * (Terms §14.2, 30 days' notice): users see it and can accept it early, nobody is locked out.
 */
final class LegalDocuments
{
    /** JUWRI LTD's documents are published on this instance (LEGAL_PAGES). */
    public static function enabled(): bool
    {
        return (bool) config('legal.enabled', false);
    }

    /**
     * JUWRI LTD's documents apply to the visitor of this request: published here and not on a reseller's white-label
     * host (its customers contract with the reseller, Terms §14.1). Links to them, the company line and the legal
     * nav only render when this is true.
     */
    public static function publishedHere(): bool
    {
        return self::enabled() && ! (function_exists('active_brand_reseller') && active_brand_reseller());
    }

    /**
     * RB C5: in production a document that still has an unresolved {{FILL}} / {{VERIFY}} marker is hidden (404, no footer
     * link) instead of served with a placeholder, unless LEGAL_HIDE_UNFILLED=false (then the controller answers 503).
     * Outside production the marks stay visible so the owner can see what is missing.
     */
    public static function hiddenAsUnfilled(string $key): bool
    {
        if (! config('legal.hide_unfilled', true) || ! app()->isProduction() || ! self::has($key)) {
            return false;
        }

        return LegalPage::load($key, LegalPage::textLocale($key, app()->getLocale()))->unresolved !== [];
    }

    /** @return array<string, array{path: string, label: string, acceptance: bool, versions: array<string, string|null>}> */
    public static function all(): array
    {
        $out = [];
        foreach ((array) config('legal.documents', []) as $key => $doc) {
            $versions = (array) ($doc['versions'] ?? []);
            if ($versions === []) {
                continue;
            }
            $out[(string) $key] = [
                'path' => trim((string) ($doc['path'] ?? $key), '/'),
                'label' => (string) ($doc['label'] ?? 'site.footer.'.$key),
                'acceptance' => (bool) ($doc['acceptance'] ?? false),
                'versions' => array_map('strval', $versions),
            ];
        }

        return $out;
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /**
     * Documents a user accepts at sign-up (Terms, DPA, AUP).
     *
     * @return list<string>
     */
    public static function acceptanceKeys(): array
    {
        return array_keys(array_filter(self::all(), fn (array $d) => $d['acceptance']));
    }

    /** The version in force on $at (default now): the last one whose effective date has come, else the first. A null date (not yet set) never comes. */
    public static function currentVersion(string $key, ?CarbonImmutable $at = null): string
    {
        $versions = self::all()[$key]['versions'] ?? [];
        $at ??= CarbonImmutable::now();
        $current = (string) array_key_first($versions);
        foreach ($versions as $version => $date) {
            if ($date !== null && $date !== '' && self::date($date)->lessThanOrEqualTo($at)) {
                $current = (string) $version;
            }
        }

        return $current;
    }

    /** The newest version, in force or announced. */
    public static function latestVersion(string $key): string
    {
        $versions = self::all()[$key]['versions'] ?? [];

        return (string) array_key_last($versions);
    }

    public static function effectiveDate(string $key, string $version): ?CarbonImmutable
    {
        $date = self::all()[$key]['versions'][$version] ?? null;

        return $date === null || $date === '' ? null : self::date($date);
    }

    /**
     * A version is a material change unless config lists it in the document's `non_material` (Terms 14.2: material
     * changes need 30 days' notice; {@see TermsChangeNotice}).
     */
    public static function material(string $key, string $version): bool
    {
        $nonMaterial = array_map('strval', (array) config('legal.documents.'.$key.'.non_material', []));

        return ! in_array($version, $nonMaterial, true);
    }

    public static function routeName(string $key): string
    {
        return 'legal.'.$key;
    }

    public static function url(string $key): string
    {
        $route = self::routeName($key);

        return Route::has($route) ? route($route) : url('/'.(self::all()[$key]['path'] ?? $key));
    }

    public static function label(string $key): string
    {
        return (string) __(self::all()[$key]['label'] ?? 'site.footer.'.$key);
    }

    private static function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'UTC')->startOfDay();
    }
}

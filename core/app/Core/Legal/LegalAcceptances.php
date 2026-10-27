<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Legal;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\LegalAcceptance;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Tenancy\TenantContext;

/**
 * P1-23: records and answers "which legal document versions has this user accepted" (Terms §14.13 electronic
 * acceptance; §14.2 changes with 30 days' notice). A record is never updated or removed here.
 */
final class LegalAcceptances
{
    /**
     * Record the user's acceptance of $keys (default: every sign-up document) at the version in force — or, from
     * the change banner, at the newest announced version.
     *
     * @param  list<string>|null  $keys
     * @return int rows written (an already recorded version is skipped)
     */
    public static function record(User $user, string $source, ?Request $request = null, ?array $keys = null, bool $latest = false): int
    {
        if (! LegalDocuments::publishedHere() || ! in_array($source, LegalAcceptance::SOURCES, true)) {
            return 0;
        }
        $written = 0;
        foreach ($keys ?? LegalDocuments::acceptanceKeys() as $key) {
            if (! LegalDocuments::has($key)) {
                continue;
            }
            $version = $latest ? LegalDocuments::latestVersion($key) : LegalDocuments::currentVersion($key);
            $row = TenantContext::bypass(fn () => LegalAcceptance::query()->firstOrCreate(
                ['user_id' => (int) $user->getKey(), 'document' => $key, 'version' => $version],
                [
                    'source' => $source,
                    'accepted_at' => now(),
                    'ip' => $request?->ip(),
                    'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
                ],
            ));
            if ($row->wasRecentlyCreated) {
                $written++;
                Log::info('legal.accepted', ['user_id' => (int) $user->getKey(), 'document' => $key, 'version' => $version, 'source' => $source]);
            }
        }

        return $written;
    }

    /**
     * Record the user's acceptance of ONE document at the version in force — a click-through that is not part of
     * sign-up (e.g. the Free Reseller Terms, accepted in the reseller portal, which may run on the reseller's own host).
     * Needs only the published documents (LEGAL_PAGES), not a JUWRI-branded host. True when a row was written.
     */
    public static function recordDocument(User $user, string $key, string $source, ?Request $request = null): bool
    {
        if (! LegalDocuments::enabled() || ! LegalDocuments::has($key) || ! in_array($source, LegalAcceptance::SOURCES, true)) {
            return false;
        }
        $version = LegalDocuments::currentVersion($key);
        $row = TenantContext::bypass(fn () => LegalAcceptance::query()->firstOrCreate(
            ['user_id' => (int) $user->getKey(), 'document' => $key, 'version' => $version],
            [
                'source' => $source,
                'accepted_at' => now(),
                'ip' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            ],
        ));
        if ($row->wasRecentlyCreated) {
            Log::info('legal.accepted', ['user_id' => (int) $user->getKey(), 'document' => $key, 'version' => $version, 'source' => $source]);
        }

        return $row->wasRecentlyCreated;
    }

    /**
     * Sign-up documents whose newest version (in force or announced) the user has not accepted yet. Platform
     * administrators are never asked (they are not a customer under the Terms).
     *
     * @return list<array{document: string, version: string, effective: CarbonImmutable|null, upcoming: bool}>
     */
    public static function pending(User $user): array
    {
        if (! LegalDocuments::publishedHere() || $user->isAdmin()) {
            return [];
        }
        $keys = LegalDocuments::acceptanceKeys();
        $accepted = TenantContext::bypass(fn () => LegalAcceptance::query()
            ->where('user_id', (int) $user->getKey())
            ->whereIn('document', $keys)
            ->get(['document', 'version'])
            ->map(fn (LegalAcceptance $a) => $a->document.'@'.$a->version)
            ->all());
        $out = [];
        foreach ($keys as $key) {
            $version = LegalDocuments::latestVersion($key);
            if (in_array($key.'@'.$version, $accepted, true)) {
                continue;
            }
            $effective = LegalDocuments::effectiveDate($key, $version);
            $out[] = [
                'document' => $key,
                'version' => $version,
                'effective' => $effective,
                'upcoming' => $effective !== null && $effective->isFuture(),
            ];
        }

        return $out;
    }

    /** Accept everything pending (the banner's button); returns the rows written. */
    public static function acceptPending(User $user, ?Request $request = null): int
    {
        $keys = array_column(self::pending($user), 'document');

        return $keys === [] ? 0 : self::record($user, 'banner', $request, $keys, true);
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Biolink\Support;

use Juwri\Modules\Biolink\Models\BioClick;
use Illuminate\Http\Request;

/**
 * bio-t15 Lane D — the single, PII-free deriver for everything a `bio_clicks`
 * row buckets on (SPEC §7). `capture(Request): self` is self-contained on
 * purpose: it does not read or write anything, so a caller (F0, at cross-lane
 * integration) can drop it into BOTH the page-view AND click-beacon record
 * paths and get identical source/device classification to what
 * BioPublicController already does inline today (the `source`/`device` logic
 * below is a byte-for-byte port of its classifySource()/classifyDevice()/
 * SOCIAL_HOSTS, so wiring this in changes NOTHING for those two dimensions —
 * only ADDS `country`/`ref`).
 *
 * PRIVACY (do not weaken):
 *  - `country` comes ONLY from the platform's existing geo seam,
 *    `visitor_country()` (autoloaded from app/Http/Helpers/GeoHelper.php — a
 *    trusted-proxy Cloudflare `CF-IPCountry` header or a local GeoLite2 mmdb,
 *    BOTH dark by default). This class never touches `$request->ip()` itself,
 *    never calls an external geo API, and never stores anything but the
 *    resulting 2-letter code (or the `'--'` sentinel when geo is dark/absent).
 *  - `ref` is a referrer HOST bucket only — lowercased, matched against a
 *    small allowlist of known platforms, collapsed to `'other'` outside it.
 *    Never a path, query string, or the full referrer URL.
 *
 * NOT YET WIRED into the record call sites (BioPublicController::show()/
 * click(), a Lane B file) or into BioLinkService::upsertCount()'s grain (a
 * Lane A/B file) — both are outside Lane D's fence. See LEDGER
 * "§ Contract changes" for exactly what F0 needs to change at integration.
 */
class BioClickContext
{
    /**
     * Referrer hosts (or host suffixes) we recognise, mapped to the canonical
     * bucket value stored in `ref`. A `www.` prefix is stripped before
     * matching. Anything with a referrer that does NOT match one of these is
     * bucketed to BioClick::REF_OTHER — never stored as the raw arbitrary
     * host — so an unusual/identifying referrer domain (a private intranet, a
     * personal blog) never lands in the table (SPEC §7 "small allowlist of
     * known hosts + other").
     */
    private const REF_HOSTS = [
        'instagram.com', 'tiktok.com', 'facebook.com', 'fb.com', 'x.com', 'twitter.com', 't.co',
        'snapchat.com', 'youtube.com', 'youtu.be', 'linkedin.com', 'pinterest.com',
        'threads.net', 'whatsapp.com', 'wa.me', 'reddit.com',
        'google.com', 'bing.com', 'yahoo.com', 'duckduckgo.com',
    ];

    /** Referrer hosts we bucket as 'social' (mirrors BioPublicController::SOCIAL_HOSTS byte-for-byte). */
    private const SOCIAL_HOSTS = [
        'instagram', 'l.instagram', 'tiktok', 'facebook', 'fb.', 'm.facebook',
        'twitter', 'x.com', 't.co', 'snapchat', 'youtube', 'youtu.be',
        'linkedin', 'pinterest', 'threads', 'wa.me', 'whatsapp',
    ];

    public function __construct(
        public readonly string $source,
        public readonly string $device,
        public readonly string $country,
        public readonly string $ref,
    ) {
    }

    /** Derive every bucket a bio_clicks row needs from one Request. Pure — no I/O beyond the geo seam. */
    public static function capture(Request $request): self
    {
        return new self(
            source: self::classifySource($request),
            device: self::classifyDevice($request),
            country: self::classifyCountry(),
            ref: self::classifyReferrer($request),
        );
    }

    /**
     * G5 traffic-source classification — explicit `?src`, any `?qr-id` (a QR
     * scan), else the referrer host → social/direct/other. Identical rule set
     * to BioPublicController::classifySource().
     */
    public static function classifySource(Request $request): string
    {
        $src = strtolower((string) $request->query('src', ''));
        if (in_array($src, BioClick::SOURCES, true)) {
            return $src;
        }

        if ($request->query('qr-id') !== null) {
            return 'qr';
        }

        $ref = (string) $request->headers->get('referer', '');
        if ($ref === '') {
            return 'direct';
        }

        $host = strtolower((string) parse_url($ref, PHP_URL_HOST));
        if ($host === '') {
            return 'direct';
        }

        foreach (self::SOCIAL_HOSTS as $needle) {
            if (str_contains($host, $needle)) {
                return 'social';
            }
        }

        return str_contains($host, (string) parse_url(config('app.url'), PHP_URL_HOST)) ? 'direct' : 'other';
    }

    /** Coarse device bucket from the User-Agent. Identical rule set to BioPublicController::classifyDevice(). */
    public static function classifyDevice(Request $request): string
    {
        $ua = strtolower((string) $request->userAgent());
        if ($ua === '') {
            return 'other';
        }
        if (str_contains($ua, 'ipad') || (str_contains($ua, 'tablet') && ! str_contains($ua, 'mobile'))) {
            return 'tablet';
        }
        if (str_contains($ua, 'mobi') || str_contains($ua, 'iphone') || str_contains($ua, 'android')) {
            return 'mobile';
        }

        return 'desktop';
    }

    /**
     * D1: the coarse 2-letter country bucket, or BioClick::COUNTRY_UNKNOWN
     * when the platform's geo seam is dark/absent/unrecognised. Never touches
     * the IP directly — delegates entirely to the existing `visitor_country()`
     * helper (app/Http/Helpers/GeoHelper.php).
     */
    public static function classifyCountry(): string
    {
        if (! function_exists('visitor_country')) {
            return BioClick::COUNTRY_UNKNOWN;
        }

        try {
            $code = visitor_country();
        } catch (\Throwable $e) {
            return BioClick::COUNTRY_UNKNOWN;
        }

        if (! is_string($code) || strlen($code) !== 2) {
            return BioClick::COUNTRY_UNKNOWN;
        }

        return strtoupper($code);
    }

    /**
     * D2: the referrer HOST bucket — lowercased, `www.` stripped, matched
     * against REF_HOSTS (a subdomain like `l.instagram.com` still buckets to
     * `instagram.com`). No referrer at all → BioClick::REF_NONE (''). A
     * referrer present but not recognised → BioClick::REF_OTHER. Never a
     * path, query string, or the full URL.
     */
    public static function classifyReferrer(Request $request): string
    {
        $raw = (string) $request->headers->get('referer', '');
        if ($raw === '') {
            return BioClick::REF_NONE;
        }

        $host = strtolower((string) (parse_url($raw, PHP_URL_HOST) ?: ''));
        if ($host === '') {
            return BioClick::REF_NONE;
        }

        $host = preg_replace('/^www\./', '', $host) ?? $host;

        foreach (self::REF_HOSTS as $known) {
            if ($host === $known || str_ends_with($host, '.'.$known)) {
                return $known;
            }
        }

        return BioClick::REF_OTHER;
    }
}

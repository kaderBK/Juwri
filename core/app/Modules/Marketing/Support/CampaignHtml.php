<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Marketing\Support;

use Juwri\Core\Support\Html;

/**
 * P6-286: allowlist sanitiser for marketing campaign e-mail bodies.
 *
 * The body is mailed from the platform's domain to every customer of the store,
 * so it gets the strict `campaign` HTMLPurifier profile (config/purify.php):
 * formatting, http/https/mailto/tel links and images only — no forms, frames,
 * scripts, styles or on* handlers. On top of that, an <img> survives only when it
 * is served from this platform's own media hosts (app URL, public disk, S3/CDN
 * URL): remote images are open/IP trackers and phishing props.
 *
 * Applied when the campaign is saved AND again at send time (rows saved before
 * P6-286 were stored raw).
 */
final class CampaignHtml
{
    public static function clean(?string $html): string
    {
        $clean = Html::clean($html, 'campaign');
        if ($clean === '' || stripos($clean, '<img') === false) {
            return $clean;
        }

        $hosts = self::mediaHosts();

        // HTMLPurifier output is normalised (double-quoted, entity-encoded attributes).
        return (string) preg_replace_callback('/<img\b[^>]*>/i', function (array $m) use ($hosts): string {
            if (preg_match('/\ssrc="([^"]*)"/i', $m[0], $src) !== 1) {
                return '';
            }

            return self::ownMedia(html_entity_decode($src[1], ENT_QUOTES | ENT_HTML5), $hosts) ? $m[0] : '';
        }, $clean);
    }

    /** @param  list<string>  $hosts */
    private static function ownMedia(string $src, array $hosts): bool
    {
        $parts = parse_url(trim($src));
        if ($parts === false) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            return false; // relative or scheme-less URLs do not render in a mail client anyway
        }

        return in_array(strtolower((string) ($parts['host'] ?? '')), $hosts, true);
    }

    /** @return list<string> */
    private static function mediaHosts(): array
    {
        $urls = [
            config('app.url'),
            config('filesystems.disks.public.url'),
            config('filesystems.disks.s3.url'),
            config('app.asset_url'),
        ];
        $hosts = [];
        foreach ($urls as $url) {
            $host = is_string($url) ? strtolower((string) parse_url($url, PHP_URL_HOST)) : '';
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }
}

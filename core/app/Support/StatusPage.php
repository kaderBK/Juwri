<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Support;

/**
 * P6-114: optional link to an external status page. Provider-neutral: any http(s) URL (hosted service, self-hosted
 * Uptime Kuma, a static page). Unset or invalid means no link is shown anywhere.
 */
final class StatusPage
{
    public static function url(): ?string
    {
        $url = trim((string) config('appinfo.status_page_url', ''));
        if ($url === '' || ! preg_match('#^https?://[^\s<>"\']+$#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $url;
    }
}

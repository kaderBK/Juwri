<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Legal;

/**
 * J71 (O1): the address of the repository's GitHub private vulnerability reporting page, from
 * `legal.security_report_url` (env LEGAL_SECURITY_REPORT_URL). It is printed verbatim in the legal pages and in
 * security.txt, so anything that is not a plain https URL is treated as unset.
 */
final class SecurityReport
{
    public static function url(): ?string
    {
        $url = trim((string) config('legal.security_report_url', ''));

        return $url !== '' && preg_match('~^https://[^\s<>"\'()]+$~', $url) === 1 ? $url : null;
    }
}

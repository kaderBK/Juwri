<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services;

use Illuminate\Support\Facades\Cache;
use Juwri\Core\Support\ClientNetwork;

/**
 * QR scans are counted at most once per client network (IPv4 address / IPv6 /64) per store per
 * config abuse.scan_dedupe_seconds (default 30). A qr-id is not secret (anyone can build one for any slug), so without
 * this a script could burn a store's monthly scan cap. Shared by the core page's own scan and the migration
 * compatibility module, so one visitor is one scan whichever code format they scanned. Default cache store; a
 * multi-node install needs a shared store.
 */
final class ScanDedupe
{
    /** True for the first scan in the window (count it); false for a repeat (redirect without counting). */
    public static function first(int $storeId): bool
    {
        $seconds = max(1, (int) config('abuse.scan_dedupe_seconds', 30));

        return Cache::add('qr-scan-dedupe|'.$storeId.'|'.ClientNetwork::bucket(), 1, $seconds);
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// AL-40 (auto-locale v1) — geo source configuration. Ships DARK: with neither key
// set, visitor_country() returns null and every geo-aware feature (AL-20 currency
// default) stays off. The owner provisions ONE source per install:
//   • trust_cf_country=true only when the site sits behind Cloudflare AND
//     TRUSTED_PROXIES lists CF ranges (so the CF-IPCountry header cannot be spoofed
//     by a direct client);
//   • mmdb_path pointing at a local MaxMind GeoLite2-Country.mmdb (read via the
//     already-installed geoip2/geoip2). The mmdb is NEVER bundled in the sellable
//     codebase — it is a per-install file.
// ip-api.com (user_ip_lookup) is deliberately NOT a source here: it sends the
// visitor's raw IP to a third party on an anonymous path.

return [
    'trust_cf_country' => filter_var(env('GEOIP_TRUST_CF_COUNTRY', false), FILTER_VALIDATE_BOOLEAN),
    'mmdb_path' => env('GEOIP_MMDB_PATH'),
];

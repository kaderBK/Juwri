<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Domains;


/**
 * Cloudflare-for-SaaS custom-hostname provisioner (RP3 driver seam).
 *
 * DEFERRED (per RUN-ALL): the real Cloudflare API call — POST /zones/{zone}/custom_hostnames
 * with the domain + ssl.method=http — is not wired this run (needs the platform's CF API
 * token + zone id). The driver + config switch are in place; provision() returns
 * 'provisioning' so the flow completes and the domain shows as pending-cert until the
 * live call is added. Enable with config('settings.reseller_domain_driver') = 'cloudflare'.
 *
 * To go live: call the CF custom_hostnames endpoint, then poll/verify ssl.status and map
 * it to 'active' | 'provisioning' | 'failed'.
 */
class CloudflareDomainProvisioner implements DomainProvisioner
{
    public function cnameTarget(): string
    {
        // The fallback origin / CNAME target for CF-for-SaaS (a *.saas host).
        return (string) config('settings.reseller_cname_target', 'cname.' . parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    public function provision(string $domain): string
    {
        // LIVE CF API CALL DEFERRED — see class docblock.
        return 'provisioning';
    }
}

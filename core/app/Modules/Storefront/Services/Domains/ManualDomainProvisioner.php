<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Domains;


/**
 * Manual (no-API) provisioner: gives the reseller a CNAME target + instructions and
 * marks the domain 'manual' — a human/ops step issues the cert (or Caddy on-demand
 * TLS handles it at request time). No external DNS/CF call. This is the only driver
 * wired this run; a Cloudflare-for-SaaS driver implementing DomainProvisioner is the
 * deferred live step.
 */
class ManualDomainProvisioner implements DomainProvisioner
{
    public function cnameTarget(): string
    {
        return (string) config('settings.reseller_cname_target', 'cname.' . parse_url(config('app.url'), PHP_URL_HOST));
    }

    public function provision(string $domain): string
    {
        // No API call — ops/Caddy-on-demand handles the cert. Mark 'manual'.
        return 'manual';
    }
}

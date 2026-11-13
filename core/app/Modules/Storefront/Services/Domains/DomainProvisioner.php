<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Domains;

/**
 * Reseller P5: pluggable custom-domain provisioner. A real driver (Cloudflare for
 * SaaS, Caddy on-demand TLS) would issue certs via API; this interface lets that
 * swap in later. For this run only the manual (no-API) driver exists.
 */
interface DomainProvisioner
{
    /** The CNAME target the reseller points their domain at. */
    public function cnameTarget(): string;

    /**
     * Provision TLS for a verified domain. Returns an ssl_status
     * ('provisioning'|'active'|'failed'|'manual'). No real API call in the manual driver.
     */
    public function provision(string $domain): string;
}

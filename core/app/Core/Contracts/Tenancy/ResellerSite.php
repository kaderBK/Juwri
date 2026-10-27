<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Juwri\Core\Editions\Community\NoResellers;

/**
 * P1-13 extension point: the public site a reseller serves on its own domain (landing, block pages, contact,
 * sitemap). Only reached when {@see ResellerContext::forRequest()} found a reseller, so Community never calls it;
 * its default answers "nothing here" ({@see NoResellers}).
 */
interface ResellerSite
{
    /** The reseller's own home page for a guest, or null to fall through to the platform home. */
    public function home(Model $reseller): mixed;

    /** The reseller's own pricing page (R4/RP11; 404 when they turned it off). */
    public function pricing(Model $reseller): mixed;

    /** A reseller block page by slug (404 when absent). */
    public function page(Request $request, string $slug): mixed;

    /** The reseller's own blog index on its domain, optionally filtered by a tag (P1-13 13f: was BlogController → ResellerPublicController). */
    public function blogIndex(Request $request, ?string $tag = null): mixed;

    /** One of the reseller's own blog posts by slug (404 when absent). */
    public function blogShow(Request $request, string $slug): mixed;

    /** The reseller's contact page (404 without a support email). */
    public function contact(Model $reseller): mixed;

    /** Send the contact form to the reseller's support email. */
    public function contactSend(Request $request, Model $reseller): mixed;

    /**
     * The reseller's sitemap entries (same shape as SitemapService entries).
     *
     * @return list<array<string, mixed>>
     */
    public function sitemapEntries(Model $reseller): array;
}

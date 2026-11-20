<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Http\Controllers;

use Illuminate\Http\Response;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Http\Hosts\HostMap;

/**
 * P6-248: robots.txt is served per request. A static file advertised juwri.com's sitemap on every install and let
 * staging / self-hosted / reseller hosts be indexed. Production: crawlers welcome (rev/seo R7) with THIS host's
 * sitemap. Any other APP_ENV, or SEO_NOINDEX=true, gets Disallow: / plus X-Robots-Tag: noindex.
 */
class RobotsController extends Controller
{
    public function __invoke(): Response
    {
        if (self::noindex()) {
            return response("User-agent: *\nDisallow: /\n", 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
        }

        $body = "# All crawlers welcome, including AI search agents and AI training bots (rev/seo R7, owner decision 2026-07-07).\n"
            ."User-agent: *\n"
            ."Allow: /\n"
            ."Disallow: /cgi-bin/\n"
            ."Disallow: /dashboard\n"
            ."Disallow: /*/dashboard\n"
            ."Disallow: /admin\n"
            ."\n"
            .'Sitemap: '.self::sitemapUrl()."\n";

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** P6-307 / R8c: on a store or reseller host, the sitemap is that host's own. */
    private static function sitemapUrl(): string
    {
        $onStoreHost = (int) request()->attributes->get(HostMap::STORE_ATTRIBUTE, 0) > 0;
        $onResellerHost = app(ResellerContext::class)->forRequest() !== null;

        return ($onStoreHost || $onResellerHost)
            ? request()->getSchemeAndHttpHost().'/sitemap.xml'
            : route('sitemap');
    }

    public static function noindex(): bool
    {
        return ! app()->environment('production') || (bool) config('seo.noindex');
    }
}

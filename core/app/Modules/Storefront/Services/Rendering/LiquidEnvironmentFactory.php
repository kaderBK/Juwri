<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Rendering;

use Juwri\Modules\Storefront\Storefront\SnippetMap;
use Juwri\Modules\Storefront\Storefront\Tags\CommerceTag;
use Juwri\Modules\Storefront\Storefront\Tags\CustomBlockTag;
use Juwri\Modules\Storefront\Storefront\Tags\SchemaTag;
use Juwri\Modules\Storefront\Storefront\Tags\WidgetTag;
use Juwri\Modules\Storefront\Storefront\ThemeFilters;
use Keepsuit\Liquid\Environment;
use Keepsuit\Liquid\EnvironmentFactory;
use Keepsuit\Liquid\Render\ResourceLimits;

/**
 * P2-1: build hardened Liquid environments for untrusted theme rendering.
 *
 * On shared/cPanel hosting this language layer is the load-bearing security
 * boundary (see rev/platform-v2/decisions/render-isolation.md), so the config
 * is deliberately strict:
 *  - no filesystem — {% render %} cannot include arbitrary files (path traversal
 *    impossible by construction; a theme-scoped snippet loader is added in P2-2b).
 *  - ResourceLimits on every render (DoS cap).
 *  - upload mode is strict + rethrowing (catch every author mistake at submit);
 *    live mode is lenient (never 500 a diner's menu on a missing var).
 */
class LiquidEnvironmentFactory
{
    /**
     * Strict environment for upload/test-render: unknown var/filter = error.
     *
     * @param  array<string,string>  $snippets  theme-version snippet map (P2-6)
     */
    public function upload(array $snippets = []): Environment
    {
        return $this->base($snippets)
            ->setStrictVariables(true)
            ->setStrictFilters(true)
            ->setRethrowErrors(true)
            ->build();
    }

    /**
     * Lenient environment for live rendering: log misses, don't blow up the page.
     *
     * @param  array<string,string>  $snippets  theme-version snippet map (P2-6)
     */
    public function live(array $snippets = []): Environment
    {
        return $this->base($snippets)
            ->setStrictVariables(false)
            ->setStrictFilters(true)
            ->setRethrowErrors(false)
            ->build();
    }

    protected function base(array $snippets = []): EnvironmentFactory
    {
        // Default: no filesystem → no file includes for {% render %}. With a
        // snippet map, {% render 'name' %} resolves by pure key lookup
        // (\Juwri\Modules\Storefront\Storefront\SnippetMap) — path traversal impossible by
        // construction. Closed whitelist (P2-2b): only ThemeFilters' filters +
        // the standard safe set are callable under strictFilters.
        $factory = EnvironmentFactory::new()
            ->setResourceLimits($this->limits())
            ->registerFilters(ThemeFilters::class)
            ->registerTag(SchemaTag::class)
            ->registerTag(CommerceTag::class)
            // Theme Studio B0.7: allow-listed platform widgets (trusted Blade).
            ->registerTag(WidgetTag::class)
            // Theme Studio phase A: owner-authored Builder.js blocks (trusted render).
            ->registerTag(CustomBlockTag::class);

        if ($snippets !== []) {
            $factory->setFilesystem(new SnippetMap($snippets));
        }

        return $factory;
    }

    /** Fresh limits object per render (scores accumulate per render tree). */
    public function limits(): ResourceLimits
    {
        $c = config('platform.liquid');

        return new ResourceLimits(
            renderLengthLimit: $c['render_length_limit'] ?? null,
            renderScoreLimit: $c['render_score_limit'] ?? null,
            assignScoreLimit: $c['assign_score_limit'] ?? null,
            cumulativeRenderScoreLimit: $c['cumulative_render_score_limit'] ?? null,
            cumulativeAssignScoreLimit: $c['cumulative_assign_score_limit'] ?? null,
        );
    }
}

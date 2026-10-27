<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\Builder;

use Juwri\Core\Support\Html;

/**
 * The single render pipeline for Builder.js html-mode pages. Every public surface
 * (CMS / reseller / biolink) echoes builder HTML through here, in this fixed order:
 *
 *   1. Html::cleanPage()  — allowlist sanitise (also percent-encodes URL-attr braces,
 *                           keeps scoped <style>, marks curated forms/iframes).
 *   2. MergeTags::apply() — substitute live, escaped {{tokens}} (after cleanPage, so
 *                           the %7B%7B…%7D%7D URL form resolves — matches the email order).
 *   3. BuilderForms::prepare() — wire any curated <form> to the platform submit route
 *                           with a signed context + honeypot.
 */
class BuilderRender
{
    /**
     * @param  array<string,string>  $tokens  merge-tag map (see MergeTags::forX)
     * @param  array<string,mixed>  $formCtx  form binding (surface/id/to/label/url)
     */
    public static function page(?string $raw, array $tokens = [], array $formCtx = []): string
    {
        $html = MergeTags::apply(Html::cleanPage($raw), $tokens);

        return BuilderForms::prepare($html, $formCtx);
    }
}

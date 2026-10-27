<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Tags;

use Juwri\Modules\Storefront\Services\Rendering\ThemeOutputSanitizer;
use Keepsuit\Liquid\Parse\TagParseContext;
use Keepsuit\Liquid\Render\RenderContext;
use Keepsuit\Liquid\Tag;

/**
 * P2-8: `{% commerce %}` — emits the platform commerce runtime once per page:
 * the `window.MB_COMMERCE` config (server-built, never theme-forgeable — read
 * from a render register) plus the versioned `commerce.js` bundle, both carrying
 * the CSP nonce (P0-4). A theme drops this once in its layout and gets the whole
 * cart/checkout without writing any JavaScript.
 *
 * Idempotent: if a theme includes the tag more than once, only the first
 * emission is written (tracked via a render register).
 */
class CommerceTag extends Tag
{
    public static function tagName(): string
    {
        return 'commerce';
    }

    public function parse(TagParseContext $context): static
    {
        $context->params->assertEnd();

        return $this;
    }

    public function render(RenderContext $context): string
    {
        if ($context->getRegister('__mb_commerce_emitted')) {
            return '';
        }
        $context->setRegister('__mb_commerce_emitted', true);

        $config = $context->getRegister('commerce_config');
        $url = (string) $context->getRegister('commerce_runtime_url');
        if (! is_array($config) || $url === '') {
            return '';
        }

        $nonce = $context->getRegister('csp_nonce');
        $nonceAttr = is_string($nonce) && $nonce !== ''
            ? ' nonce="'.htmlspecialchars($nonce, ENT_QUOTES).'"'
            : '';

        // JSON_HEX_* closes the `</script>` breakout — item names/descriptions
        // are DB content and could otherwise terminate the inline script.
        $json = json_encode(
            $config,
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        // P6-263: platform script — parked so the theme-output sanitiser keeps it.
        return ThemeOutputSanitizer::hold('<script'.$nonceAttr.'>window.MB_COMMERCE='.$json.';</script>'
            .'<script'.$nonceAttr.' src="'.htmlspecialchars($url, ENT_QUOTES).'" defer></script>');
    }
}

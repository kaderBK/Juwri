<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Tags;

use App\Models\StoreCustomBlock;
use App\Services\FeatureAccess;
use Juwri\Core\Models\Store;
use Juwri\Modules\Storefront\Services\Rendering\ThemeOutputSanitizer;
use Keepsuit\Liquid\Exceptions\SyntaxException;
use Keepsuit\Liquid\Parse\TagParseContext;
use Keepsuit\Liquid\Parse\TokenType;
use Keepsuit\Liquid\Render\RenderContext;
use Keepsuit\Liquid\Tag;

/**
 * Theme Studio phase A: `{% custom_block section.settings.block %}` — renders one
 * of the STORE's published, owner-authored Builder.js blocks (store_custom_blocks)
 * through trusted server code. Liquid never sees the block HTML.
 *
 *  - The store comes ONLY from the server-set `custom_block_context` render
 *    register (ThemeContext::registers) — not a theme variable — and the block is
 *    looked up scoped to that store's store_id, so a block id of another store
 *    renders nothing.
 *  - Gated by FeatureAccess::storeAllows(post, 'custom_sections'): feature off → ''.
 *  - A block with a language renders only in that language; drafts never render.
 *  - Output = StoreCustomBlock::renderFor (BuilderRender::page: cleanPage at echo
 *    with the live CSP nonce on kept <style>, escaped merge tags, curated forms
 *    wired to builder.form.submit) + the Builder.js runtime assets, written once
 *    per page (the register's `emitted` object is shared by every section render).
 */
class CustomBlockTag extends Tag
{
    protected mixed $blockExpression = null;

    public static function tagName(): string
    {
        return 'custom_block';
    }

    public function parse(TagParseContext $context): static
    {
        $strict = $context->getParseContext()->environment->defaultRenderContextOptions->rethrowErrors;

        try {
            $this->blockExpression = $context->params->expression();
        } catch (SyntaxException $e) {
            if ($strict) {
                throw new SyntaxException('custom_block tag needs a block id, e.g. {% custom_block section.settings.block %}');
            }

            return $this; // live: renders ''
        }
        if ($this->blockExpression === null && $strict) {
            throw new SyntaxException('custom_block tag needs a block id, e.g. {% custom_block section.settings.block %}');
        }

        $extra = false;
        while (! $context->params->isEnd()) {
            $extra = true;
            $context->params->consumeOrFalse(TokenType::Comma);
            $context->params->expression(); // live: extra arguments are ignored
        }
        if ($extra && $strict) {
            throw new SyntaxException('custom_block tag takes a single block id');
        }

        return $this;
    }

    public function render(RenderContext $context): string
    {
        if ($this->blockExpression === null) {
            return '';
        }

        $ctx = $context->getRegister('custom_block_context');
        if (! is_array($ctx) || ! ($ctx['store'] ?? null) instanceof Store || ! $ctx['store']->id) {
            return '';
        }
        $store = $ctx['store'];

        $raw = $context->evaluate($this->blockExpression);
        $id = is_int($raw) ? $raw : (is_string($raw) && preg_match('/^[1-9][0-9]{0,18}$/', trim($raw)) ? (int) trim($raw) : 0);
        if ($id <= 0) {
            return '';
        }

        try {
            if (! FeatureAccess::storeAllows((int) $store->id, StoreCustomBlock::FEATURE)) {
                return '';
            }

            $block = StoreCustomBlock::query()->published()
                ->where('store_id', $store->id)
                ->where('id', $id)
                ->first();
            if (! $block) {
                return '';
            }
            $locale = (string) app()->getLocale();
            if (($block->locale ?? '') !== '' && $block->locale !== $locale) {
                return '';
            }

            $html = $block->renderFor($store);
            if (trim($html) === '') {
                return '';
            }

            $emitted = $ctx['emitted'] ?? null;
            $emitted = $emitted instanceof \ArrayObject ? $emitted : new \ArrayObject;
            $assets = '';
            if (! isset($emitted['runtime'])) {
                $assets = view('builder._runtime-assets')->render();
                $emitted['runtime'] = true;
            }

            // P6-263: owner-authored, platform-sanitised block — parked past the theme-output sanitiser.
            return ThemeOutputSanitizer::hold($assets.'<div class="mbs-custom-block__body" data-mb-custom-block="'.(int) $block->id.'">'.$html.'</div>');
        } catch (\Throwable $e) {
            report($e); // a custom block is an add-on — never take the storefront down

            return '';
        }
    }
}

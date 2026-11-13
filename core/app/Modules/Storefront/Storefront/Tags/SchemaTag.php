<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Tags;

use Keepsuit\Liquid\Exceptions\SyntaxException;
use Keepsuit\Liquid\Nodes\BodyNode;
use Keepsuit\Liquid\Nodes\Raw;
use Keepsuit\Liquid\Parse\TagParseContext;
use Keepsuit\Liquid\Render\RenderContext;
use Keepsuit\Liquid\TagBlock;

/**
 * P2-2b: `{% schema %}{ ...json... }{% endschema %}` — a section/settings
 * schema block. The JSON is parsed (and validated) at parse time so an author
 * sees a syntax error at upload, and the block emits NOTHING at render (it is
 * metadata, never markup). Body is raw so `{{ }}`/tags inside are not executed.
 */
class SchemaTag extends TagBlock
{
    protected array $schema = [];

    public static function tagName(): string
    {
        return 'schema';
    }

    public static function hasRawBody(): bool
    {
        return true;
    }

    public function parse(TagParseContext $context): static
    {
        $context->params->assertEnd();

        assert($context->body instanceof BodyNode);
        $body = $context->body->children()[0] ?? null;

        if (! $body instanceof Raw) {
            throw new SyntaxException('schema tag must have a single raw JSON body');
        }

        $json = trim($body->value);
        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                throw new SyntaxException('schema tag body must be a valid JSON object: '.json_last_error_msg());
            }
            $this->schema = $decoded;
        }

        return $this;
    }

    /** Metadata only — stripped from the rendered page. */
    public function render(RenderContext $context): string
    {
        return '';
    }

    /** The parsed schema (consumed by the theme editor in P2-3/P2-4). */
    public function getSchema(): array
    {
        return $this->schema;
    }

    public function blank(): bool
    {
        return true;
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Pci;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Every <script> element of an HTML page, parsed with the HTML5 parser (PHP 8.4 Dom\HTMLDocument, the same
 * tokeniser rules a browser uses, so a "<script" inside a JS string is not mistaken for a tag).
 */
final class ScriptSet
{
    /** @param list<ScriptTag> $tags */
    private function __construct(private array $tags) {}

    public static function fromHtml(string $html): self
    {
        if (trim($html) === '') {
            return new self([]);
        }
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $tags = [];
        foreach ($document->getElementsByTagName('script') as $element) {
            $tags[] = self::tag($element);
        }

        return new self($tags);
    }

    private static function tag(Element $element): ScriptTag
    {
        $type = strtolower(trim((string) $element->getAttribute('type')));
        $consentGated = $type === 'text/plain' && $element->hasAttribute('data-cookie-consent');
        $src = $element->getAttribute('src');
        if (($src === null || trim($src) === '') && $consentGated) {
            $src = $element->getAttribute('data-src');
        }
        $src = $src !== null && trim($src) !== '' ? trim($src) : null;

        return new ScriptTag(
            src: $src,
            integrity: $element->hasAttribute('integrity') ? trim((string) $element->getAttribute('integrity')) : null,
            crossorigin: $element->hasAttribute('crossorigin') ? strtolower(trim((string) $element->getAttribute('crossorigin'))) : null,
            nonced: trim((string) $element->getAttribute('nonce')) !== '',
            type: $type,
            consentGated: $consentGated,
            body: (string) $element->textContent,
        );
    }

    /** @return list<ScriptTag> */
    public function all(): array
    {
        return $this->tags;
    }

    /** @return list<ScriptTag> executable scripts loaded from a URL */
    public function external(): array
    {
        return array_values(array_filter($this->tags, fn (ScriptTag $t) => $t->isExternal() && $t->isExecutable()));
    }

    /** @return list<ScriptTag> executable inline scripts */
    public function inline(): array
    {
        return array_values(array_filter($this->tags, fn (ScriptTag $t) => ! $t->isExternal() && $t->isExecutable()));
    }
}

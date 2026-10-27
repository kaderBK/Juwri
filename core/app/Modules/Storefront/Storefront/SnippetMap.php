<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront;

use Keepsuit\Liquid\Contracts\LiquidFileSystem;
use Keepsuit\Liquid\Exceptions\FileSystemException;

/**
 * P2-2b/P2-6: the theme-scoped loader behind `{% render 'snippet' %}`. A pure
 * in-memory name→source map built from the version's snippets/ dir — lookup by
 * key, never path concatenation, so traversal is impossible by construction.
 * Anything not in the map throws (upload) / renders the error comment (live).
 */
class SnippetMap implements LiquidFileSystem
{
    /** @param  array<string,string>  $snippets  name (no extension) → liquid source */
    public function __construct(protected array $snippets = []) {}

    public function readTemplateFile(string $templateName): string
    {
        if (! preg_match('/^[a-z0-9][a-z0-9\-_]*$/i', $templateName)) {
            throw new FileSystemException('Illegal snippet name: '.$templateName);
        }
        if (! array_key_exists($templateName, $this->snippets)) {
            throw new FileSystemException('Unknown snippet: '.$templateName);
        }

        return $this->snippets[$templateName];
    }
}

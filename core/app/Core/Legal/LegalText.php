<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Legal;

use Illuminate\Support\HtmlString;

/**
 * P1-23: renders a site-copy line (lang/<locale>/site.php) that carries inline `[text](/path)` links. Everything is
 * escaped first; only links to a same-site absolute path become anchors. A link to a legal document goes to that
 * document's (localized) route — or stays plain text when the legal pages are not published for this request
 * (not published on this instance, or a reseller's white-label host: {@see LegalDocuments::publishedHere()}).
 */
final class LegalText
{
    /** @param  array<string, mixed>  $replace */
    public static function inline(string $key, array $replace = []): HtmlString
    {
        $line = (string) __($key, $replace);
        $paths = [];
        foreach (LegalDocuments::all() as $doc => $meta) {
            $paths['/'.$meta['path']] = $doc;
        }

        $html = (string) preg_replace_callback('~\[([^\]]+)\]\((/[A-Za-z0-9/_.-]*)\)~', function (array $m) use ($paths): string {
            [$all, $text, $path] = $m;
            if (isset($paths[$path])) {
                if (! LegalDocuments::publishedHere()) {
                    return $text;
                }
                $href = LegalDocuments::url($paths[$path]);
            } else {
                $href = url($path);
            }

            return '<a href="'.e($href).'">'.$text.'</a>';
        }, e($line));

        return new HtmlString($html);
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\LockedTemplates;


/**
 * Inline formatting inside a text slot: bold, italic, underline, strike-through,
 * links, text colour and highlight.
 *
 * The value is user input, so it is PARSED and REBUILT from an allow-list — never
 * passed through. Every text node is escaped; every tag or attribute outside the list
 * is dropped (its text kept); hrefs go through Slots::cleanHref and colours through
 * Slots::cleanColor, and the only style declarations ever emitted are the ones this
 * class writes itself from a validated #hex. Output is canonical, so
 * clean(clean(x)) === clean(x) and a stored value can be compared with a default.
 */
class RichText
{
    /** Accepted tag => the canonical tag it becomes. */
    private const TAGS = [
        'b' => 'strong', 'strong' => 'strong',
        'i' => 'em', 'em' => 'em',
        'u' => 'u', 'ins' => 'u',
        's' => 's', 'strike' => 's', 'del' => 's',
        'a' => 'a', 'span' => 'span', 'font' => 'span', 'mark' => 'mark', 'br' => 'br',
    ];

    /** Dropped together with their content (never visible text). */
    private const DROP = ['script', 'style', 'template', 'noscript', 'iframe', 'object', 'embed', 'svg', 'math', 'textarea', 'select', 'title', 'head'];

    private const MAX_INPUT = 30000;

    private const MAX_DEPTH = 8;

    /**
     * Sanitise a rich value. Returns null when the input is not a string or is
     * absurdly large (caller keeps the previous value).
     *
     * @param  bool  $marks  false = strip all formatting, keep the text only
     */
    public static function clean(mixed $html, int $max = 300, bool $marks = true, bool $multiline = false): ?string
    {
        if (! is_string($html) || strlen($html) > self::MAX_INPUT) {
            return null;
        }
        $max = max(1, min($max, 5000));
        if (! str_contains($html, '<') && ! str_contains($html, '&')) {
            $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', str_replace("\u{00A0}", ' ', $html)));

            return self::fromPlain(mb_substr($text, 0, $max));
        }

        $dom = new \DOMDocument;
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $dom->getElementsByTagName('div')->item(0);
        if ($root === null) {
            return '';
        }
        $budget = $max;
        $out = self::walk($root, $marks, $multiline, $budget, 0, false);

        return self::trim($out);
    }

    /** Escape plain text into the canonical rich form (no tags). */
    public static function fromPlain(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** The visible text of a (canonical) rich value. */
    public static function plain(string $html): string
    {
        return html_entity_decode(strip_tags(str_replace('<br>', "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function walk(\DOMNode $node, bool $marks, bool $multiline, int &$budget, int $depth, bool $inLink): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($budget <= 0) {
                break;
            }
            if ($child instanceof \DOMText) {
                $text = str_replace("\u{00A0}", ' ', (string) $child->nodeValue);
                $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
                $text = mb_substr($text, 0, $budget);
                $budget -= mb_strlen($text);
                $out .= self::fromPlain($text);

                continue;
            }
            if (! $child instanceof \DOMElement) {
                continue;                                      // comments, PIs
            }
            $name = strtolower($child->tagName);
            if (in_array($name, self::DROP, true)) {
                continue;
            }
            $tag = self::TAGS[$name] ?? null;
            if ($tag === 'br') {
                $out .= $multiline && $marks ? '<br>' : ' ';

                continue;
            }
            // Block wrappers (a pasted paragraph, Enter in some browsers) are unwrapped
            // but must not glue two words together.
            if ($tag === null && $out !== '' && preg_match('/^(?:div|p|li|h[1-6]|tr|blockquote)$/', $name)) {
                $out .= ' ';
            }
            $inner = self::walk($child, $marks, $multiline, $budget, $depth + 1, $inLink || $tag === 'a');
            if ($inner === '' || (trim(strip_tags($inner)) === '' && ! str_contains($inner, '<br>'))) {
                $out .= $inner;

                continue;
            }
            if ($tag === null || ! $marks || $depth >= self::MAX_DEPTH) {
                $out .= $inner;

                continue;
            }
            $out .= self::wrap($child, $tag, $inner, $inLink);
        }

        return $out;
    }

    /**
     * Re-emit one allowed element around already-sanitised inner markup, in a fixed
     * nesting order (mark › span colour › s › u › em › strong › a) so the output is
     * canonical. Presentational styles (execCommand output — a highlight may land on
     * a <strong>, a colour on a <font>) map onto the same marks.
     */
    private static function wrap(\DOMElement $el, string $tag, string $inner, bool $inLink): string
    {
        $style = self::styleMap($el->getAttribute('style'));
        $color = self::color(strtolower($el->tagName) === 'font' ? $el->getAttribute('color') : ($style['color'] ?? ''));
        $bg = self::color($style['background-color'] ?? ($style['background'] ?? ''));

        if ($tag === 'mark' || $bg) {
            $inner = '<mark'.($bg ? ' style="background-color:'.$bg.'"' : '').'>'.$inner.'</mark>';
        }
        if ($color) {
            $inner = '<span style="color:'.$color.'">'.$inner.'</span>';
        }
        $deco = strtolower($style['text-decoration'] ?? ($style['text-decoration-line'] ?? ''));
        $weight = strtolower($style['font-weight'] ?? '');
        $marks = [
            's' => $tag === 's' || str_contains($deco, 'line-through'),
            'u' => $tag === 'u' || str_contains($deco, 'underline'),
            'em' => $tag === 'em' || strtolower($style['font-style'] ?? '') === 'italic',
            'strong' => $tag === 'strong' || $weight === 'bold' || $weight === 'bolder' || (ctype_digit($weight) && (int) $weight >= 600),
        ];
        foreach ($marks as $mark => $on) {
            if ($on) {
                $inner = '<'.$mark.'>'.$inner.'</'.$mark.'>';
            }
        }

        if ($tag === 'a') {
            $href = Slots::cleanHref($el->getAttribute('href'));
            if ($href === null || $inLink) {
                return $inner;                                 // bad link / nested link → text
            }
            $attrs = ' href="'.htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
            if ($el->getAttribute('target') === '_blank') {
                $attrs .= ' target="_blank" rel="noopener noreferrer"';
            }

            return '<a'.$attrs.'>'.$inner.'</a>';
        }

        return $inner;
    }

    /** @return array<string, string> lower-cased property => raw value */
    private static function styleMap(string $style): array
    {
        $out = [];
        foreach (explode(';', $style) as $decl) {
            $parts = explode(':', $decl, 2);
            if (count($parts) === 2) {
                $out[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return $out;
    }

    /** #hex / rgb() / rgba() (opaque enough) → canonical #rrggbb, else null. */
    private static function color(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '' || $value === 'transparent' || $value === 'initial' || $value === 'inherit') {
            return null;
        }
        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([\d.]+)\s*)?\)$/', $value, $m)) {
            if (isset($m[4]) && (float) $m[4] < 0.05) {
                return null;
            }

            return sprintf('#%02x%02x%02x', min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3]));
        }
        $hex = Slots::cleanColor($value);
        if ($hex !== null && strlen($hex) === 4) {
            $hex = '#'.$hex[1].$hex[1].$hex[2].$hex[2].$hex[3].$hex[3];
        }

        return $hex;
    }

    /** Trim leading/trailing whitespace of the text without touching the markup. */
    private static function trim(string $html): string
    {
        $html = (string) preg_replace('/^((?:<[^>]+>)*)\s+/u', '$1', $html);

        return (string) preg_replace('/\s+((?:<\/[^>]+>)*)$/u', '$1', $html);
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ThemeStudio\SectionAuthor;

use Juwri\Modules\Storefront\Storefront\ThemeLinter;
use Juwri\Core\Support\Html;

/**
 * Theme Studio phase E1: the CSS gate for an AI-written section.
 *
 *  1. size       ≤ Contract::CSS_MAX_BYTES (spec §2 rule 6)
 *  2. scope      ThemeLinter::lintLibraryCss — every selector starts with
 *                `.mbs-<type>` (same rule as studio:library-check); its
 *                rtl-physical-prop warning is an ERROR here
 *  3. contract   the library build contract (tools/liquid-library/build.mjs),
 *                checked in PHP so a draft never fails later at build time:
 *                logical properties only, colours only via var(--tc-*, …),
 *                no url()/@import/@font-face/100vh, keyframes named
 *                mbs-<type>-*, no nesting
 *  4. sanitiser  the CSS goes through Html::cleanPage's own CSS sanitiser
 *                (sanitizeCss: drops @import, expression(), behavior,
 *                javascript:/data: urls, unknown at-rules); any declaration
 *                or rule it would drop is reported — the draft must survive
 *                sanitising unchanged.
 */
class CssGate
{
    private const PHYSICAL_PROP = '/^(margin|padding|border|scroll-margin|scroll-padding)-(left|right)(-|$)|^border-(top|bottom)-(left|right)-radius$|^(left|right)$/';

    private const PHYSICAL_VALUE_PROPS = ['float', 'clear', 'text-align', 'caption-side', 'justify-self', 'justify-items', 'resize'];

    private const NAMED_COLOR = '/(^|[\s,(])(white|black|red|green|blue|gray|grey|orange|yellow|purple|pink|silver|navy|teal|maroon|olive|lime|aqua|fuchsia)(?=$|[\s,);!])/i';

    /** @return array{errors: list<string>, warnings: list<string>, bytes: int} */
    public function check(string $type, string $css): array
    {
        $errors = [];
        $warnings = [];
        $bytes = strlen($css);

        if (trim($css) === '') {
            return ['errors' => ['css is empty — style the section with scoped, token-based CSS.'], 'warnings' => [], 'bytes' => 0];
        }
        if ($bytes > Contract::CSS_MAX_BYTES) {
            $errors[] = "css is {$bytes} bytes (budget ".Contract::CSS_MAX_BYTES.' bytes).';
        }

        $lint = ThemeLinter::lintLibraryCss($type, $css);
        foreach ($lint['errors'] as $e) {
            $errors[] = $e['message'];
        }
        foreach ($lint['warnings'] as $w) {
            $errors[] = $w['message']; // rtl-physical-prop is fatal for the library
        }

        array_push($errors, ...$this->contract($type, $css));
        array_push($errors, ...$this->sanitiserDrops($css, '.mbs-'.$type));

        return ['errors' => array_values(array_unique($errors)), 'warnings' => $warnings, 'bytes' => $bytes];
    }

    /**
     * The library build contract (build.mjs validate()/checkDecls()).
     *
     * @return list<string>
     */
    public function contract(string $type, string $css): array
    {
        $errors = [];
        $src = (string) preg_replace('#/\*.*?\*/#s', ' ', $css);
        if (preg_match('/@(import|charset|namespace|font-face|page|property|counter-style|layer\s*[^{]*;)/i', $src, $m)) {
            $errors[] = "@{$m[1]} is not allowed in section CSS.";
        }

        try {
            $nodes = $this->parse($src);
        } catch (\RuntimeException $e) {
            return [...$errors, 'css does not parse: '.$e->getMessage()];
        }

        $this->validate($nodes, $type, $errors);

        return array_values(array_unique($errors));
    }

    /**
     * @param  list<array<string,mixed>>  $nodes
     * @param  list<string>  $errors
     */
    private function validate(array $nodes, string $type, array &$errors): void
    {
        foreach ($nodes as $node) {
            if (isset($node['children'])) {
                if (! in_array($node['at'], ['media', 'supports', 'container'], true)) {
                    $errors[] = "@{$node['at']} is not allowed (only @media, @supports, @container, @keyframes).";
                }
                $this->validate($node['children'], $type, $errors);

                continue;
            }
            if (isset($node['frames'])) {
                if (! str_starts_with((string) $node['prelude'], "mbs-{$type}-")) {
                    $errors[] = "@keyframes {$node['prelude']} must be named mbs-{$type}-*.";
                }
                foreach ($node['frames'] as $frame) {
                    $this->checkDecls($frame['decls'] ?? [], "@keyframes {$node['prelude']}", $errors);
                }

                continue;
            }
            if (isset($node['at'])) {
                $errors[] = "@{$node['at']} is not allowed in section CSS.";

                continue;
            }
            $this->checkDecls($node['decls'], '"'.$node['selector'].'"', $errors);
        }
    }

    /**
     * @param  list<array{prop:string,value:string}>  $decls
     * @param  list<string>  $errors
     */
    private function checkDecls(array $decls, string $where, array &$errors): void
    {
        foreach ($decls as ['prop' => $prop, 'value' => $value]) {
            if (preg_match(self::PHYSICAL_PROP, $prop)) {
                $errors[] = "{$where}: physical property \"{$prop}\" — use the logical equivalent (margin-inline-start, inset-inline-end, …).";
            }
            if (in_array($prop, self::PHYSICAL_VALUE_PROPS, true) && preg_match('/\b(left|right)\b/i', $value)) {
                $errors[] = "{$where}: \"{$prop}: {$value}\" — use start/end.";
            }
            if (preg_match('/translatex\s*\(/i', $value) || ($prop === 'translate' && preg_match('/^-?[\d.]+(px|%|rem|em)?\s/', $value))) {
                $errors[] = "{$where}: horizontal translate flips in RTL.";
            }
            if (preg_match('/url\s*\(/i', $value)) {
                $errors[] = "{$where}: url() is not allowed (no external or bundled assets).";
            }
            if (preg_match('/expression\s*\(|javascript:|behaviou?r|-moz-binding/i', $prop.':'.$value)) {
                $errors[] = "{$where}: \"{$prop}\" carries a script-capable value.";
            }
            if (preg_match('/\b100(d|s|l)?vh\b/i', $value)) {
                $errors[] = "{$where}: 100vh — heights must be capped.";
            }
            if (! str_starts_with($prop, '--')) {
                $bare = $this->stripVars($value);
                if (preg_match('/#[0-9a-f]{3,8}\b/i', $bare) || preg_match('/\b(rgba?|hsla?|hwb|lab|lch|oklab|oklch)\(\s*[\d.]/i', $bare) || preg_match(self::NAMED_COLOR, $bare)) {
                    $errors[] = "{$where}: raw colour in \"{$prop}: {$value}\" — use var(--tc-*, <fallback>).";
                }
            }
        }
    }

    private function stripVars(string $value): string
    {
        $out = '';
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            if (substr($value, $i, 4) === 'var(') {
                $depth = 0;
                for ($j = $i + 3; $j < $len; $j++) {
                    if ($value[$j] === '(') {
                        $depth++;
                    } elseif ($value[$j] === ')' && --$depth === 0) {
                        break;
                    }
                }
                $out .= 'VAR';
                $i = $j;
            } else {
                $out .= $value[$i];
            }
        }

        return $out;
    }

    /**
     * Declarations / rules the platform sanitiser would drop.
     *
     * @return list<string>
     */
    public function sanitiserDrops(string $css, string $scope): array
    {
        $sanitize = \Closure::bind(static fn (string $c, string $s): string => Html::sanitizeCss($c, $s), null, Html::class);
        $clean = (string) $sanitize($css, $scope);

        $before = $this->declarationCount((string) preg_replace('#/\*.*?\*/#s', '', $css));
        $after = $this->declarationCount($clean);
        $errors = [];
        if (preg_match('/@import\b/i', $css)) {
            $errors[] = 'the CSS sanitiser drops @import.';
        }
        if ($after < $before) {
            $errors[] = 'the CSS sanitiser dropped '.($before - $after).' declaration(s) (expression(), behavior, script/data URLs or an unsupported at-rule) — remove them.';
        }

        return $errors;
    }

    /** Declarations in the innermost `{…}` blocks. */
    private function declarationCount(string $css): int
    {
        $count = 0;
        preg_match_all('/\{([^{}]*)\}/', $css, $m);
        foreach ($m[1] as $block) {
            foreach (explode(';', $block) as $decl) {
                if (str_contains($decl, ':') && trim($decl) !== '') {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Tiny CSS parser (build.mjs parse()): rules {selector, decls} and
     * at-rules {at, prelude, children|frames|decls}.
     *
     * @return list<array<string,mixed>>
     */
    private function parse(string $src): array
    {
        $i = 0;

        return $this->nodes($src, $i, true);
    }

    /** @return list<array<string,mixed>> */
    private function nodes(string $src, int &$i, bool $top): array
    {
        $list = [];
        $buf = '';
        $len = strlen($src);
        while ($i < $len) {
            $c = $src[$i];
            if ($c === '"' || $c === "'") {
                $end = $this->skipString($src, $i);
                $buf .= substr($src, $i, $end - $i);
                $i = $end;

                continue;
            }
            if ($c === '{') {
                $i++;
                $prelude = trim($buf);
                $buf = '';
                if (str_starts_with($prelude, '@')) {
                    preg_match('/^@([a-z-]+)\s*(.*)$/is', $prelude, $m);
                    $name = strtolower($m[1] ?? '');
                    $rest = trim($m[2] ?? '');
                    if (in_array($name, ['media', 'supports', 'container', 'layer'], true)) {
                        $list[] = ['at' => $name, 'prelude' => $rest, 'children' => $this->nodes($src, $i, false)];
                    } elseif ($name === 'keyframes') {
                        $list[] = ['at' => $name, 'prelude' => $rest, 'frames' => $this->nodes($src, $i, false)];
                    } else {
                        $list[] = ['at' => $name, 'prelude' => $rest, 'decls' => $this->decls($src, $i)];
                    }
                } else {
                    $list[] = ['selector' => $prelude, 'decls' => $this->decls($src, $i)];
                }

                continue;
            }
            if ($c === '}') {
                if ($top) {
                    throw new \RuntimeException('unexpected }');
                }
                $i++;
                if (trim($buf) !== '') {
                    throw new \RuntimeException('stray text "'.trim($buf).'"');
                }

                return $list;
            }
            if ($c === ';' && str_starts_with(trim($buf), '@')) {
                $list[] = ['at' => strtolower((string) strtok(substr(trim($buf), 1), " \t\n")), 'prelude' => trim($buf), 'statement' => true];
                $buf = '';
                $i++;

                continue;
            }
            $buf .= $c;
            $i++;
        }
        if (! $top) {
            throw new \RuntimeException('missing }');
        }
        if (trim($buf) !== '') {
            throw new \RuntimeException('stray text "'.mb_substr(trim($buf), 0, 60).'"');
        }

        return $list;
    }

    /** @return list<array{prop:string,value:string}> */
    private function decls(string $src, int &$i): array
    {
        $out = [];
        $buf = '';
        $depth = 0;
        $len = strlen($src);
        while ($i < $len) {
            $c = $src[$i];
            if ($c === '"' || $c === "'") {
                $end = $this->skipString($src, $i);
                $buf .= substr($src, $i, $end - $i);
                $i = $end;

                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            }
            if ($c === '{') {
                throw new \RuntimeException('nested rule inside a declaration block (nesting is not supported)');
            }
            if (($c === ';' && $depth === 0) || $c === '}') {
                $d = trim($buf);
                if ($d !== '') {
                    $k = strpos($d, ':');
                    if ($k === false || $k < 1) {
                        throw new \RuntimeException('bad declaration "'.mb_substr($d, 0, 60).'"');
                    }
                    $out[] = ['prop' => strtolower(trim(substr($d, 0, $k))), 'value' => trim(substr($d, $k + 1))];
                }
                $buf = '';
                $i++;
                if ($c === '}') {
                    return $out;
                }

                continue;
            }
            $buf .= $c;
            $i++;
        }
        throw new \RuntimeException('missing }');
    }

    private function skipString(string $src, int $i): int
    {
        $q = $src[$i];
        $len = strlen($src);
        for ($j = $i + 1; $j < $len; $j++) {
            if ($src[$j] === '\\') {
                $j++;

                continue;
            }
            if ($src[$j] === $q) {
                return $j + 1;
            }
        }
        throw new \RuntimeException('unterminated string');
    }
}

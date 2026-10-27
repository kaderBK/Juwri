<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

/**
 * P1-13 13i part 2b: the class/namespace-name parser of the module-boundary tests (it used to be a private method of
 * Tests\Architecture\ModuleBoundariesTest), shared with the Enterprise package's boundary test.
 */
final class ReferencedNames
{
    /**
     * Class/namespace names a file references: `use` imports (incl. group use)
     * and qualified names in code, resolved against the file's namespace and imports.
     *
     * @return list<string>
     */
    public static function of(string $file): array
    {
        $code = (string) file_get_contents($file);
        if (str_ends_with($file, '.blade.php')) {
            preg_match_all('/\\\\?(Juwri(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+)/', $code, $m);

            return array_values(array_unique($m[1]));
        }

        $tokens = token_get_all($code);
        $names = [];
        $namespace = '';
        $imports = [];
        $count = count($tokens);
        $depth = 0; // brace depth, to tell top-level `use` from trait `use`

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (! is_array($t)) {
                if ($t === '{') {
                    $depth++;
                } elseif ($t === '}') {
                    $depth--;
                }

                continue;
            }
            if ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;

                continue;
            }
            if ($t[0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING], true)) {
                        $namespace = $tokens[$j][1];
                        break;
                    }
                    if ($tokens[$j] === ';' || $tokens[$j] === '{') {
                        break;
                    }
                }

                continue;
            }
            if ($t[0] === T_USE && $depth === 0) {
                $stmt = '';
                for ($j = $i + 1; $j < $count && $tokens[$j] !== ';'; $j++) {
                    $stmt .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                }
                $i = $j;
                $stmt = trim((string) preg_replace('/^\s*(function|const)\s+/', '', $stmt));
                if (preg_match('/^(.*?)\{(.*)\}$/s', $stmt, $g)) {
                    $prefix = trim($g[1]);
                    foreach (explode(',', $g[2]) as $part) {
                        $part = trim((string) preg_replace('/^\s*(function|const)\s+/', '', $part));
                        if ($part === '') {
                            continue;
                        }
                        [$full, $alias] = self::splitAlias($prefix.$part);
                        $imports[strtolower($alias)] = $full;
                        $names[] = $full;
                    }
                } else {
                    foreach (explode(',', $stmt) as $part) {
                        [$full, $alias] = self::splitAlias(trim($part));
                        $imports[strtolower($alias)] = $full;
                        $names[] = $full;
                    }
                }

                continue;
            }
            if ($t[0] === T_NAME_FULLY_QUALIFIED) {
                $names[] = ltrim($t[1], '\\');
            } elseif ($t[0] === T_NAME_QUALIFIED) {
                $first = strtolower(explode('\\', $t[1])[0]);
                $names[] = isset($imports[$first])
                    ? $imports[$first].substr($t[1], strlen(explode('\\', $t[1])[0]))
                    : ($namespace !== '' ? $namespace.'\\'.$t[1] : $t[1]);
            }
        }

        return array_values(array_unique(array_map(fn ($n) => ltrim($n, '\\'), $names)));
    }

    /** @return array{0: string, 1: string} */
    private static function splitAlias(string $part): array
    {
        $part = ltrim(trim($part), '\\');
        if (preg_match('/^(.+?)\s+as\s+(\w+)$/i', $part, $m)) {
            return [trim($m[1]), $m[2]];
        }
        $segments = explode('\\', $part);

        return [$part, (string) end($segments)];
    }
}

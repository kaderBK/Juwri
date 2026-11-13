<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * J52 rename guard: the pre-store vocabulary (post/restaurant/quickorder and the
 * keys, routes, files and classes built from it) must not creep back in.
 *
 * The full scan is intentionally disabled until the last rename batch. Set
 * LEGACY_GUARD=1 to run it, or LEGACY_GUARD_REPORT=1 to print a per-rule report.
 * The rule self-test below always runs so the regexes cannot rot silently.
 */
class LegacyNamingGuardTest extends TestCase
{
    public const ENABLE_ENV = 'LEGACY_GUARD';

    public const REPORT_ENV = 'LEGACY_GUARD_REPORT';

    /** Bare words handled only by the context rules, never by the exact-name rule. */
    private const CONTEXT_ONLY = [
        'post',
        'Post',
        'posts',
        'restaurant',
        'restaurants',
        'restaurant_id',
        'post_id',
    ];

    private const SCAN_EXTENSIONS = [
        'php',
        'js',
        'ts',
        'css',
        'scss',
        'json',
        'neon',
        'md',
        'txt',
        'yml',
        'yaml',
        'xml',
        'liquid',
        'sh',
    ];

    private const SKIP_DIRS = [
        'vendor',
        'node_modules',
        'storage',
        '.git',
    ];

    /** @var array<string, array{kind: string, new: string, note: string}>|null */
    private static ?array $renameMap = null;

    /** @var array<string, true>|null */
    private static ?array $allowlistedNames = null;

    /** @var list<array{path: string, rules: list<string>, reason: string}>|null */
    private static ?array $pathAllowlist = null;

    /** @var array<string, string>|null */
    private static ?array $rulesCache = null;

    /** @var list<array{rule: string, path: string, line: int|null, text: string}>|null */
    private static ?array $scanCache = null;

    /**
     * @return array<string, string>
     */
    private static function rules(): array
    {
        if (self::$rulesCache !== null) {
            return self::$rulesCache;
        }

        $names = [];
        foreach (self::renameMap() as $name => $entry) {
            if (($entry['held'] ?? false) === true || in_array($name, self::CONTEXT_ONLY, true)) {
                continue;
            }
            $names[] = $name;
        }
        usort($names, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $quoted = array_map(static fn (string $name): string => preg_quote($name, '~'), $names);

        return self::$rulesCache = [
            'exact_names' => '~(?<![A-Za-z0-9_-])(?:'.implode('|', $quoted).')(?![A-Za-z0-9_-])~',
            'context_post_variable' => '~\$(?:post|posts|postId|restaurantId|restaurant|restaurants)\b~',
            'context_relation' => '~->(?:restaurant|restaurants|posts)\b~',
            'context_post_relation' => '~(?<!\$response)->post\b(?!\()~',
            'context_empty_relation' => '~(?<!\$request)->post\(\)~',
            'context_function' => '~\bfunction\s+(?:(?:posts|restaurant|restaurants)\s*\(|post\s*\(\s*\))~',
            'context_route_param' => '~\{(?:restaurant|post|restaurantId)\}~',
            'context_column' => '~\b(?:post_id|restaurant_id)\b~',
            'context_class' => '~\bPost(?:Option|View|Controller|TemplateService|TemplateMeta|Factory)\w*~',
            'context_restaurant_class' => '~\bRestaurant[A-Z][A-Za-z0-9]*\b~',
            'context_quick' => '~\bquick_(?:alert\w*|switch|xor\w*)~',
            'context_quick_asset' => '~\bquick-(?:reorder-icon|user-avatar|user-box)\b~',
            'context_quick_legacy' => '~\b(?:quickorder|quickmenu|quickad_|quickchat_|quickvcard)[A-Za-z0-9_]*~', // whole token, so allowlist-names.txt can exempt one legacy key
            'context_legacy_terms' => '~(?<![A-Za-z0-9_-])(?:post_options|post_templates|post_views|restaurant_tables|reseller_posts|reseller_post_id|target_post_id|sendOrder|qr-builder)(?![A-Za-z0-9_-])~',
            'context_route_name' => '~[\'"](?:restaurants?|posts)\.[A-Za-z]~',
        ];
    }

    public function test_no_legacy_names_remain(): void
    {
        if (getenv(self::ENABLE_ENV) !== '1') {
            $this->markTestSkipped('J52 guard: enabled in the last rename batch');
        }

        $hits = self::scan();
        $examples = array_map(
            static fn (array $hit): string => self::describe($hit),
            array_slice($hits, 0, 100)
        );

        $this->assertCount(
            0,
            $hits,
            'Legacy naming must be renamed (first '.count($examples).' of '.count($hits)."):\n".implode("\n", $examples)
        );
    }

    public function test_report(): void
    {
        if (getenv(self::REPORT_ENV) !== '1') {
            $this->markTestSkipped('Set '.self::REPORT_ENV.'=1 to print the legacy-naming report.');
        }

        try {
            $hits = self::scan();
            $counts = [];
            $samples = [];
            foreach ($hits as $hit) {
                $rule = $hit['rule'];
                $counts[$rule] = ($counts[$rule] ?? 0) + 1;
                if (count($samples[$rule] ?? []) < 5) {
                    $samples[$rule][] = self::describe($hit);
                }
            }
            ksort($counts);
            echo PHP_EOL.'J52 legacy-naming report: '.count($hits).' hit(s)'.PHP_EOL;
            foreach ($counts as $rule => $count) {
                echo $rule.': '.$count.PHP_EOL;
                foreach ($samples[$rule] as $sample) {
                    echo '  '.$sample.PHP_EOL;
                }
            }
        } catch (Throwable $e) {
            echo PHP_EOL.'J52 report failed to complete: '.$e->getMessage().PHP_EOL;
        }

        $this->assertTrue(true);
    }

    public function test_rules_fire_on_samples_and_ignore_http_verbs(): void
    {
        $rules = self::rules();

        $mustMatch = [
            '$post->id',
            '->restaurant->name',
            "route('restaurants.menu')",
            'PostOption::get(',
            'function post()',
        ];
        foreach ($mustMatch as $sample) {
            $this->assertTrue(
                self::matchesAnyRule($rules, $sample),
                'Expected a J52 rule to flag: '.$sample
            );
        }

        $mustNotMatch = [
            "\$this->post('/x')",
            "Route::post('/a'",
            'Http::post($u)',
            '$response->post',
            'blog_posts',
            'postal_code',
            'SocialPostController',
        ];
        foreach ($mustNotMatch as $sample) {
            $this->assertFalse(
                self::matchesAnyRule($rules, $sample),
                'Expected no J52 rule to flag: '.$sample
            );
        }
    }

    /**
     * @param  array<string, string>  $rules
     */
    private static function matchesAnyRule(array $rules, string $sample): bool
    {
        foreach ($rules as $regex) {
            if (preg_match($regex, $sample) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array{kind: string, new: string, note: string}>
     */
    private static function renameMap(): array
    {
        if (self::$renameMap !== null) {
            return self::$renameMap;
        }

        $path = __DIR__.'/legacy-naming/rename-map.json';
        $map = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($map)) {
            throw new \RuntimeException('Unable to read rename map: '.$path);
        }

        return self::$renameMap = $map;
    }

    /**
     * @return array<string, true>
     */
    private static function allowlistedNames(): array
    {
        if (self::$allowlistedNames !== null) {
            return self::$allowlistedNames;
        }

        $names = [];
        $lines = file(__DIR__.'/legacy-naming/allowlist-names.txt', FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $names[$line] = true;
        }

        return self::$allowlistedNames = $names;
    }

    /**
     * @return list<array{path: string, rules: list<string>, reason: string}>
     */
    private static function pathAllowlist(): array
    {
        if (self::$pathAllowlist !== null) {
            return self::$pathAllowlist;
        }

        $entries = require __DIR__.'/legacy-naming/path-allowlist.php';
        if (! is_array($entries)) {
            throw new \RuntimeException('path-allowlist.php must return an array.');
        }

        return self::$pathAllowlist = array_values($entries);
    }

    /**
     * @return list<array{rule: string, path: string, line: int|null, text: string}>
     */
    private static function scan(): array
    {
        if (self::$scanCache !== null) {
            return self::$scanCache;
        }

        $core = dirname(__DIR__, 2);
        $repo = dirname($core);
        $legacyDir = $core.'/tests/Architecture/legacy-naming';
        $selfPath = __FILE__;
        $rules = self::rules();
        $names = self::allowlistedNames();
        $pathAllowlist = self::pathAllowlist();

        $roots = [$core];
        foreach (['assets', 'integrations'] as $extra) {
            if (is_dir($repo.'/'.$extra)) {
                $roots[] = $repo.'/'.$extra;
            }
        }

        $hits = [];
        $seen = [];

        foreach ($roots as $root) {
            $directory = new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS);
            $filter = new RecursiveCallbackFilterIterator(
                $directory,
                static function (\SplFileInfo $current) use ($legacyDir, $selfPath): bool {
                    $path = $current->getPathname();
                    if ($current->isDir()) {
                        if (in_array($current->getFilename(), self::SKIP_DIRS, true)) {
                            return false;
                        }

                        return $path !== $legacyDir && ! str_starts_with($path, $legacyDir.'/');
                    }

                    return $path !== $selfPath;
                }
            );

            $iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $item) {
                $path = $item->getPathname();
                $relative = self::relativePath($path, $core, $repo);

                foreach (self::findHits($relative, $rules, $names) as [$rule, $text]) {
                    if (self::pathAllowed($relative, $rule, $pathAllowlist)) {
                        continue;
                    }
                    $key = 'path|'.$rule.'|'.$relative.'|'.$text;
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $hits[] = ['rule' => $rule, 'path' => $relative, 'line' => null, 'text' => $text];
                }

                if (! $item->isFile()) {
                    continue;
                }
                if (! in_array(strtolower($item->getExtension()), self::SCAN_EXTENSIONS, true)) {
                    continue;
                }

                $lines = file($path);
                if ($lines === false) {
                    continue;
                }
                foreach ($lines as $index => $line) {
                    foreach (self::findHits($line, $rules, $names) as [$rule, $text]) {
                        if (self::pathAllowed($relative, $rule, $pathAllowlist)) {
                            continue;
                        }
                        $lineNumber = $index + 1;
                        $key = 'line|'.$rule.'|'.$relative.'|'.$lineNumber.'|'.$text;
                        if (isset($seen[$key])) {
                            continue;
                        }
                        $seen[$key] = true;
                        $hits[] = [
                            'rule' => $rule,
                            'path' => $relative,
                            'line' => $lineNumber,
                            'text' => self::clip(trim($line)),
                        ];
                    }
                }
            }
        }

        return self::$scanCache = $hits;
    }

    /**
     * @param  array<string, string>  $rules
     * @param  array<string, true>  $names
     * @return list<array{0: string, 1: string}>
     */
    private static function findHits(string $text, array $rules, array $names): array
    {
        $found = [];
        foreach ($rules as $rule => $regex) {
            $count = preg_match_all($regex, $text, $matches);
            if ($count === false || $count === 0) {
                continue;
            }
            foreach ($matches[0] as $match) {
                if (isset($names[$match])) {
                    continue;
                }
                $found[$rule.'|'.$match] = [$rule, $match];
            }
        }

        return array_values($found);
    }

    private static function relativePath(string $path, string $core, string $repo): string
    {
        if (str_starts_with($path, $core.'/')) {
            return substr($path, strlen($core) + 1);
        }
        if (str_starts_with($path, $repo.'/')) {
            return substr($path, strlen($repo) + 1);
        }

        return $path;
    }

    /**
     * @param  list<array{path: string, rules: list<string>, reason: string}>  $allowlist
     */
    private static function pathAllowed(string $relative, string $rule, array $allowlist): bool
    {
        foreach ($allowlist as $entry) {
            if (! self::globMatches($entry['path'], $relative)) {
                continue;
            }
            if (in_array('*', $entry['rules'], true) || in_array($rule, $entry['rules'], true)) {
                return true;
            }
        }

        return false;
    }

    private static function globMatches(string $glob, string $path): bool
    {
        $regex = preg_quote($glob, '~');
        $regex = str_replace(['\*\*', '\*', '\?'], ['.*', '[^/]*', '[^/]'], $regex);

        return preg_match('~^'.$regex.'$~', $path) === 1;
    }

    /**
     * @param  array{rule: string, path: string, line: int|null, text: string}  $hit
     */
    private static function describe(array $hit): string
    {
        $where = $hit['path'].($hit['line'] !== null ? ':'.$hit['line'] : '');

        return '['.$hit['rule'].'] '.$where.': '.$hit['text'];
    }

    private static function clip(string $text): string
    {
        return strlen($text) > 300 ? substr($text, 0, 300).'…' : $text;
    }
}

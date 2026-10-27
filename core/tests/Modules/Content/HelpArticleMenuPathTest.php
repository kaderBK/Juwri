<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Editions\EditionPackages;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P6-35: every menu path a help article names (`**Tab → Group → Item**`) must match the real owner dashboard nav
 * (app/Http/Helpers/NavHelper.php: tab → group → item labels), or - for a path that starts at a nav item and goes
 * into a settings screen (`**Stores → Edit → Ordering**`) - a label that exists in the owner views. Every URI an
 * article writes as `/some/path` or `dashboard/goal` must match a registered route.
 */
class HelpArticleMenuPathTest extends TestCase
{
    /** UI words that are buttons, not nav labels */
    private const BUTTONS = ['Edit'];

    /** @return array{tabs: array<string, array<string, list<string>>>, items: list<string>} */
    private function nav(): array
    {
        $tabs = [];
        $tab = $group = null;
        $lines = file(base_path('app/Http/Helpers/NavHelper.php'));
        foreach (array_slice($lines, 150, 420) as $line) {
            if (preg_match("/\\['key' => '[a-z_]+', 'title' => ___\\('([^']+)'\\)/", $line, $m)) {
                $tab = $m[1];
                $tabs[$tab] = [];
            } elseif ($tab && preg_match("/^\\s*\\[___\\('([^']+)'\\), /", $line, $m)) {
                $group = $m[1];
                $tabs[$tab][$group] = [];
            } elseif ($tab && $group && preg_match("/\\\$(?:it|gatedIt|link)\\(.*?___\\('([^']+)'\\)/", $line, $m)) {
                $tabs[$tab][$group][] = $m[1];
            }
        }
        $items = [];
        foreach ($tabs as $groups) {
            foreach ($groups as $list) {
                $items = array_merge($items, $list);
            }
        }

        return ['tabs' => $tabs, 'items' => array_values(array_unique($items))];
    }

    /** @return list<string> every ___('…') label in the dashboard screens (core views, EE module views, the nav) */
    private function ownerLabels(): array
    {
        $labels = [];
        foreach ([resource_path('views'), ...EditionPackages::viewRoots()] as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }
                $code = (string) file_get_contents($file->getPathname());
                if (preg_match_all("/___\\(\\s*'((?:[^'\\\\]|\\\\.)+)'/", $code, $m)) {
                    $labels = array_merge($labels, array_map('stripslashes', $m[1]));
                }
                if (preg_match_all('/___\\(\\s*"((?:[^"\\\\]|\\\\.)+)"/', $code, $m)) {
                    $labels = array_merge($labels, array_map('stripslashes', $m[1]));
                }
            }
        }

        return array_values(array_unique($labels));
    }

    /** @return string every dashboard/staff-app view concatenated: a label an article names that is plain markup text (`>Enable alerts<`), not a ___() call */
    private function viewMarkup(): string
    {
        static $blob = null;
        if ($blob === null) {
            $blob = '';
            foreach ([resource_path('views'), ...EditionPackages::viewRoots()] as $root) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $file) {
                    if (str_ends_with($file->getFilename(), '.blade.php')) {
                        $blob .= file_get_contents($file->getPathname());
                    }
                }
            }
        }

        return $blob;
    }

    /** @return list<string> the bold UI labels an article tells the owner to act on ("Open **Stores**", "click **Save**", "under **Setup → Account**" is a path and is skipped here) */
    private function actionLabels(string $text): array
    {
        preg_match_all('/\b(?:[Gg]o to|[Oo]pen|[Cc]lick|[Tt]ap|[Ss]elect|[Cc]hoose|[Pp]ress|[Uu]nder|[Ii]n|[Tt]oggle|[Tt]urn on|[Ee]nable) \*\*([^*\n→>]{2,60})\*\*/u', $text, $m);

        return array_values(array_unique(array_map('trim', $m[1])));
    }

    /** a nav/screen label in a locale: the English source label through lang/<loc>/lang.php (slug key), as ___() does */
    private function tr(string $label, string $locale): string
    {
        static $cache = [];
        $cache[$locale] ??= require base_path('lang/'.($locale === '' ? 'en' : $locale).'/lang.php');

        return $cache[$locale][Str::slug($label, '_')] ?? $label;
    }

    /** @return array<string, list<string>> file => path segments */
    private function paths(string $locale = ''): array
    {
        $out = [];
        $files = glob(resource_path('docs/users/'.($locale === '' ? '' : $locale.'/').'*.md'));
        foreach ($files as $file) {
            // status / state flows written with arrows, not menu paths
            if ($locale !== '' && in_array(basename($file), ['fulfillment-methods.md', 'laundry-services.md', 'staff-app.md'], true)) {
                continue;
            }
            $text = (string) file_get_contents($file);
            if (preg_match_all('/\*\*([^*\n]{1,80}?(?:→|←|&gt;| > )[^*\n]{1,80}?)\*\*/u', $text, $m)) {
                foreach ($m[1] as $raw) {
                    $segs = array_map('trim', preg_split('/\s*(?:→|←|&gt;|>)\s*/u', $raw));
                    // not a menu path: a status flow ("Processing → Ready"), a button flow ("Accept → create order"), a card or column title
                    // ("Branch → vendor mapping", "View→order", "Lapsed (> 30d)"), an empty segment
                    if (in_array('', $segs, true) || str_contains($raw, '30d') || str_contains($raw, 'Processing') || str_contains($raw, 'Mark ready') || str_contains($raw, 'View→') || str_contains($raw, 'Accept →') || str_contains($raw, 'vendor mapping')) {
                        continue;
                    }
                    $out[basename($file).': '.$raw] = $segs;
                }
            }
        }

        return $out;
    }

    /** @return array<string, array{string}> */
    public static function localeProvider(): array
    {
        $out = ['en' => ['']];
        foreach (glob(dirname(__DIR__, 3).'/resources/docs/users/*', GLOB_ONLYDIR) as $dir) {
            $out[basename($dir)] = [basename($dir)];
        }

        return $out;
    }

    #[DataProvider('localeProvider')]
    public function test_every_menu_path_in_the_help_articles_exists_in_the_dashboard(string $locale): void
    {
        $en = $this->nav();
        $this->assertNotEmpty($en['tabs'], 'nav parser found no tabs');
        $this->assertArrayHasKey('Money', $en['tabs']);
        $t = fn (string $l): string => $this->tr($l, $locale);
        $nav = ['tabs' => [], 'items' => array_map($t, $en['items'])];
        foreach ($en['tabs'] as $tab => $groups) {
            foreach ($groups as $group => $items) {
                $nav['tabs'][$t($tab)][$t($group)] = array_map($t, $items);
            }
        }
        $labels = array_merge(array_map($t, $this->ownerLabels()), $nav['items'], array_map($t, self::BUTTONS));
        $paths = $this->paths($locale);
        if ($locale === '') {
            $this->assertNotEmpty($paths);
        }

        $bad = [];
        foreach ($paths as $where => $segs) {
            if (isset($nav['tabs'][$segs[0]])) {
                // Tab → Group [→ Item]
                $groups = $nav['tabs'][$segs[0]];
                $ok = isset($segs[1]) && isset($groups[$segs[1]])
                    && (! isset($segs[2]) || in_array($segs[2], $groups[$segs[1]], true))
                    && count($segs) <= 3;
                if (! $ok) {
                    $bad[] = $where;
                }

                continue;
            }
            // Item → screen labels
            if (! in_array($segs[0], $nav['items'], true)) {
                $bad[] = $where;

                continue;
            }
            foreach (array_slice($segs, 1) as $seg) {
                if (! in_array($seg, $labels, true)) {
                    $bad[] = $where;
                    break;
                }
            }
        }

        $this->assertSame([], $bad, "Help-article menu paths that do not match the dashboard ({$locale}):\n".implode("\n", $bad));
    }

    /**
     * The English UI text is NOT the ___() source string: lang/en/lang.php overrides it ("Restaurants" shows as "Stores",
     * "Edit menu" as "Change menu"). Every bold label an English article tells the owner to open / click / press must be a
     * label the dashboard really shows (after that English lookup), or plain markup text in a view.
     */
    public function test_every_action_label_in_the_english_articles_is_a_real_ui_label(): void
    {
        $known = array_map(fn (string $l): string => $this->tr($l, ''), $this->ownerLabels());
        $known = array_merge($known, $this->nav()['items']);
        $markup = $this->viewMarkup();
        $bad = [];
        foreach (glob(resource_path('docs/users/*.md')) as $file) {
            foreach ($this->actionLabels((string) file_get_contents($file)) as $label) {
                if (! in_array($label, $known, true) && ! str_contains($markup, '>'.$label.'<') && ! str_contains($markup, "'".$label."'")) {
                    $bad[] = basename($file).': '.$label;
                }
            }
        }

        $this->assertSame([], $bad, "English help-article labels that do not exist in the dashboard:\n".implode("\n", $bad));
    }

    /**
     * A translated article must name each action label with that locale's own label (lang/<loc>/lang.php), never the
     * English text and never a free re-translation that differs from what the owner sees on screen.
     */
    #[DataProvider('localeProvider')]
    public function test_translated_articles_use_the_locales_real_ui_labels(string $locale): void
    {
        if ($locale === '') {
            $this->assertTrue(true);

            return;
        }
        // English UI text -> the ___() source string it comes from (lang/en/lang.php renames some), so the locale lookup uses the right key
        $source = [];
        foreach (array_merge($this->ownerLabels(), $this->nav()['items']) as $src) {
            $source[$this->tr($src, '')][] = $src;
        }
        $bad = [];
        foreach (glob(resource_path('docs/users/'.$locale.'/*.md')) as $file) {
            $enFile = resource_path('docs/users/'.basename($file));
            if (! is_file($enFile)) {
                $bad[] = basename($file).': no English source article';

                continue;
            }
            $text = (string) file_get_contents($file);
            foreach ($this->actionLabels((string) file_get_contents($enFile)) as $label) {
                if (! isset($source[$label])) {
                    continue; // plain markup text in a view (not translated by any locale)
                }
                // several ___() sources can share one English text ("Save" / "Save changes"): any of their locale labels is right
                $wants = array_values(array_unique(array_map(fn (string $src): string => $this->tr($src, $locale), $source[$label])));
                $found = array_filter($wants, fn (string $w): bool => str_contains($text, '**'.$w.'**'));
                if ($found === []) {
                    $bad[] = basename($file).': expected **'.implode('** or **', $wants)."** (English: {$label})";
                }
            }
        }

        $this->assertSame([], $bad, "Translated help articles that do not use the locale's UI labels ({$locale}):\n".implode("\n", $bad));
    }

    public function test_every_route_uri_in_the_help_articles_is_registered(): void
    {
        $uris = [];
        foreach (Route::getRoutes() as $route) {
            $uris[] = '/'.ltrim(preg_replace('/\{[^}]+\??\}/', '{}', $route->uri()), '/');
        }
        $bad = [];
        foreach (array_merge(glob(resource_path('docs/users/*.md')), glob(resource_path('docs/users/*/*.md'))) as $file) {
            if (! preg_match_all('/`(\/?[a-z][a-z0-9_-]*(?:\/(?:[a-z0-9_-]+|\{[a-z_]+\}))+)`/', (string) file_get_contents($file), $m)) {
                continue;
            }
            foreach ($m[1] as $uri) {
                $norm = '/'.ltrim(preg_replace('/\{[^}]+\}/', '{}', $uri), '/');
                $hit = false;
                foreach ($uris as $u) {
                    if ($u === $norm || str_ends_with($u, $norm) || str_contains($u, $norm.'/')) {
                        $hit = true;
                        break;
                    }
                }
                if (! $hit) {
                    $bad[] = basename($file).': '.$uri;
                }
            }
        }

        $this->assertSame([], array_values(array_unique($bad)), "Help-article URIs that match no route:\n".implode("\n", array_unique($bad)));
    }
}

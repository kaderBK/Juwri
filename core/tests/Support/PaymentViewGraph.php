<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

/**
 * PCI 6.4.3 inventory test helper: every script a payment page CAN load, read from the Blade sources — the page
 * view and, transitively, its @extends / @include* / @each / <x-…> component views — so a script behind a
 * condition the rendering tests do not reach (Turnstile, the agent chat widget, analytics after consent) is
 * still checked. A dynamic view name (`@include($var)`, `'store_templates.'.$theme`) cannot be followed; the
 * rendering tests cover those.
 *
 * Found: <script src> / data-src literals (an absolute URL, `{{ asset('…') }}`, `$__v('…')`, `$rpV('…')`;
 * Blade echoes inside a URL become `*`) and JavaScript string literals of an absolute `….js` URL (a loader
 * that injects the script at run time, e.g. gtag / GTM / the Meta pixel).
 */
final class PaymentViewGraph
{
    /** @var array<string, true> */
    private array $seen = [];

    /** @var array<string, list<string>> src => views that load it */
    private array $scripts = [];

    /** @return array<string, list<string>> every script src the view can load, with the views it comes from */
    public static function scriptsOf(string $view): array
    {
        $graph = new self;
        $graph->walk($view);
        ksort($graph->scripts);

        return $graph->scripts;
    }

    /** @return list<string> */
    public static function viewsOf(string $view): array
    {
        $graph = new self;
        $graph->walk($view);

        return array_keys($graph->seen);
    }

    private function walk(string $view): void
    {
        if (isset($this->seen[$view])) {
            return;
        }
        $this->seen[$view] = true;
        try {
            $path = view()->getFinder()->find($view);
        } catch (\InvalidArgumentException) {
            return;
        }
        $source = (string) file_get_contents($path);
        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source); // Blade comments

        foreach ($this->scriptSources($source) as $src) {
            $this->scripts[$src][] = $view;
        }
        foreach ($this->children($source, $view) as $child) {
            $this->walk($child);
        }
    }

    /** @return list<string> */
    private function scriptSources(string $source): array
    {
        $out = [];
        if (preg_match_all('/<script\b[^>]*?\s(?:src|data-src)\s*=\s*"([^"]*)"/is', $source, $m)) {
            foreach ($m[1] as $raw) {
                $src = $this->literal($raw);
                if ($src !== null) {
                    $out[] = $src;
                }
            }
        }
        // JS loaders: `s.src = 'https://host/path?id=' + …` (gtag.js has no .js extension).
        if (preg_match_all('#\.src\s*=\s*[\'"]((?:https?:)?//[^\'"\s<>]+)[\'"]#i', $source, $m)) {
            foreach ($m[1] as $url) {
                $url = str_starts_with($url, '//') ? 'https:'.$url : (string) preg_replace('#^http:#i', 'https:', $url);
                $out[] = str_ends_with($url, '=') ? $url.'*' : $url;
            }
        }
        // JS loaders: 'https://host/path.js' / "https://host/path.js?id=" string literals.
        if (preg_match_all('#[\'"](https?:)?//([a-z0-9.-]+\.[a-z]{2,})(/[^\'"\s<>]*?\.js)(\?[^\'"\s<>]*)?[\'"]#i', $source, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $query = isset($hit[4]) && $hit[4] !== '' ? $hit[4].(str_ends_with($hit[4], '=') ? '*' : '') : '';
                $out[] = 'https://'.strtolower($hit[2]).$hit[3].$query;
            }
        }

        // P3-13: a service worker the page registers runs for every request of its scope, the payment page's too.
        if (preg_match_all('#serviceWorker\.register\(\s*[\'"](/[^\'"\s?{]*)#', $source, $m)) {
            foreach ($m[1] as $path) {
                $out[] = $path;
            }
        }

        return $out;
    }

    /** The URL a src attribute value loads, Blade echoes → `*`; null when it is wholly dynamic (`{{ $url }}`). */
    private function literal(string $raw): ?string
    {
        $raw = trim($raw);
        if (preg_match('/^\{\{\s*(?:asset|\$__v|\$rpV|\$v)\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $raw, $m)) {
            $path = '/'.ltrim($m[1], '/');

            return strtok($path, '?') ?: $path;
        }
        if (preg_match('#^(https?:)?//#i', $raw)) {
            $url = (string) preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', '*', $raw);
            if (str_starts_with($url, '//')) {
                $url = 'https:'.$url;
            }

            return $url;
        }

        return null;
    }

    /** @return list<string> view names this source pulls in */
    private function children(string $source, string $self): array
    {
        $out = [];
        $namespace = str_contains($self, '::') ? strstr($self, '::', true).'::' : '';
        if (preg_match_all('/@(?:extends|include|includeIf|includeFirst|each|component)\s*\(\s*\[?\s*[\'"]([A-Za-z0-9_.:\-\/]+)[\'"]\s*[,\)\]]/', $source, $m)) {
            foreach ($m[1] as $name) {
                $out[] = $name;
            }
        }
        if (preg_match_all('/@(?:includeWhen|includeUnless)\s*\([^,]+,\s*[\'"]([A-Za-z0-9_.:\-\/]+)[\'"]/', $source, $m)) {
            foreach ($m[1] as $name) {
                $out[] = $name;
            }
        }
        // view('name') rendered inline ({!! view('partials.brand-tile')->render() !!}).
        if (preg_match_all('/\bview\(\s*[\'"]([A-Za-z0-9_.:\-\/]+)[\'"]/', $source, $m)) {
            foreach ($m[1] as $name) {
                $out[] = $name;
            }
        }
        if (preg_match_all('/<x-([a-z0-9][a-z0-9.\-:]*)/i', $source, $m)) {
            foreach ($m[1] as $tag) {
                if (str_contains($tag, '::')) {
                    [$ns, $name] = explode('::', $tag, 2);
                    $out[] = $ns.'::components.'.$name;
                } else {
                    $out[] = 'components.'.$tag;
                    $out[] = 'components.'.$tag.'.index';
                }
            }
        }

        // A bare name inside a namespaced view usually means the same namespace when it exists there.
        return array_values(array_unique(array_map(function (string $name) use ($namespace): string {
            if ($namespace !== '' && ! str_contains($name, '::') && view()->exists($namespace.$name)) {
                return $namespace.$name;
            }

            return $name;
        }, $out)));
    }
}

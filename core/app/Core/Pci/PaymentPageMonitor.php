<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Pci;

use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Models\PaymentCspReport;
use Juwri\Core\Models\PaymentPageCheck;
use Juwri\Core\Services\AlertDispatcher;
use Juwri\Core\Services\SsrfGuard;

/**
 * PCI DSS 4.0 req. 11.6.1: change and tamper detection of the payment pages as a customer's browser receives them.
 *
 * Each target page is fetched over HTTP (the configured `targets` and the signed probe URLs of PaymentPageProbes);
 * its script set — every external script with its SRI hash and, for first-party and SRI-pinned files, the hash of
 * the file's content — and its security headers (CSP with the nonce normalised, frame/CORP/HSTS …) are hashed and
 * compared with the page's accepted baseline. Any difference, any script not in the inventory (6.4.3) or a fetch
 * error is stored (payment_page_checks) and raised through the platform AlertDispatcher. `pci:accept-baseline`
 * records the page as it is now as the new known-good state. A page's first check becomes its baseline.
 */
final class PaymentPageMonitor
{
    public const ALERT_KIND = 'pci_payment_page';

    /** @var array<string, string|null> script URL => content hash, per run */
    private array $contentHashes = [];

    /**
     * @param  list<string>|null  $only
     * @return list<array{page: string, url: string}>
     */
    public function targets(?array $only = null): array
    {
        $targets = [];
        foreach ((array) config('payment_scripts.monitor.targets', []) as $target) {
            $page = is_array($target) ? (string) ($target['page'] ?? '') : '';
            $url = is_array($target) ? (string) ($target['url'] ?? '') : '';
            if ($page === '' || $url === '' || PaymentScriptInventory::page($page) === null) {
                continue;
            }
            $targets[] = ['page' => $page, 'url' => str_starts_with($url, 'http') ? $url : url($url)];
        }
        if (config('payment_scripts.monitor.probes', true)) {
            $configured = array_column($targets, 'page');
            foreach (PaymentPageProbes::pages() as $page) {
                if (PaymentScriptInventory::page($page) !== null && ! in_array($page, $configured, true)) {
                    $targets[] = ['page' => $page, 'url' => PaymentPageProbes::url($page)];
                }
            }
        }
        if ($only !== null && $only !== []) {
            $targets = array_values(array_filter($targets, fn (array $t) => in_array($t['page'], $only, true)));
        }

        return $targets;
    }

    /**
     * Check every target (or $only pages), alert on changes, then report the payment-page CSP violations stored since
     * the previous run.
     *
     * @param  list<string>|null  $only
     * @return list<PaymentPageCheck>
     */
    public function run(?array $only = null): array
    {
        $since = PaymentPageCheck::query()->max('checked_at');
        $checks = [];
        foreach ($this->targets($only) as $target) {
            $checks[] = $this->check($target['page'], $target['url']);
        }
        $this->reportCspViolations(is_string($since) ? $since : null);
        $this->pruneReports();

        return $checks;
    }

    public function check(string $page, string $url): PaymentPageCheck
    {
        $observed = $this->observe($page, $url);
        if (isset($observed['error'])) {
            $check = PaymentPageCheck::query()->create([
                'page' => $page, 'url' => self::storedUrl($url), 'status' => PaymentPageCheck::STATUS_ERROR,
                'error' => mb_substr((string) $observed['error'], 0, 500), 'checked_at' => now(),
            ]);
            AlertDispatcher::send(self::ALERT_KIND, "payment page {$page}: change detection could not fetch it ({$observed['error']})");

            return $check;
        }

        $baseline = PaymentPageCheck::baselineOf($page);
        $changes = $baseline === null ? [] : self::differences($baseline, $observed);
        foreach ($observed['violations'] as $violation) {
            $changes[] = 'not authorised (6.4.3): '.$violation;
        }
        $status = $baseline === null ? PaymentPageCheck::STATUS_BASELINE
            : ($changes === [] ? PaymentPageCheck::STATUS_UNCHANGED : PaymentPageCheck::STATUS_CHANGED);

        $check = PaymentPageCheck::query()->create([
            'page' => $page,
            'url' => self::storedUrl($url),
            'status' => $status,
            'scripts' => $observed['scripts'],
            'inline_scripts' => $observed['inline'],
            'headers' => $observed['headers'],
            'script_hash' => $observed['script_hash'],
            'header_hash' => $observed['header_hash'],
            'changes' => $changes === [] ? null : $changes,
            'baseline' => $baseline === null,
            'accepted_by' => $baseline === null ? 'first check' : null,
            'accepted_at' => $baseline === null ? now() : null,
            'checked_at' => now(),
        ]);

        if ($changes !== []) {
            AlertDispatcher::send(self::ALERT_KIND, "payment page {$page} changed: ".mb_substr(implode('; ', $changes), 0, 900)
                .". Review it; if the change is authorised, add new scripts to the inventory and run: php artisan pci:accept-baseline {$page}");
        }

        return $check;
    }

    /** Fetch $page now and make that its accepted baseline. Null when it cannot be fetched. */
    public function accept(string $page, string $url, string $by): ?PaymentPageCheck
    {
        $observed = $this->observe($page, $url);
        if (isset($observed['error'])) {
            return null;
        }
        PaymentPageCheck::query()->where('page', $page)->where('baseline', true)->update(['baseline' => false]);

        return PaymentPageCheck::query()->create([
            'page' => $page,
            'url' => self::storedUrl($url),
            'status' => PaymentPageCheck::STATUS_BASELINE,
            'scripts' => $observed['scripts'],
            'inline_scripts' => $observed['inline'],
            'headers' => $observed['headers'],
            'script_hash' => $observed['script_hash'],
            'header_hash' => $observed['header_hash'],
            'changes' => $observed['violations'] === [] ? null : array_map(fn (string $v) => 'not authorised (6.4.3): '.$v, $observed['violations']),
            'baseline' => true,
            'accepted_by' => mb_substr($by, 0, 120),
            'accepted_at' => now(),
            'checked_at' => now(),
        ]);
    }

    /**
     * @return array{error: string}|array{scripts: list<array{src: string, integrity: string|null, content_hash: string|null}>, inline: int, headers: array<string, string>, script_hash: string, header_hash: string, violations: list<string>}
     */
    private function observe(string $page, string $url): array
    {
        try {
            $response = $this->fetch($url);
        } catch (\Throwable $e) {
            return ['error' => class_basename($e).': '.$e->getMessage()];
        }
        if ($response->status() !== 200) {
            return ['error' => 'HTTP '.$response->status()];
        }

        $html = (string) $response->body();
        $set = ScriptSet::fromHtml($html);
        $scripts = [];
        foreach ($set->external() as $tag) {
            $normalized = PaymentScriptInventory::normalize((string) $tag->src);
            $scripts[] = [
                'src' => $normalized,
                'integrity' => $tag->integrity,
                'content_hash' => $this->contentHash($page, (string) $tag->src, $normalized, $url),
            ];
        }
        usort($scripts, fn (array $a, array $b) => strcmp($a['src'], $b['src']));

        $headers = [];
        foreach ((array) config('payment_scripts.monitor.headers', []) as $name) {
            $value = $response->header((string) $name);
            if ($value !== '') {
                $headers[(string) $name] = self::normalizeHeader($value);
            }
        }
        ksort($headers);

        $inline = count($set->inline());

        return [
            'scripts' => $scripts,
            'inline' => $inline,
            'headers' => $headers,
            'script_hash' => hash('sha256', (string) json_encode([$scripts, $inline])),
            'header_hash' => hash('sha256', (string) json_encode($headers)),
            'violations' => PaymentScriptInventory::violations($html, $page),
        ];
    }

    /** P6-68b: response size cap — a script or page larger than this is refused mid-download. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** @param  array<string, mixed>  $options  extra Guzzle options (the SSRF pin) */
    private function fetch(string $url, array $options = []): ClientResponse
    {
        $cap = static function (int $total, int $downloaded): void {
            if ($total > self::MAX_BYTES || $downloaded > self::MAX_BYTES) {
                throw new \RuntimeException('response exceeds '.self::MAX_BYTES.' bytes');
            }
        };

        return Http::withHeaders([
            'User-Agent' => (string) config('payment_scripts.monitor.user_agent'),
            'Accept' => 'text/html,application/xhtml+xml',
            'Accept-Language' => 'en',
        ])->timeout(max(1, (int) config('payment_scripts.monitor.timeout', 20)))->connectTimeout(5)
            ->withOptions(array_merge(['progress' => $cap], $options, ['allow_redirects' => false]))
            ->get($url);
    }

    /**
     * P6-68b: a script src comes from the fetched page's HTML, so a tampered page could point it anywhere. Same
     * origin as the (configured, trusted) page URL → fetched as the page is; any other host must be a public https
     * address, pinned to the vetted IP (no DNS rebinding), no redirects. Otherwise null = refused.
     */
    private function fetchScript(string $absolute, string $pageUrl): ?ClientResponse
    {
        $origin = static function (string $u): string {
            $p = parse_url($u) ?: [];

            return strtolower(($p['scheme'] ?? '').'://'.($p['host'] ?? '').':'.($p['port'] ?? ''));
        };
        if ($origin($absolute) === $origin($pageUrl)) {
            return $this->fetch($absolute);
        }
        $guard = SsrfGuard::pinnedOptions($absolute, false);

        return $guard === null ? null : $this->fetch($absolute, $guard);
    }

    /**
     * The pinned content of a script: the SRI hash the browser enforces, or the sha256 of the file for a first-party
     * script (or an unlisted one — so a later swap shows too). Null for a vendor SDK that is SRI-exempt (it changes in
     * place by design; the page CSP pins its URL/host).
     */
    private function contentHash(string $page, string $src, string $normalized, string $pageUrl): ?string
    {
        $id = PaymentScriptInventory::match($src, $page);
        $entry = $id !== null ? PaymentScriptInventory::scripts()[$id] : null;
        if ($entry !== null && ! PaymentScriptInventory::isFirstParty((string) $entry['src'])) {
            $integrity = (string) ($entry['integrity'] ?? '');

            return $integrity !== '' ? 'sri:'.$integrity : null;
        }

        $absolute = self::absolute($src, $pageUrl);
        if (! array_key_exists($absolute, $this->contentHashes)) {
            try {
                $response = $this->fetchScript($absolute, $pageUrl);
                $this->contentHashes[$absolute] = $response === null ? 'blocked:non-public' : ($response->status() === 200 ? 'sha256:'.hash('sha256', (string) $response->body()) : 'http:'.$response->status());
            } catch (\Throwable) {
                $this->contentHashes[$absolute] = 'unreachable';
            }
        }

        return $this->contentHashes[$absolute];
    }

    /**
     * @param  array{scripts: list<array{src: string, integrity: string|null, content_hash: string|null}>, inline: int, headers: array<string, string>}  $observed
     * @return list<string>
     */
    private static function differences(PaymentPageCheck $baseline, array $observed): array
    {
        $changes = [];
        $before = [];
        foreach ((array) $baseline->scripts as $script) {
            $before[(string) $script['src']] = $script;
        }
        $after = [];
        foreach ($observed['scripts'] as $script) {
            $after[$script['src']] = $script;
        }
        foreach (array_diff_key($after, $before) as $src => $_) {
            $changes[] = "new script {$src}";
        }
        foreach (array_diff_key($before, $after) as $src => $_) {
            $changes[] = "script removed {$src}";
        }
        foreach (array_intersect_key($after, $before) as $src => $script) {
            if (($before[$src]['integrity'] ?? null) !== $script['integrity']) {
                $changes[] = "SRI changed on {$src}";
            }
            if (($before[$src]['content_hash'] ?? null) !== $script['content_hash']) {
                $changes[] = "content changed: {$src}";
            }
        }
        if ((int) $baseline->inline_scripts !== $observed['inline']) {
            $changes[] = "inline scripts {$baseline->inline_scripts} → {$observed['inline']}";
        }
        $old = (array) $baseline->headers;
        $new = $observed['headers'];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $name) {
            if (($old[$name] ?? null) !== ($new[$name] ?? null)) {
                $changes[] = "header {$name} changed (".mb_substr((string) ($old[$name] ?? '∅'), 0, 120).' → '.mb_substr((string) ($new[$name] ?? '∅'), 0, 120).')';
            }
        }

        return $changes;
    }

    /** CSP violations browsers reported from payment pages since the previous run (or the last 7 days). */
    private function reportCspViolations(?string $since): void
    {
        $from = $since !== null ? $since : now()->subDays(7);
        $rows = PaymentCspReport::query()->where('created_at', '>', $from)
            ->selectRaw('page, blocked_uri, count(*) as hits')->groupBy('page', 'blocked_uri')
            ->orderByDesc('hits')->limit(10)->get();
        if ($rows->isEmpty()) {
            return;
        }
        $summary = $rows->map(fn (PaymentCspReport $r) => "{$r->page}: ".($r->blocked_uri ?? 'inline').' ×'.(int) $r->getAttribute('hits'))->implode('; ');
        AlertDispatcher::send(self::ALERT_KIND, 'payment pages: the browser CSP blocked or reported scripts since the last check — '.mb_substr($summary, 0, 900));
    }

    private function pruneReports(): void
    {
        $days = max(1, (int) config('payment_scripts.reports.retention_days', 90));
        PaymentCspReport::query()->where('created_at', '<', now()->subDays($days))->delete();
    }

    /** The CSP nonce changes per response; anything else in a header is compared as is. */
    public static function normalizeHeader(string $value): string
    {
        return (string) preg_replace("/'nonce-[^']*'/", "'nonce-*'", trim($value));
    }

    private static function absolute(string $src, string $pageUrl): string
    {
        $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5));
        if (str_starts_with($src, '//')) {
            return 'https:'.$src;
        }
        if (preg_match('#^https?://#i', $src)) {
            return $src;
        }
        $parts = parse_url($pageUrl);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $origin.'/'.ltrim($src, '/');
    }

    /** A probe URL's signature/expiry are not worth keeping. */
    private static function storedUrl(string $url): string
    {
        return mb_substr((string) preg_replace('/\?(expires|signature)=.*$/', '', $url), 0, 500);
    }
}

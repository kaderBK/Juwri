<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Juwri\Core\Models\PaymentCspReport;
use Juwri\Core\Pci\PaymentScriptInventory;

/**
 * PCI DSS 4.0 6.4.3: the report endpoint of the payment-page CSP (report-uri and Reporting API report-to). Stores
 * each violation (no IP address) so a blocked script on a payment page is evidence, not a log line. Abuse guards:
 * the route is throttled per client (`throttle` in the slot file), crawlers are dropped like on /csp-report, an
 * unknown page is ignored, and at most `payment_scripts.reports.max_per_page_per_hour` rows per page and hour are
 * kept — a flood costs a cache increment, never an unbounded table.
 */
class PaymentCspReportController extends Controller
{
    public function store(Request $request, string $page): Response
    {
        $agent = (string) $request->userAgent();
        if ($agent === '' || preg_match('/bot|crawler|spider|externalads|externalhit|slurp|headless/i', $agent)) {
            return response()->noContent();
        }
        if (PaymentScriptInventory::page($page) === null) {
            return response()->noContent();
        }

        foreach (self::violations($request) as $violation) {
            if (! self::withinCap($page)) {
                break;
            }
            PaymentCspReport::query()->create([
                'page' => $page,
                'directive' => self::cut($violation['directive'], 64),
                'blocked_uri' => self::cut($violation['blocked'], 500),
                'document_uri' => self::cut($violation['document'], 500),
                'source_file' => self::cut($violation['source'], 500),
                'line' => is_numeric($violation['line']) ? max(0, (int) $violation['line']) : null,
                'disposition' => self::cut($violation['disposition'], 16),
                'sample' => self::cut($violation['sample'], 200),
                'user_agent' => self::cut($agent, 300),
                'created_at' => now(),
            ]);
        }

        return response()->noContent();
    }

    /**
     * Both wire formats: report-uri (`{"csp-report": {...}}`, kebab-case) and the Reporting API
     * (`[{"type": "csp-violation", "body": {...}}]`, camelCase). At most 20 per request.
     *
     * @return list<array{directive: mixed, blocked: mixed, document: mixed, source: mixed, line: mixed, disposition: mixed, sample: mixed}>
     */
    private static function violations(Request $request): array
    {
        $payload = json_decode((string) $request->getContent(), true);
        if (! is_array($payload)) {
            return [];
        }
        $out = [];
        if (isset($payload['csp-report']) && is_array($payload['csp-report'])) {
            $r = $payload['csp-report'];
            $out[] = [
                'directive' => $r['effective-directive'] ?? $r['violated-directive'] ?? null,
                'blocked' => $r['blocked-uri'] ?? null,
                'document' => $r['document-uri'] ?? null,
                'source' => $r['source-file'] ?? null,
                'line' => $r['line-number'] ?? null,
                'disposition' => $r['disposition'] ?? null,
                'sample' => $r['script-sample'] ?? null,
            ];
        } elseif (array_is_list($payload)) {
            foreach (array_slice($payload, 0, 20) as $report) {
                if (! is_array($report) || ($report['type'] ?? null) !== 'csp-violation' || ! is_array($report['body'] ?? null)) {
                    continue;
                }
                $b = $report['body'];
                $out[] = [
                    'directive' => $b['effectiveDirective'] ?? null,
                    'blocked' => $b['blockedURL'] ?? null,
                    'document' => $b['documentURL'] ?? ($report['url'] ?? null),
                    'source' => $b['sourceFile'] ?? null,
                    'line' => $b['lineNumber'] ?? null,
                    'disposition' => $b['disposition'] ?? null,
                    'sample' => $b['sample'] ?? null,
                ];
            }
        }

        return $out;
    }

    private static function withinCap(string $page): bool
    {
        $cap = max(1, (int) config('payment_scripts.reports.max_per_page_per_hour', 200));
        $key = 'pci-csp-reports:'.$page.':'.now()->format('YmdH');
        Cache::add($key, 0, 3600);

        return (int) Cache::increment($key) <= $cap;
    }

    private static function cut(mixed $value, int $length): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}

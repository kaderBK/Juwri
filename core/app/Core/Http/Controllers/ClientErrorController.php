<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers;

use App\Services\PlatformMetrics;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Dashboard client-error beacon. The owner dashboard's window.onerror /
 * unhandledrejection handlers navigator.sendBeacon() a small JSON payload here,
 * so client-side breakage — which never reaches the server, and so is invisible
 * in the app logs — becomes observable (e.g. a browser extension mutating the
 * DOM and killing a control, the "user says the button does nothing" class).
 *
 * Guardrails from the 2026-09-17 prod-CPU incident (csp-report flooded the log
 * with ~57k writes/day): drop bots, and rate-limit the LOG to one line per error
 * fingerprint per hour via an atomic Cache::add. PlatformMetrics keeps the full
 * aggregate. Always answer 204 — a beacon is fire-and-forget.
 */
class ClientErrorController extends Controller
{
    public function store(Request $request): Response
    {
        $agent = (string) $request->userAgent();
        if ($agent === '' || preg_match('/bot|crawler|spider|externalads|externalhit|slurp|headless/i', $agent)) {
            return response()->noContent();
        }

        $message = mb_substr(trim((string) $request->input('message', '')), 0, 300);
        if ($message === '') {
            return response()->noContent();
        }
        $stack = mb_substr((string) $request->input('stack', ''), 0, 600);
        $source = mb_substr((string) $request->input('source', ''), 0, 300);
        $path = mb_substr((string) $request->input('path', ''), 0, 200);
        $kind = mb_substr((string) $request->input('kind', 'error'), 0, 20);

        // A stack/source rooted in a browser extension means the page's own code
        // is fine — the user's extension is mutating the DOM (a common "control
        // does nothing" cause). Tag it so it is never mistaken for a platform bug.
        $isExtension = (bool) preg_match('#(chrome|moz|safari-web)-extension://#', $stack.' '.$source);

        // Store id is in the dashboard path (/stores/{id}/...); derive it
        // rather than trusting a separate posted field.
        $storeId = preg_match('#/restaurants/(\d+)#', $path, $m) ? (int) $m[1] : null;

        $fingerprint = substr(hash('sha256', $message.'|'.explode("\n", $stack)[0]), 0, 16);

        if (cache()->add('clienterr:'.$fingerprint, 1, 3600)) {
            Log::channel('client_errors')->warning('dashboard.client_error', [
                'message' => $message,
                'kind' => $kind,
                'extension' => $isExtension,
                'path' => $path,
                'store_id' => $storeId,
                'user_id' => auth()->id(),
                'source' => $source,
                'stack' => $stack,
                'ua' => mb_substr($agent, 0, 200),
            ]);
        }

        PlatformMetrics::record('client.error', $isExtension ? 'extension' : 'app');

        return response()->noContent();
    }
}

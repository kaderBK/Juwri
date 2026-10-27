<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class MaintenanceController extends Controller
{
    /**
     * Clear cached application state.
     */
    public function clearCache()
    {
        Artisan::call('optimize:clear');

        app_alert_success('Cache cleared.');

        return redirect()->route('home');
    }

    /**
     * Serve the first available favicon asset.
     */
    public function favicon()
    {
        $candidates = [
            base_path('storage/logo/favicon.png'),
            base_path('storage/logo/adminlogo.png'),
            dirname(base_path()).'/assets/brand/favicon.svg',
        ];

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return response()->file($candidate, [
                    'Cache-Control' => 'public, max-age=86400',
                ]);
            }
        }

        abort(404);
    }

    /**
     * Header that carries the cron secret (P6-119f / F8).
     */
    public const TOKEN_HEADER = 'X-Juwri-Cron-Token';

    /**
     * Trigger the scheduled job runner over HTTP (last resort; the CLI cron is preferred).
     *
     * P6-119f: the secret is read from the X-Juwri-Cron-Token header or a POST
     * body field `token` — never from the URL, which lands in access logs. The
     * old `?token=` path only works when cron.allow_query_token is on, and logs
     * a deprecation warning (without the secret) each time. Runs are capped in
     * time and guarded by a lock so two triggers never overlap. The secret is
     * never logged, echoed or put in an exception.
     */
    public function cronjob(Request $request)
    {
        $configured = (string) config('cron.token');

        if ($configured === '') {
            // An unconfigured box must not expose a working cron trigger.
            Log::warning('cronjob.unconfigured', ['ip' => $request->ip()]);
            abort(404);
        }

        $supplied = $this->suppliedToken($request);

        if ($supplied === '' || ! hash_equals($configured, $supplied)) {
            abort(404);
        }

        $maxSeconds = max(10, (int) config('cron.max_seconds', 50));
        $lock = Cache::lock('core:cronjob:web', $maxSeconds + 30);

        if (! $lock->get()) {
            return response()->json(['message' => 'already running', 'status' => 409], 409);
        }

        // ini_set outlives the request in a long-lived worker: put the limit back afterwards.
        $limit = ini_get('max_execution_time');

        try {
            set_time_limit($maxSeconds);
            Artisan::call('schedule:run');
        } catch (Throwable $e) {
            // Only the exception class goes to the log: no message, no request data.
            Log::error('cronjob.failed', ['exception' => $e::class]);

            return response()->json(['message' => 'failed', 'status' => 500], 500);
        } finally {
            ini_set('max_execution_time', (string) $limit);
            $lock->release();
        }

        return response()->json([
            'message' => 'successful',
            'status' => 200,
        ], 200);
    }

    /**
     * The secret from the header, the POST body, or (only when allowed) the query.
     */
    private function suppliedToken(Request $request): string
    {
        $header = $request->header(self::TOKEN_HEADER);
        if (is_string($header) && $header !== '') {
            return $header;
        }

        // POST body only: $request->request never holds query parameters.
        $body = $request->isMethod('post') ? $request->request->get('token') : null;
        if (is_string($body) && $body !== '') {
            return $body;
        }

        $query = $request->query->get('token');
        if (is_string($query) && $query !== '') {
            if (! config('cron.allow_query_token', false)) {
                Log::warning('cronjob.query_token_rejected', ['ip' => $request->ip()]);

                return '';
            }

            Log::warning('cronjob.query_token_deprecated', [
                'ip' => $request->ip(),
                'hint' => 'send the secret in the '.self::TOKEN_HEADER.' header instead',
            ]);

            return $query;
        }

        return '';
    }
}

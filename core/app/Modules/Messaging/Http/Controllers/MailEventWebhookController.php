<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Messaging\Services\MailEvents\MailEventWebhook;

/**
 * P6-249: bounce / complaint webhooks (POST webhook/mail/{provider}). Unauthenticated or unconfigured → 403 and nothing
 * is recorded. SMTP-only hosts with no provider webhook record nothing here (deploy/EMAIL.md: suppress by hand).
 */
class MailEventWebhookController extends Controller
{
    public function handle(Request $request, string $provider, MailEventWebhook $webhook): Response
    {
        if (! $webhook->verify($provider, $request)) {
            Log::warning('mail-events: refused unauthenticated '.$provider.' webhook', ['ip' => $request->ip()]);

            return response('Forbidden', 403);
        }

        if ($provider === 'ses' && $webhook->confirmSesSubscription($request)) {
            return response('Subscribed', 200);
        }

        $webhook->record($provider, $request);

        return response('OK', 200);
    }
}

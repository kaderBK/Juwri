<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use Juwri\Modules\Integrations\Jobs\DeliverWebhookJob;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Str;

class WebhookService
{
    /**
     * Fan a webhook event out to every matching, active endpoint owned by the
     * user. Each endpoint gets its own queued {@see DeliverWebhookJob} so a slow
     * or dead endpoint never blocks the request that fired the event, and each
     * delivery is retried independently.
     */
    public static function dispatch(int $userId, string $event, array $payload): void
    {
        $endpoints = WebhookEndpoint::where('user_id', $userId)
            ->where('active', true)
            ->get()
            ->filter(fn ($ep) => in_array($event, $ep->events ?? []) || in_array('*', $ep->events ?? []));

        foreach ($endpoints as $endpoint) {
            // A unique id per (endpoint, event) lets receivers dedupe retries.
            $messageId = 'msg_' . Str::random(24);
            $timestamp = now()->getTimestamp();

            try {
                // Runs after the response when no queue worker exists, so
                // order.paid etc. still reach integrations on cron-less hosts.
                dispatch_job_reliably(new DeliverWebhookJob($endpoint->id, $event, $payload, $messageId, $timestamp));
            } catch (\Throwable $e) {
                // Never let webhook delivery break the caller's request.
                report($e);
            }
        }
    }
}

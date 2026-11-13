<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\WhatsApp;

use App\Services\WhatsApp\WhatsAppChatbot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Juwri\Modules\Messaging\Models\WhatsAppEvent;
use Juwri\Modules\Messaging\Models\WhatsAppInbound;
use Juwri\Modules\Messaging\Models\WhatsAppLog;
use Juwri\Modules\Messaging\Models\WhatsAppNumberHealth;
use Juwri\Modules\Messaging\Models\WhatsAppOptout;
use Juwri\Modules\Messaging\Models\WhatsAppRoute;
use Juwri\Modules\Messaging\Models\WhatsAppWindow;

/**
 * Idempotently processes a decoded webhook payload: delivery/read status
 * updates and inbound messages (tenant-routed, window-opening, opt-out aware).
 * Called from the queued job so the controller can 200 fast. (spec §15.1/§15.3/§6)
 */
class WhatsAppWebhookProcessor
{
    /** P6-100: outbound message status order (status callbacks only move forward). */
    private const STATUS_RANK = ['queued' => 0, 'accepted' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 3];

    public function __construct(
        protected WhatsAppProviderFactory $factory,
        protected WhatsAppChatbot $chatbot,
    ) {}

    public function handle(string $provider, array $payload): void
    {
        $driver = $this->factory->makeByKey($provider, null);
        if (! $driver) {
            return;
        }

        // 0. Sender quality / tier updates.
        if ($health = $driver->parseHealth($payload)) {
            WhatsAppNumberHealth::record($provider, $health['sender_id'], [
                'quality' => $health['quality'] ?? null,
                'status' => $health['status'] ?? null,
                'messaging_tier' => $health['messaging_tier'] ?? null,
            ]);

            return;
        }

        // 1. Delivery/read status callbacks update the outbound log.
        if ($status = $driver->parseStatus($payload)) {
            if (! empty($status['provider_msg_id'])
                && WhatsAppEvent::claim($provider, $status['provider_msg_id'].':'.$status['status'], 'status')) {
                $this->applyStatus($status);
            }

            return;
        }

        // 2. Inbound messages.
        if ($in = $driver->parseInbound($payload)) {
            $msgId = $in['message_id'] ?? null;
            // Resolve the owning restaurant from the business number that received it.
            $storeId = WhatsAppRoute::restaurantFor($provider, $in['to'] ?? null);

            // P6-64: claim + inbound row + window commit together, so a queue retry after a
            // failed insert is not swallowed as a "duplicate" (message lost), while a
            // committed claim still blocks a second inbound row / auto-reply on retry.
            $fresh = DB::transaction(function () use ($provider, $msgId, $in, $storeId) {
                if ($msgId && ! WhatsAppEvent::claim($provider, $msgId, 'inbound')) {
                    return false; // duplicate re-delivery
                }

                WhatsAppInbound::create([
                    'store_id' => $storeId,
                    'from_phone' => $in['from'],
                    'contact_name' => $in['contact_name'] ?? null,
                    'message_id' => $msgId,
                    'body' => $in['body'] ?? '',
                    'media' => $in['media'] ?? null,
                    'received_at' => now(),
                ]);

                // Inbound (re)opens the 24h session window.
                WhatsAppWindow::touchInbound($storeId, $in['from'], $provider);

                return true;
            });
            if (! $fresh) {
                return;
            }

            $this->handleKeywords($in['from'], $storeId, (string) ($in['body'] ?? ''));

            // Optional AI auto-reply (in-window free service message).
            $this->chatbot->maybeReply($storeId, $in['from'], (string) ($in['body'] ?? ''), $provider);
        }
    }

    protected function applyStatus(array $status): void
    {
        try {
            $log = WhatsAppLog::where('provider_msg_id', $status['provider_msg_id'])->latest('id')->first();
            if (! $log) {
                return;
            }
            // P6-100: status callbacks arrive out of order; a late "sent"/"delivered" never moves a read (or
            // failed) message backwards. Unknown statuses are applied as before.
            $current = self::STATUS_RANK[(string) $log->status] ?? null;
            $next = self::STATUS_RANK[(string) $status['status']] ?? null;
            if ($current !== null && $next !== null && $next <= $current) {
                return;
            }
            $log->status = $status['status'];
            if ($status['status'] === 'delivered' && ! $log->delivered_at) {
                $log->delivered_at = now();
            }
            if ($status['status'] === 'read' && ! $log->read_at) {
                $log->read_at = now();
            }
            if ($status['status'] === 'failed') {
                $log->error = 'provider error '.($status['error'] ?? '');
            }
            $log->save();
        } catch (\Throwable $e) {
            Log::warning('[WhatsApp] status update failed: '.$e->getMessage());
        }
    }

    protected function handleKeywords(string $phone, ?int $storeId, string $body): void
    {
        $kw = strtoupper(trim($body));
        if (in_array($kw, ['STOP', 'UNSUBSCRIBE', 'CANCEL', 'QUIT'], true)) {
            WhatsAppOptout::optOut($phone, $storeId, 'reply');
        } elseif (in_array($kw, ['START', 'SUBSCRIBE', 'YES', 'UNSTOP'], true)) {
            WhatsAppOptout::optIn($phone, $storeId);
        }
    }
}

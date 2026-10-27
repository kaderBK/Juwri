<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\WhatsApp;

use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Modules\Messaging\Jobs\SendWhatsAppJob;
use Juwri\Modules\Messaging\Models\WhatsAppConsent;
use Juwri\Modules\Messaging\Models\WhatsAppNumberHealth;

/**
 * Marketing broadcast to a restaurant's opted-in audience. Each send is queued
 * as a marketing message (so opt-out + consent are re-checked per recipient)
 * and spread out to respect the provider's throughput. (spec §7 broadcast)
 */
class WhatsAppBroadcast
{
    /** Thrown when the sender's quality is too low to run marketing. */
    public function isPaused(int $storeId): bool
    {
        $factory = app(WhatsAppProviderFactory::class);
        $provider = $factory->activeProviderKey($storeId);
        if ($provider === 'link') {
            return false; // links carry no sender quality
        }

        return ! WhatsAppNumberHealth::isHealthy($provider, $this->senderId($provider, $storeId));
    }

    /**
     * @return int recipients queued; -1 when paused for low sender quality
     */
    public function send(int $storeId, string $body, ?string $template = null, array $vars = []): int
    {
        if ($this->isPaused($storeId)) {
            return -1; // sender flagged/restricted — do not risk further damage
        }

        $ownerId = Store::whereKey($storeId)->value('user_id');

        $recipients = WhatsAppConsent::where('store_id', $storeId)
            ->whereNotNull('opted_in_at')
            ->whereNull('opted_out_at')
            ->pluck('phone');

        $mps = max(1, (int) config('whatsapp.mps', 20));

        foreach ($recipients->values() as $i => $phone) {
            SendWhatsAppJob::dispatch($phone, $body, $storeId, 'campaign', true, $template, $vars)
                ->delay(now()->addSeconds(intdiv($i, $mps)));
        }

        return $recipients->count();
    }

    /** Resolve the sender identity (from-number / phone_number_id) for health lookup. */
    protected function senderId(string $provider, int $storeId): ?string
    {
        $secrets = Credential::for('whatsapp', $storeId) ?? [];

        return match ($provider) {
            'twilio' => $secrets['from_number'] ?? $secrets['twilio_from'] ?? $secrets['from'] ?? null,
            'meta' => $secrets['phone_number_id'] ?? null,
            default => null,
        };
    }

    /** Count of opted-in, non-suppressed recipients for a restaurant. */
    public function audienceSize(int $storeId): int
    {
        return WhatsAppConsent::where('store_id', $storeId)
            ->whereNotNull('opted_in_at')
            ->whereNull('opted_out_at')
            ->count();
    }
}

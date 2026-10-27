<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services;

use App\Models\Order;
use App\Support\OutboundText;
use Illuminate\Support\Facades\Log;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Juwri\Modules\Messaging\Contracts\MobilePush;
use Juwri\Modules\Messaging\Jobs\SendPushNotificationJob;
use Juwri\Modules\Messaging\Jobs\SendSmsJob;
use Juwri\Modules\Messaging\Jobs\SendWhatsAppJob;
use Juwri\Modules\Messaging\Models\PushSubscription;
use Juwri\Modules\Messaging\Services\MobilePush\MobilePushService;

/**
 * P1-08 L1 public surface: Messaging's implementation of {@see MessageQueue} and {@see MobilePush}. Pure delegation —
 * the same job dispatches / static calls the callers made themselves before.
 */
final class PublicApi implements MessageQueue, MobilePush
{
    private const WHATSAPP_BODY_LIMIT = 4096;

    public function sms(string $to, string $message, ?int $storeId = null, string $feature = 'general', bool $marketing = false): void
    {
        SendSmsJob::dispatch($to, OutboundText::text($message), $storeId, $feature, $marketing);
    }

    public function whatsApp(string $to, string $message, ?int $storeId = null, string $feature = 'general', bool $marketing = false, ?string $template = null, array $vars = [], bool $afterCommit = false): void
    {
        // P6-106: plain-text channel — strip control/bidi/zero-width chars from the body and make each template
        // param a single clean line (Meta rejects newlines, tabs and 4+ spaces in params).
        $vars = array_map(static fn ($v) => OutboundText::whatsAppParam($v), $vars);
        $message = OutboundText::text($message);
        if (mb_strlen($message) > self::WHATSAPP_BODY_LIMIT) {
            // Never truncate (could cut a URL or code): the provider rejects it and the failure is visible.
            Log::warning('whatsapp.body_over_limit', ['length' => mb_strlen($message), 'feature' => $feature, 'store_id' => $storeId]);
        }
        $pending = SendWhatsAppJob::dispatch($to, $message, $storeId, $feature, $marketing, $template, $vars);
        if ($afterCommit) {
            $pending->afterCommit();
        }
    }

    public function staffPush(int $storeId, string $title, string $body, string $url = '/staff-app', ?int $staffId = null): void
    {
        SendPushNotificationJob::dispatch($storeId, $title, $body, $url, $staffId);
    }

    public function newOrder(Order $order, ?string $body = null): void
    {
        MobilePushService::newOrder($order, $body);
    }

    public function registerDevice(int $storeId, string $token, string $platform, array $meta = [], ?int $staffId = null, ?int $userId = null): PushSubscription
    {
        return MobilePushService::registerDevice($storeId, $token, $platform, $meta, $staffId, $userId);
    }

    public function revokeDevices(array $by): int
    {
        return match (true) {
            isset($by['api_token_id']) => PushSubscription::revokeForApiToken((int) $by['api_token_id']),
            isset($by['staff_token_id']) => PushSubscription::revokeForStaffToken((int) $by['staff_token_id']),
            isset($by['staff_id']) => PushSubscription::revokeForStaff((int) $by['staff_id']),
            isset($by['driver_id']) => PushSubscription::revokeForDriver((int) $by['driver_id']),
            isset($by['owner_user_id']) => PushSubscription::revokeOwnerApps((int) $by['owner_user_id'], isset($by['keep_api_token_id']) ? (int) $by['keep_api_token_id'] : null),
            default => throw new \InvalidArgumentException('revokeDevices: no selector'),
        };
    }
}

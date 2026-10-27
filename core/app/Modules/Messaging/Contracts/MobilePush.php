<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Contracts;

use App\Models\Order;
use Illuminate\Validation\ValidationException;
use Juwri\Modules\Messaging\Models\PushSubscription;
use Juwri\Modules\Messaging\Services\MobilePush\MobilePushService;

/** P1-08 L1 public surface: owner/staff app push. Same semantics as {@see MobilePushService::newOrder()}. */
interface MobilePush
{
    public function newOrder(Order $order, ?string $body = null): void;

    /**
     * Register a native app device for push. Same semantics as {@see MobilePushService::registerDevice()}.
     *
     * @param  array<string, mixed>  $meta
     *
     * @throws ValidationException when the mobile app is off for the store
     */
    public function registerDevice(int $storeId, string $token, string $platform, array $meta = [], ?int $staffId = null, ?int $userId = null): PushSubscription;

    /**
     * P6-269: delete push devices tied to an app sign-in. Exactly one selector:
     * `api_token_id`, `staff_token_id`, `staff_id`, `driver_id`, or `owner_user_id`
     * (every owner-app native device of that user; `keep_api_token_id` spares the
     * current device). Returns rows deleted.
     *
     * @param  array{api_token_id?: int, staff_token_id?: int, staff_id?: int, driver_id?: int, owner_user_id?: int, keep_api_token_id?: int|null}  $by
     */
    public function revokeDevices(array $by): int;
}

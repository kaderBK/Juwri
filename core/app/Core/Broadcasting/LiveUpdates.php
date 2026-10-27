<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Broadcasting;

use App\Models\StaffMember;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Events\Live\KdsTicketChanged;
use Juwri\Core\Events\Live\PosOrdersChanged;
use Juwri\Core\Events\Live\StoreOrderChanged;
use Juwri\Core\Models\Branch;

/**
 * Live updates for the orders list, KDS and POS screens (ROADMAP-LATER item 17).
 *
 * Everything here is OFF unless BROADCAST_CONNECTION=reverb and the Reverb app key is set. When off, nothing is
 * dispatched and the screens keep polling exactly as before; when on, the screens still poll as a fallback. The
 * payloads carry ids and statuses only (never customer data): a screen that hears one refetches through its normal,
 * authorized endpoint.
 */
final class LiveUpdates
{
    public const CHANNEL_ORDERS = 'orders';

    public const CHANNEL_KDS = 'kds';

    public const CHANNEL_POS = 'pos';

    /** channel suffix => the staff permission that may listen (the owner may listen to all three). */
    public const PERMISSIONS = [
        self::CHANNEL_ORDERS => 'orders',
        self::CHANNEL_KDS => 'kds',
        self::CHANNEL_POS => 'pos',
    ];

    public static function enabled(): bool
    {
        return config('broadcasting.default') === 'reverb'
            && (string) config('broadcasting.connections.reverb.key', '') !== '';
    }

    public static function channel(int $storeId, string $suffix): string
    {
        return 'store.'.$storeId.'.'.$suffix;
    }

    /** P5-19 G1: one branch's channel (store.{id}.branch.{bid}.orders / .kds / .pos). */
    public static function branchChannel(int $storeId, int $branchId, string $suffix): string
    {
        return 'store.'.$storeId.'.branch.'.$branchId.'.'.$suffix;
    }

    /**
     * P5-19 G1: the branch the signed-in staff member is limited to, or null (the owner, or staff of the whole store).
     * A branch-limited member may listen only to their branch's channels (StoreChannelAuthorizer), so the screens
     * subscribe there instead of to the store channel. Same actor order as the broadcast auth route: the staff session,
     * else an owner web login (null), else a staff-only guard login.
     */
    public static function branchScope(): ?int
    {
        $staff = staff_session();
        if ($staff === null && Auth::guard('web')->user() === null) {
            $staff = Auth::guard('staff')->user();
        }

        return $staff instanceof StaffMember && (int) $staff->branch_id > 0 ? (int) $staff->branch_id : null;
    }

    /**
     * Browser-side settings, or null when live updates are off (the page then ships no script and no socket).
     *
     * @return array{key:string,host:string,port:int,scheme:string,authUrl:string}|null
     */
    public static function clientConfig(): ?array
    {
        if (! self::enabled()) {
            return null;
        }
        $host = (string) config('reverb.public.host', '');
        if ($host === '') {
            return null;
        }

        return [
            'key' => (string) config('broadcasting.connections.reverb.key'),
            'host' => $host,
            'port' => (int) config('reverb.public.port', 443),
            'scheme' => (string) config('reverb.public.scheme', 'https'),
            'authUrl' => url('broadcasting/auth'),
        ];
    }

    /**
     * An order was placed ($placed) or its status changed. Fans out to the orders, KDS and POS channels of the store
     * and, when the order has a branch, of that branch.
     */
    public static function orderChanged(int $orderId, int $storeId, string $status, bool $placed, ?int $branchId = null): void
    {
        if (! self::enabled() || $storeId <= 0) {
            return;
        }
        self::send(fn () => event(new StoreOrderChanged($storeId, $orderId, $status, $placed, $branchId)));
        self::send(fn () => event(new KdsTicketChanged($storeId, $orderId, $status, $branchId)));
        self::send(fn () => event(new PosOrdersChanged($storeId, $orderId, $status, $branchId)));
    }

    /** A kitchen ticket changed without the order status moving (an item was bumped or recalled). */
    public static function kdsChanged(int $orderId, int $storeId, string $status, ?int $branchId = null): void
    {
        if (! self::enabled() || $storeId <= 0) {
            return;
        }
        self::send(fn () => event(new KdsTicketChanged($storeId, $orderId, $status, $branchId)));
    }

    /**
     * The channels one event goes to: the store channel, plus the branch channel when the order has a branch.
     *
     * @return list<PrivateChannel>
     */
    public static function channelsFor(int $storeId, ?int $branchId, string $suffix): array
    {
        $channels = [new PrivateChannel(self::channel($storeId, $suffix))];
        // Legacy rows with no branch belong to the store's first (default) branch (ScopesToBranch), so its staff hear them too.
        $branchId = ($branchId !== null && $branchId > 0) ? $branchId : Branch::query()->withoutGlobalScopes()->where('store_id', $storeId)->orderBy('id')->value('id');
        if ($branchId !== null && $branchId > 0) {
            $channels[] = new PrivateChannel(self::branchChannel($storeId, $branchId, $suffix));
        }

        return $channels;
    }

    /** A live update must never break the request that caused it: a down socket server only costs the live push. */
    private static function send(\Closure $dispatch): void
    {
        try {
            $dispatch();
        } catch (\Throwable $e) {
            Log::warning('[live-updates] dispatch failed: '.$e->getMessage());
        }
    }
}

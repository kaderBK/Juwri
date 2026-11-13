<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Enums;

/**
 * R1a — the order-status vocabulary, previously re-declared as string literals in at
 * least five places that could drift apart (StaffAppController, OwnerTools,
 * SignageController, ManagesOrders, Order). String-backed so `->value` is the exact
 * legacy string; no Eloquent cast is added (≈3,400 legacy stores may hold out-of-enum
 * strings — use statusEnum()/tryFrom for tolerant reads).
 */
enum OrderStatus: string
{
    case PendingApproval = 'pending_approval';
    case New = 'new';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case CallBill = 'call_bill';

    /** @return list<string> All backing strings. */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** @return list<string> The statuses a staff member / dashboard may set (excludes the system-only pending_approval). */
    public static function settable(): array
    {
        return [
            self::New->value,
            self::Preparing->value,
            self::Ready->value,
            self::Delivered->value,
            self::Completed->value,
            self::Cancelled->value,
            self::CallBill->value,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingApproval => 'Pending approval',
            self::New => 'New',
            self::Preparing => 'Preparing',
            self::Ready => 'Ready',
            self::Delivered => 'Delivered',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::CallBill => 'Call bill',
        };
    }

    /** @return array<string,string> value => label */
    public static function labels(): array
    {
        $out = [];
        foreach (self::cases() as $c) {
            $out[$c->value] = $c->label();
        }

        return $out;
    }

    /**
     * BL-051: the owner Orders screen's five status groups and the stored values each one covers. Orders are created
     * `pending`; the kitchen sync rewrites them to `new` / `preparing` / `ready` / `delivered`, so `new` shows as
     * Pending and `delivered` as Completed. The chips, the counts, the server filter and the status dropdown all read
     * this one map.
     */
    public const DASHBOARD_GROUPS = [
        'pending' => ['pending', 'new'],
        'preparing' => ['preparing'],
        'ready' => ['ready'],
        'completed' => ['completed', 'delivered'],
        'cancelled' => ['cancelled'],
    ];

    /** BL-051: the owner-dashboard group of a stored status, or null (unpaid, call_bill, pending_approval, unknown). */
    public static function dashboardGroup(?string $status): ?string
    {
        foreach (self::DASHBOARD_GROUPS as $group => $values) {
            if (in_array($status, $values, true)) {
                return $group;
            }
        }

        return null;
    }

    /** A terminal status no longer in the active workflow. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Delivered], true);
    }
}

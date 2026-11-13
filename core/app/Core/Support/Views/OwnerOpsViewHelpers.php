<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support\Views;

use App\Models\StoreTable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Static helpers used by owner-ops Blade views (hotel, team, kiosk, kitchen,
 * menu, tables, marketing). They live in a class because a closure literal
 * (`fn (...)` / `function (...)`) inside a compiled view keeps about 250 bytes
 * in the worker on every include on PHP 8.4, whether or not it runs
 * (P6-36/P6-36b soak): a view rendered per request with a closure grows a
 * long-lived Octane worker. Do not add closure literals to views in the hot
 * list of Tests\Feature\Views\NoViewClosuresTest.
 */
final class OwnerOpsViewHelpers
{
    /**
     * old() with a fallback through the booking model property.
     *
     * Mirrors `fn ($k, $d = '') => old($k, $booking->{$k} ?? $d);`.
     */
    public static function bookingFormVal(mixed $booking, string $key, mixed $default = ''): mixed
    {
        return old($key, $booking->{$key} ?? $default);
    }

    /**
     * Generate a new UUID string (replaces `$uuid = fn () => Str::uuid();`).
     */
    public static function bookingUuid(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Group rooms by their `room_type_name` (falls back to a localised label).
     *
     * @param  iterable<mixed>  $rooms
     * @return Collection<int|string, Collection<int, mixed>>
     */
    public static function groupByRoomTypeName(iterable $rooms): Collection
    {
        $fallback = ___('Unassigned');
        $groups = [];
        foreach ($rooms as $r) {
            $groups[$r->room_type_name ?? $fallback][] = $r;
        }

        return self::wrapGroups($groups);
    }

    /**
     * @param  array<int|string, array<int, mixed>>  $groups
     * @return Collection<int|string, Collection<int, mixed>>
     */
    private static function wrapGroups(array $groups): Collection
    {
        $out = [];
        foreach ($groups as $key => $items) {
            $out[$key] = new Collection($items);
        }

        return new Collection($out);
    }

    /**
     * Group rooms by housekeeping status, clamping unknown statuses to "clean".
     *
     * @param  iterable<mixed>  $rooms
     * @param  array<string, mixed>  $columns
     * @return Collection<int|string, Collection<int, mixed>>
     */
    public static function groupByHousekeepingStatus(iterable $rooms, array $columns): Collection
    {
        $known = array_keys($columns);
        $groups = [];
        foreach ($rooms as $r) {
            $status = $r->hk_status ?? 'clean';
            $groups[in_array($status, $known, true) ? $status : 'clean'][] = $r;
        }

        return self::wrapGroups($groups);
    }

    /**
     * Convert a staff collection to a plain array of `{id, name}` pairs for JS.
     *
     * @param  iterable<mixed>  $staff
     * @return array<int, array{id: int, name: mixed}>
     */
    public static function staffAsIdNameList(iterable $staff): array
    {
        $out = [];
        foreach ($staff as $s) {
            $out[] = ['id' => (int) $s->id, 'name' => $s->name];
        }

        return $out;
    }

    /**
     * Resolve a rate plan's display name by id, or null when not found.
     */
    public static function ratePlanName(int $id, mixed $ratePlans): mixed
    {
        return data_get($ratePlans->firstWhere('id', $id), 'name');
    }

    /**
     * Display name for a room.
     *
     * Mirrors `function ($room) { if (! $room) return null; if ($room instanceof
     * StoreTable && $room->roomType) return data_get($room->roomType, 'name'); return $room->room_type_name ?? null; }`.
     */
    public static function roomTypeName(mixed $room): mixed
    {
        if (! $room) {
            return null;
        }
        if ($room instanceof StoreTable && $room->roomType) {
            return data_get($room->roomType, 'name');
        }

        return $room->room_type_name ?? null;
    }

    /**
     * Return the day's shifts for one member from the global $shifts collection.
     *
     * @param  Collection<int|string, mixed>  $shifts
     * @return Collection<int|string, mixed>
     */
    public static function dayShiftsForMember(Collection $shifts, mixed $memberId, mixed $day): Collection
    {
        $member = $shifts->get($memberId) ?? collect();
        $out = [];
        foreach ($member as $k => $s) {
            if ($s->starts_at->isSameDay($day)) {
                $out[$k] = $s;
            }
        }

        return new Collection($out);
    }

    /**
     * Localised label for a roster role (manager/cashier/kitchen/waiter).
     */
    public static function teamRoleLabel(mixed $role): mixed
    {
        return ['manager' => ___('Manager'), 'cashier' => ___('Cashier'), 'kitchen' => ___('Kitchen'), 'waiter' => ___('Waiter')][$role] ?? $role;
    }

    /**
     * Build today's [member, shift] pairs by flattening $dayShiftsForMember across the staff roster.
     *
     * @param  iterable<mixed>  $staff
     * @param  Collection<int|string, mixed>  $shifts
     * @return Collection<int, array{0: mixed, 1: mixed}>
     */
    public static function todayAgendaPairs(iterable $staff, Collection $shifts, mixed $day): Collection
    {
        $out = collect();
        foreach ($staff as $m) {
            foreach (self::dayShiftsForMember($shifts, $m->id, $day) as $s) {
                $out->push([$m, $s]);
            }
        }

        return $out;
    }

    /**
     * UI tone (color category) for a team-role tag.
     */
    public static function staffRoleTone(mixed $role): string
    {
        return match ($role) {
            'manager' => 'accent',
            'kitchen' => 'warning',
            default => 'neutral',
        };
    }

    /**
     * Read a kiosk/option-store key with optional default.
     */
    public static function opt(mixed $opts, string $key, mixed $default = null): mixed
    {
        return data_get($opts, $key, $default);
    }

    /**
     * Whether an automation switch (stored as '0'/'1' string) is on.
     */
    public static function automationSwitch(mixed $opts, string $name): bool
    {
        return self::opt($opts, $name) == '1';
    }

    /**
     * UI tone for a print-job status.
     */
    public static function printJobTone(mixed $status): string
    {
        return match ($status) {
            'done' => 'success',
            'failed' => 'danger',
            'claimed' => 'warning',
            default => 'neutral',
        };
    }

    /**
     * UI tone for a campaign status variant.
     */
    public static function campaignStatusVariant(mixed $status): string
    {
        return match ($status) {
            'sent' => 'success',
            'failed' => 'danger',
            'scheduled' => 'warning',
            default => 'neutral',
        };
    }

    /**
     * Count coupons expiring within the next 7 days that are not yet expired.
     *
     * @param  iterable<mixed>  $items
     */
    public static function couponsExpiringSoon(iterable $items): int
    {
        $cutoff = now()->addDays(7);
        $n = 0;
        foreach ($items as $c) {
            if ($c->expiry_at && ! $c->isExpiry() && $c->expiry_at->lessThan($cutoff)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Convert a menu collection to plain `{id, name}` pairs for JS.
     *
     * @param  iterable<mixed>  $menus
     * @return array<int, array{id: int, name: string}>
     */
    public static function menuIdNamePairs(iterable $menus): array
    {
        $out = [];
        foreach ($menus as $k => $m) {
            $out[$k] = ['id' => (int) $m->id, 'name' => (string) $m->name];
        }

        return $out;
    }

    /**
     * Flatten category + subcategory menus into one ordered collection.
     *
     * @param  iterable<mixed>  $categories
     * @return Collection<int, mixed>
     */
    public static function flatMapCategoriesMenus(iterable $categories): Collection
    {
        $out = [];
        foreach ($categories as $category) {
            foreach ($category->menus->merge($category->subcategories->flatMap->menus) as $menu) {
                $out[] = $menu;
            }
        }

        return new Collection($out);
    }

    /**
     * Build the gallery payload (`{filename, url}`) for a menu item.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function menuGalleryPayload(mixed $galleryImages, ?int $storeId): Collection
    {
        /** @var array<int, array<string, mixed>> $out */
        $out = [];
        foreach (array_filter((array) $galleryImages) as $f) {
            $out[] = ['filename' => $f, 'url' => media_url('storage/menu/', $f, $storeId)];
        }

        return new Collection($out);
    }

    /**
     * Convert a Collection of schedule targets into a plain int[] list.
     *
     * @return array<int, int>
     */
    public static function scheduleTargetIdsAsInts(mixed $targets): array
    {
        $out = [];
        foreach ($targets as $t) {
            $out[] = (int) data_get($t, 'target_id');
        }

        return $out;
    }

    /**
     * UI tone for a waitlist entry status.
     */
    public static function waitlistStatusTone(mixed $status): string
    {
        return match ($status) {
            'waiting' => 'warning',
            'notified' => 'accent',
            'seated' => 'success',
            'left' => 'danger',
            default => 'neutral',
        };
    }
}

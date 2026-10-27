<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Services\Hotel;

use App\Models\StoreTable;
use Illuminate\Support\Carbon;
use Juwri\Modules\Hotel\Models\HotelStay;
use Juwri\Modules\Hotel\Models\HousekeepingTask;

/**
 * HPMS A9 — housekeeping (SPEC §2). Two axes, never merged: the room's own
 * cleanliness (hk_status: dirty|clean|inspected on store_tables) and the
 * occupancy derived from stays. Checkout dirties the room + opens a clean task;
 * completing a clean/inspect task advances the room's cleanliness; check-in only
 * WARNS (never blocks) when the room is not clean/inspected. out_of_order rooms
 * are subtracted from availability for their range (BookingService); the
 * out_of_service flag never blocks a booking.
 */
class HousekeepingService
{
    /** Checkout hook: dirty the room and open a clean task (stay-linked) if none open. */
    public static function onCheckOut(HotelStay $stay): void
    {
        $roomId = (int) $stay->table_id;
        if ($roomId <= 0) {
            return;
        }

        StoreTable::whereKey($roomId)->update(['hk_status' => 'dirty']);

        $hasOpenClean = HousekeepingTask::where('room_id', $roomId)
            ->where('type', HousekeepingTask::TYPE_CLEAN)
            ->where('status', HousekeepingTask::STATUS_OPEN)
            ->exists();
        if (! $hasOpenClean) {
            HousekeepingTask::create([
                'store_id' => (int) $stay->store_id,
                'room_id' => $roomId,
                'type' => HousekeepingTask::TYPE_CLEAN,
                'status' => HousekeepingTask::STATUS_OPEN,
                'stay_id' => $stay->id,
                'due_date' => Carbon::now()->toDateString(),
            ]);
        }
    }

    /** Open a housekeeping task on a room. */
    public static function openTask(int $storeId, int $roomId, string $type = HousekeepingTask::TYPE_CLEAN, ?string $notes = null): HousekeepingTask
    {
        if (! in_array($type, [HousekeepingTask::TYPE_CLEAN, HousekeepingTask::TYPE_INSPECT, HousekeepingTask::TYPE_MAINTENANCE], true)) {
            $type = HousekeepingTask::TYPE_CLEAN;
        }

        return HousekeepingTask::create([
            'store_id' => $storeId,
            'room_id' => $roomId,
            'type' => $type,
            'status' => HousekeepingTask::STATUS_OPEN,
            'notes' => $notes,
        ]);
    }

    /**
     * Complete a task: a clean task advances the room to 'clean', an inspect task
     * to 'inspected'; maintenance leaves the cleanliness axis untouched.
     */
    public static function completeTask(HousekeepingTask $task, ?int $doneBy = null): HousekeepingTask
    {
        $task->status = HousekeepingTask::STATUS_DONE;
        $task->done_by = $doneBy;
        $task->done_at = Carbon::now();
        $task->save();

        if ($task->type === HousekeepingTask::TYPE_CLEAN) {
            StoreTable::whereKey($task->room_id)->update(['hk_status' => 'clean']);
        } elseif ($task->type === HousekeepingTask::TYPE_INSPECT) {
            StoreTable::whereKey($task->room_id)->update(['hk_status' => 'inspected']);
        }

        return $task->refresh();
    }

    /** Non-blocking check-in warning when the room is not clean/inspected. */
    public static function checkInWarning(?StoreTable $room): ?string
    {
        if (! $room) {
            return null;
        }
        if (in_array($room->hkStatus(), ['clean', 'inspected'], true)) {
            return null;
        }

        return ___('Room is not marked clean — check housekeeping.');
    }

    /**
     * Set a room's out-of-service marker. out_of_order (with an optional end date)
     * is subtracted from availability for its range; out_of_service is a soft flag.
     */
    public static function setOutOfOrder(StoreTable $room, string $ooStatus, ?string $ooUntil = null): StoreTable
    {
        if (! in_array($ooStatus, ['none', 'out_of_service', 'out_of_order'], true)) {
            throw new \InvalidArgumentException('Invalid out-of-service status.');
        }
        $room->oo_status = $ooStatus;
        $room->oo_until = ($ooStatus === 'out_of_order' && $ooUntil) ? Carbon::parse($ooUntil)->toDateString() : null;
        $room->save();

        return $room;
    }

    /**
     * Housekeeping board data: rooms grouped by cleanliness + the open tasks.
     *
     * @return array{grouped: array<string, array<int, StoreTable>>, open_tasks: \Illuminate\Support\Collection<int, HousekeepingTask>}
     */
    public static function board(int $storeId): array
    {
        $rooms = StoreTable::where('store_id', $storeId)
            ->where('area_type', 'room')
            ->with('roomType')
            ->orderBy('table_no')
            ->get();

        $openTasks = HousekeepingTask::where('store_id', $storeId)
            ->where('status', HousekeepingTask::STATUS_OPEN)
            ->with('room')
            ->orderBy('due_date')
            ->get();

        // First open task per room, so the board can badge a room directly (lane-B
        // contract: each room carries hk_status/oo_status/oo_until/room_type_name/open_task).
        $taskByRoom = $openTasks->keyBy(fn (HousekeepingTask $t) => (int) $t->room_id);

        $grouped = ['dirty' => [], 'clean' => [], 'inspected' => []];
        foreach ($rooms as $room) {
            $room->setAttribute('room_type_name', $room->roomType?->getAttribute('name'));
            $room->setAttribute('open_task', $taskByRoom->get((int) $room->id));
            $room->setAttribute('hk_status', $room->hkStatus());
            $room->setAttribute('oo_status', $room->ooStatus());
            $grouped[$room->hkStatus()][] = $room;
        }

        return ['grouped' => $grouped, 'open_tasks' => $openTasks];
    }
}

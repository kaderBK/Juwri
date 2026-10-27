{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Housekeeping'))
@section('page_title', ___('Housekeeping board'))
@section('page_sub', $store->title.' - '.___('Room cleanliness, tasks and out-of-service rooms.'))

@php
    use Illuminate\Support\Carbon;

    // Lane A (A9 board endpoint) supplies $rooms; defaults keep the blade renderable
    // pre-merge / defensive. Each room object:
    //   id, table_no, label, room_type_name, hk_status, oo_status, oo_until,
    //   open_task { id, type, created_at }  (nullable)
    $rooms = collect($rooms ?? []);

    $hasBackend = Route::has('hotel.pms.housekeeping');
    $canTask    = Route::has('hotel.pms.housekeeping.task');
    $canOO      = Route::has('hotel.pms.rooms.oo');

    $columns = [
        'dirty'     => ['label' => ___('Needs cleaning'), 'tone' => 'warning'],
        'clean'     => ['label' => ___('Clean'),          'tone' => 'success'],
        'inspected' => ['label' => ___('Inspected'),      'tone' => 'accent'],
    ];
    $typeLabels = ['clean' => ___('Clean'), 'inspect' => ___('Inspect'), 'maintenance' => ___('Maintenance')];
    $ooLabels = ['out_of_service' => ___('Out of service'), 'out_of_order' => ___('Out of order')];

    // Rooms default to 'clean' when the column has no value yet.
    $byStatus = \Juwri\Core\Support\Views\OwnerOpsViewHelpers::groupByHousekeepingStatus($rooms, $columns);
@endphp

@if($hasBackend && $canTask)
@section('page_actions')
    <x-ui.button type="button" icon="plus" x-data @click="$dispatch('hk-task')">{{ ___('New task') }}</x-ui.button>
@endsection
@endif

@section('content')
@unless($hasBackend)
    <x-ui.empty :title="___('PMS backend pending')" :body="___('The housekeeping board appears here once the booking engine is enabled.')" />
@else
<div class="ui-stack" x-data="pmsHousekeeping()" @hk-task.window="openTask()">
    <div class="ui-form-grid">
        @foreach($columns as $key => $col)
            @php $colRooms = $byStatus[$key] ?? collect(); @endphp
            <div>
                <div class="ui-card ui-fill">
                    <div class="ui-cluster ui-cluster--between ui-card__head">
                        <span class="ui-tag" data-tone="{{ $col['tone'] }}">{{ $col['label'] }}</span>
                        <span class="ui-tag" data-tone="neutral">{{ $colRooms->count() }}</span>
                    </div>
                    <div class="ui-card__body ui-stack">
                        @forelse($colRooms as $room)
                            @php
                                $task = $room->open_task ?? null;
                                $age = ($task && ($task->created_at ?? null)) ? Carbon::parse($task->created_at)->diffForHumans() : null;
                                $oo = $room->oo_status ?? 'none';
                            @endphp
                            <div class="ui-bordered" x-data="{ oo: false }">
                                <div class="ui-cluster ui-cluster--between ui-cluster--top">
                                    <div>
                                        <strong>#{{ $room->table_no }}</strong>
                                        <span class="ui-muted">{{ ($room->label ?? '') ? '· '.$room->label : '' }}</span>
                                        @if($room->room_type_name ?? null)
                                            <div class="ui-muted">{{ $room->room_type_name }}</div>
                                        @endif
                                    </div>
                                    @if($oo && $oo !== 'none')
                                        <span class="ui-tag" data-tone="neutral">{{ $ooLabels[$oo] ?? ___('Out of order') }}</span>
                                    @endif
                                </div>

                                @if($task)
                                    <div class="ui-cluster ui-cluster--between">
                                        <span class="ui-small">
                                            <span class="ui-tag" data-tone="neutral">{{ $typeLabels[$task->type ?? 'clean'] ?? ___('Task') }}</span>
                                            @if($age)<span class="ui-text-2">{{ $age }}</span>@endif
                                        </span>
                                        @if($canTask)
                                            <form action="{{ route('hotel.pms.housekeeping.task') }}" method="post">
                                                @csrf
                                                <input type="hidden" name="task_id" value="{{ $task->id }}">
                                                <input type="hidden" name="room_id" value="{{ $room->id }}">
                                                <input type="hidden" name="type" value="{{ $task->type ?? 'clean' }}">
                                                <input type="hidden" name="status" value="done">
                                                <x-ui.button type="submit" variant="success" size="sm" icon="check">{{ ___('Complete') }}</x-ui.button>
                                            </form>
                                        @endif
                                    </div>
                                @endif

                                @if($canOO)
                                    <div>
                                        <button type="button" class="ui-btn ui-btn--link ui-btn--sm" @click="oo = !oo">{{ ___('Out of service…') }}</button>
                                        <form action="{{ route('hotel.pms.rooms.oo', $room->id) }}" method="post" class="ui-form-grid" x-show="oo" x-cloak>
                                            @csrf
                                            <div>
                                                <select name="oo_status" class="ui-input ui-input--sm">
                                                    <option value="none" @selected($oo === 'none')>{{ ___('In service') }}</option>
                                                    <option value="out_of_service" @selected($oo === 'out_of_service')>{{ ___('Out of service') }}</option>
                                                    <option value="out_of_order" @selected($oo === 'out_of_order')>{{ ___('Out of order') }}</option>
                                                </select>
                                            </div>
                                            <div>
                                                <input type="date" name="oo_until" class="ui-input ui-input--sm" value="{{ ($room->oo_until ?? null) ? \Illuminate\Support\Carbon::parse($room->oo_until)->format('Y-m-d') : '' }}">
                                            </div>
                                            <div class="ui-form-grid__full">
                                                <x-ui.button type="submit" variant="ghost" size="sm" icon="settings">{{ ___('Set') }}</x-ui.button>
                                            </div>
                                        </form>
                                    </div>
                                @endif
                            </div>
                        @empty
                            <p class="ui-muted">{{ ___('No rooms here.') }}</p>
                        @endforelse
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- New task dialog (clean / inspect / maintenance). --}}
    @if($canTask)
    <dialog class="ui-dialog ui-dialog--flat" x-ref="taskDlg" style="max-width:460px;">
        <form action="{{ route('hotel.pms.housekeeping.task') }}" method="post">
            @csrf
            <input type="hidden" name="status" value="open">
            <div class="ui-cluster ui-cluster--between ui-rule-bottom">
                <h3 class="ui-section-title">{{ ___('New housekeeping task') }}</h3>
                <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="$refs.taskDlg.close()" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" size="18" /></button>
            </div>
            <div class="ui-stack">
                <div>
                    <label class="ui-label">{{ ___('Room') }}</label>
                    <select name="room_id" class="ui-input" required>
                        <option value="">{{ ___('Select a room') }}</option>
                        @foreach($rooms as $room)
                            <option value="{{ $room->id }}">#{{ $room->table_no }}{{ ($room->label ?? '') ? ' · '.$room->label : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="ui-label">{{ ___('Task type') }}</label>
                    <select name="type" class="ui-input">
                        <option value="clean">{{ ___('Clean') }}</option>
                        <option value="inspect">{{ ___('Inspect') }}</option>
                        <option value="maintenance">{{ ___('Maintenance') }}</option>
                    </select>
                </div>
                <div>
                    <label class="ui-label">{{ ___('Notes') }}</label>
                    <textarea name="notes" class="ui-input" rows="2" maxlength="255"></textarea>
                </div>
            </div>
            <div class="ui-cluster ui-cluster--end ui-rule-top">
                <x-ui.button type="button" variant="ghost" @click="$refs.taskDlg.close()">{{ ___('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" icon="check">{{ ___('Create task') }}</x-ui.button>
            </div>
        </form>
    </dialog>
    @endif
</div>
@endunless
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    function pmsHousekeeping() {
        return { openTask() { if (this.$refs.taskDlg) { this.$refs.taskDlg.showModal(); } } };
    }
</script>
@endpush

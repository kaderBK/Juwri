{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Hotel stays'))
@section('page_title', ___('Stays & folios'))
@section('page_sub', $store->title.' - '.___('Check guests in, charge to room and settle at check-out.'))

@section('page_actions')
    <x-ui.button type="button" icon="back" x-data @click="$dispatch('stay-checkin')">{{ ___('Check in') }}</x-ui.button>
@endsection

@php
    // HP-B3: the folio balance currency was never passed ($statement_currency was
    // always undefined → bare numbers). Show the store's folio currency on every
    // balance. (Server pagination/search over past stays is a lane-A controller
    // change — see LEDGER handoff; this blade filters the loaded window client-side.)
    $statement_currency = \Juwri\Modules\Hotel\Services\Hotel\HotelFolioService::currencyFor($store->id).' ';

    // HPMS-B7: cleanliness chips. hk_status is an additive lane-A column
    // (nullable pre-migration → chips simply hidden), read straight off the room.
    $hkTones = ['dirty' => 'warning', 'clean' => 'success', 'inspected' => 'accent'];
    $hkLabels = ['dirty' => ___('Needs cleaning'), 'clean' => ___('Clean'), 'inspected' => ___('Inspected')];
@endphp

@section('content')
<div class="ui-stack" x-data="hotelStaysPage()" @stay-checkin.window="openCheckIn()">
    {{-- Active stays --}}
    <div class="ui-card">
        <div class="ui-cluster ui-cluster--between ui-card__head">
            <h3 class="ui-section-title">{{ ___('In-house guests') }}</h3>
            <span class="ui-tag" data-tone="neutral">{{ $active->count() }}</span>
        </div>
        @if($active->isEmpty())
            <div class="ui-card__body">
                <x-ui.empty :title="___('No active stays')" :body="___('Check a guest into a room to open a charge-to-room folio.')" />
            </div>
        @else
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead><tr>
                        <th>{{ ___('Room') }}</th>
                        <th>{{ ___('Guest') }}</th>
                        <th>{{ ___('Checked in') }}</th>
                        <th class="ui-end">{{ ___('Folio balance') }}</th>
                        <th class="ui-end">{{ ___('Actions') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach($active as $stay)
                            <tr>
                                <td data-label="{{ ___('Room') }}">
                                    <strong>#{{ $stay->room->table_no ?? $stay->table_id }}</strong> {{ $stay->room->label ? '· '.$stay->room->label : '' }}
                                    @php $rtName = \Juwri\Core\Support\Views\OwnerOpsViewHelpers::roomTypeName($stay->room ?? null); $hk = $stay->room->hk_status ?? null; @endphp
                                    @if($rtName)<div class="ui-muted">{{ $rtName }}</div>@endif
                                    @if($hk && isset($hkTones[$hk]))<span class="ui-tag" data-tone="{{ $hkTones[$hk] }}">{{ $hkLabels[$hk] }}</span>@endif
                                </td>
                                <td data-label="{{ ___('Guest') }}">{{ $stay->guest_name }}<div class="ui-muted">{{ $stay->guest_phone }}</div></td>
                                <td data-label="{{ ___('Checked in') }}">{{ optional($stay->checked_in_at)->format('d M, H:i') }}</td>
                                <td class="ui-end" data-label="{{ ___('Folio balance') }}"><strong>{{ $statement_currency ?? '' }}{{ number_format($balances[$stay->id] ?? 0, 2) }}</strong></td>
                                <td class="ui-end" data-label="{{ ___('Actions') }}">
                                    <x-ui.button :href="route('hotel.stays.show', $stay->id)" variant="ghost" size="sm" icon="orders">{{ ___('Folio') }}</x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Past stays --}}
    @if($past->isNotEmpty())
    <div class="ui-card" x-data="{ q: '' }">
        <div class="ui-cluster ui-cluster--between ui-card__head">
            <h3 class="ui-section-title">{{ ___('Recent past stays') }}</h3>
            {{-- HP-B3: client-side filter over the loaded window (name / room / date). --}}
            <input type="search" x-model="q" class="ui-input ui-input--sm" style="max-width:220px;" placeholder="{{ ___('Search name, room or date') }}">
        </div>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr>
                    <th>{{ ___('Room') }}</th><th>{{ ___('Guest') }}</th><th>{{ ___('Checked out') }}</th>
                    <th>{{ ___('Status') }}</th><th class="ui-end">{{ ___('Balance') }}</th><th class="ui-end"></th>
                </tr></thead>
                <tbody>
                    @foreach($past as $stay)
                        @php $rowKey = trim(mb_strtolower(($stay->guest_name ?? '').' #'.($stay->room->table_no ?? $stay->table_id).' '.optional($stay->checked_out_at)->format('d M Y'))); @endphp
                        <tr x-show="q === '' || @js($rowKey).includes(q.toLowerCase().trim())">
                            <td data-label="{{ ___('Room') }}">#{{ $stay->room->table_no ?? $stay->table_id }}</td>
                            <td data-label="{{ ___('Guest') }}">{{ $stay->guest_name }}</td>
                            <td data-label="{{ ___('Checked out') }}">{{ optional($stay->checked_out_at)->format('d M, H:i') }}</td>
                            <td data-label="{{ ___('Status') }}"><span class="ui-tag ui-capitalize" data-tone="neutral">{{ ___(str_replace('_',' ',$stay->status)) }}</span></td>
                            <td class="ui-end" data-label="{{ ___('Balance') }}">{{ $statement_currency }}{{ number_format($balances[$stay->id] ?? 0, 2) }}</td>
                            <td class="ui-end" data-label=""><x-ui.button :href="route('hotel.stays.show', $stay->id)" variant="ghost" size="sm" icon="orders">{{ ___('Folio') }}</x-ui.button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="ui-muted ui-card__foot">{{ ___('Showing the most recent stays. Use the folio link for full statements.') }}</div>
    </div>
    @endif

    {{-- Check-in dialog --}}
    <dialog class="ui-dialog ui-dialog--flat" x-ref="checkin" style="max-width:460px;">
        <form action="{{ route('hotel.folio.checkin') }}" method="post">
            @csrf
            <div class="ui-cluster ui-cluster--between ui-rule-bottom">
                <h3 class="ui-section-title">{{ ___('Check in guest') }}</h3>
                <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="$refs.checkin.close()" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" size="18" /></button>
            </div>
            <div class="ui-stack">
                <div>
                    <label class="ui-label">{{ ___('Room') }}</label>
                    {{-- HPMS-B7: dirty rooms are flagged server-side (data-hk); selecting one
                         raises an amber "not ready" chip. WARN, never block — the guest can
                         still be checked in (housekeeping catches up). --}}
                    <select name="table_id" class="ui-input" required
                            @change="notReady = ($event.target.selectedOptions[0]?.dataset.hk === 'dirty')">
                        <option value="">{{ ___('Select a room') }}</option>
                        @foreach($rooms as $room)
                            <option value="{{ $room->id }}" data-hk="{{ $room->hk_status ?? '' }}" @disabled($room->is_occupied)>#{{ $room->table_no }} {{ $room->label ? '· '.$room->label : '' }}{{ $room->is_occupied ? ' ('.___('occupied').')' : '' }}{{ (($room->hk_status ?? '') === 'dirty') ? ' · '.___('not ready') : '' }}</option>
                        @endforeach
                    </select>
                    <div x-show="notReady" x-cloak>
                        <span class="ui-tag" data-tone="warning">{{ ___('Room not ready') }}</span>
                        <span class="ui-muted">{{ ___('This room is not marked clean. Check in anyway or pick another room.') }}</span>
                    </div>
                </div>
                <div>
                    <label class="ui-label">{{ ___('Guest name') }}</label>
                    <input type="text" name="guest_name" class="ui-input" required maxlength="120">
                </div>
                <div>
                    <label class="ui-label">{{ ___('Phone (optional)') }}</label>
                    <input type="text" name="guest_phone" class="ui-input" maxlength="40">
                </div>
            </div>
            <div class="ui-cluster ui-cluster--end ui-rule-top">
                <x-ui.button type="button" variant="ghost" @click="$refs.checkin.close()">{{ ___('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" icon="check">{{ ___('Check in') }}</x-ui.button>
            </div>
        </form>
    </dialog>
</div>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    function hotelStaysPage() {
        return {
            notReady: false,
            openCheckIn() { this.notReady = false; this.$refs.checkin.showModal(); },
        };
    }
</script>
@endpush

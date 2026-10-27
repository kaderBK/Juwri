{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@php
    use Illuminate\Support\Carbon;

    // Lane A supplies $event (object) + $items + $services + $statement; defaults defensive.
    $event    = $event ?? (object) [];
    $items    = collect($items ?? []);
    $services = collect($services ?? []);
    $currency = trim($currency ?? '');

    $status    = $event->status ?? 'enquiry';
    $stayId    = $event->hotel_stay_id ?? null;
    $isStayBilled = (bool) $stayId;
    $starts    = ($event->starts_at ?? null) ? Carbon::parse($event->starts_at) : null;
    $ends      = ($event->ends_at ?? null) ? Carbon::parse($event->ends_at) : null;

    $itemsTotal = $items->sum(fn ($i) => (float) ($i->qty ?? 0) * (float) ($i->unit_price ?? 0));
    // Standalone billing pulls the authoritative statement when lane A passes it.
    $statement = $statement ?? null;
    $cur = $currency !== '' ? $currency.' ' : '';

    $statusTones = [
        'enquiry' => 'warning', 'confirmed' => 'accent',
        'completed' => 'success', 'cancelled' => 'neutral',
    ];
    $canUpdate = Route::has('hotel.pms.events.update');
    $canItems  = Route::has('hotel.pms.events.items.add');
    $canDelItem = Route::has('hotel.pms.events.items.remove');
    $canSettle = Route::has('hotel.pms.events.settle');
@endphp

@section('title', ___('Event').' · '.($event->title ?? ''))
@section('page_title', $event->title ?? ___('Event'))
@section('page_sub', ___('Event / function').' · '.___($status))

@section('page_actions')
    @if(Route::has('hotel.pms.events'))
        <x-ui.button :href="route('hotel.pms.events')" variant="ghost" icon="back">{{ ___('All events') }}</x-ui.button>
    @endif
    @if($isStayBilled && Route::has('hotel.stays.show'))
        <x-ui.button :href="route('hotel.stays.show', $stayId)" variant="secondary" icon="orders">{{ ___('Open stay folio') }}</x-ui.button>
    @endif
@endsection

@section('content')
<div class="ui-form-grid">
    <div class="ui-stack">
        {{-- Header / BEO facts --}}
        <div class="ui-card">
            <div class="ui-cluster ui-cluster--between ui-card__head">
                <h3 class="ui-section-title">{{ ___('Function sheet') }}</h3>
                <div class="ui-cluster">
                    <span class="ui-tag ui-capitalize" data-tone="{{ $statusTones[$status] ?? 'neutral' }}">{{ ___($status) }}</span>
                    @if($isStayBilled)
                        <span class="ui-tag" data-tone="accent">{{ ___('Bills to stay folio') }}</span>
                    @else
                        <span class="ui-tag" data-tone="neutral">{{ ___('Standalone account') }}</span>
                    @endif
                </div>
            </div>
            <div class="ui-card__body">
                <dl class="ui-form-grid">
                    <dt>{{ ___('Client') }}</dt><dd>{{ $event->guest_name ?? '—' }} <span class="ui-muted">{{ $event->guest_phone ?? '' }}</span></dd>
                    <dt>{{ ___('Venue') }}</dt><dd>{{ $event->venue ?? '—' }}</dd>
                    <dt>{{ ___('Starts') }}</dt><dd>{{ optional($starts)->translatedFormat('d M Y, H:i') ?: '—' }}</dd>
                    <dt>{{ ___('Ends') }}</dt><dd>{{ optional($ends)->translatedFormat('d M Y, H:i') ?: '—' }}</dd>
                    <dt>{{ ___('Attendees') }}</dt><dd>{{ ($event->attendees ?? null) !== null ? (int) $event->attendees : '—' }}</dd>
                    @if($event->notes ?? null)
                        <dt>{{ ___('Notes') }}</dt><dd>{{ $event->notes }}</dd>
                    @endif
                </dl>
            </div>
        </div>

        {{-- Line items --}}
        <div class="ui-card">
            <div class="ui-card__head"><h3 class="ui-section-title">{{ ___('Items & charges') }}</h3></div>
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead><tr>
                        <th>{{ ___('Description') }}</th>
                        <th class="ui-end">{{ ___('Qty') }}</th>
                        <th class="ui-end">{{ ___('Unit price') }}</th>
                        <th class="ui-end">{{ ___('Line total') }}</th>
                        <th class="ui-end"></th>
                    </tr></thead>
                    <tbody>
                        @forelse($items as $it)
                            <tr>
                                <td data-label="{{ ___('Description') }}">{{ $it->description }}</td>
                                <td class="ui-end" data-label="{{ ___('Qty') }}">{{ rtrim(rtrim(number_format((float) ($it->qty ?? 0), 2), '0'), '.') }}</td>
                                <td class="ui-end" data-label="{{ ___('Unit price') }}">{{ $cur }}{{ number_format((float) ($it->unit_price ?? 0), 2) }}</td>
                                <td class="ui-end" data-label="{{ ___('Line total') }}">{{ $cur }}{{ number_format((float) ($it->qty ?? 0) * (float) ($it->unit_price ?? 0), 2) }}</td>
                                <td class="ui-end" data-label="">
                                    @if($canDelItem)
                                        <form action="{{ route('hotel.pms.events.items.remove', [$event->id, $it->id]) }}" method="post" data-confirm="{{ ___('Remove this item?') }}">
                                            @csrf
                                            <input type="hidden" name="item_id" value="{{ $it->id }}">
                                            <x-ui.button type="submit" variant="ghost" size="sm" icon="trash">{{ ___('Remove') }}</x-ui.button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="ui-text-2 ui-center">{{ ___('No items yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="ui-table__total"><th colspan="3" class="ui-end">{{ ___('Total charges') }}</th><th class="ui-end">{{ $cur }}{{ number_format((float) $itemsTotal, 2) }}</th><th></th></tr>
                    </tfoot>
                </table>
            </div>

            {{-- Add item — a catalog service (auto-priced) or a free-text line. --}}
            @if($canItems && $status !== 'cancelled')
            <div class="ui-card__body ui-rule-top">
                <form action="{{ route('hotel.pms.events.items.add', $event->id) }}" method="post" class="ui-form-grid">
                    @csrf
                    <div>
                        <label class="ui-label ui-small">{{ ___('From service') }}</label>
                        <select name="service_id" class="ui-input ui-input--sm">
                            <option value="">{{ ___('— free text —') }}</option>
                            @foreach($services as $svc)
                                <option value="{{ $svc->id }}">{{ $svc->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="ui-label ui-small">{{ ___('Description') }}</label>
                        <input type="text" name="description" class="ui-input ui-input--sm" maxlength="160" placeholder="{{ ___('e.g. Canapés package') }}">
                    </div>
                    <div>
                        <label class="ui-label ui-small">{{ ___('Qty') }}</label>
                        <input type="number" name="qty" class="ui-input ui-input--sm" value="1" min="0.01" step="0.01">
                    </div>
                    <div>
                        <label class="ui-label ui-small">{{ ___('Unit price') }}</label>
                        <input type="number" name="unit_price" class="ui-input ui-input--sm" step="0.01" min="0" placeholder="0.00">
                    </div>
                    <div>
                        <x-ui.button type="submit" variant="secondary" size="sm" icon="plus" class="ui-w-full">{{ ___('Add') }}</x-ui.button>
                    </div>
                </form>
                <div class="ui-field__help">{{ ___('Pick a service to pull its name and price, or type a free line. Leave the price blank to use the service price.') }}</div>
            </div>
            @endif
        </div>
    </div>

    {{-- Right column: status + billing --}}
    <div class="ui-stack">
        @if($canUpdate && !in_array($status, ['completed', 'cancelled'], true))
        <div class="ui-card">
            <div class="ui-card__head"><h4 class="ui-section-title">{{ ___('Status') }}</h4></div>
            <div class="ui-card__body ui-stack">
                @if($status === 'enquiry')
                    <form action="{{ route('hotel.pms.events.update', $event->id) }}" method="post">
                        @csrf
                        <input type="hidden" name="status" value="confirmed">
                        <x-ui.button type="submit" icon="check" class="ui-w-full">{{ ___('Confirm event') }}</x-ui.button>
                    </form>
                @endif
                @if($status === 'confirmed')
                    <form action="{{ route('hotel.pms.events.update', $event->id) }}" method="post">
                        @csrf
                        <input type="hidden" name="status" value="completed">
                        <x-ui.button type="submit" variant="success" icon="pin" class="ui-w-full">{{ ___('Mark completed') }}</x-ui.button>
                    </form>
                @endif
                <form action="{{ route('hotel.pms.events.update', $event->id) }}" method="post" data-confirm="{{ ___('Cancel this event?') }}" data-confirm-danger>
                    @csrf
                    <input type="hidden" name="status" value="cancelled">
                    <x-ui.button type="submit" variant="ghost" size="sm" icon="error" class="ui-w-full">{{ ___('Cancel event') }}</x-ui.button>
                </form>
            </div>
        </div>
        @endif

        <div class="ui-card">
            <div class="ui-card__head"><h4 class="ui-section-title">{{ ___('Billing') }}</h4></div>
            <div class="ui-card__body">
                @if($isStayBilled)
                    <p class="ui-muted">{{ ___('Items on this event post to the linked guest’s stay folio. Settle them on the folio at check-out.') }}</p>
                    @if(Route::has('hotel.stays.show'))
                        <x-ui.button :href="route('hotel.stays.show', $stayId)" variant="secondary" icon="orders" class="ui-w-full">{{ ___('Open stay folio') }}</x-ui.button>
                    @endif
                @else
                    {{-- Standalone: the event carries its own account. Show its balance and settle it. --}}
                    <dl class="ui-form-grid ui-small">
                        <dt>{{ ___('Total charges') }}</dt><dd class="ui-end">{{ $cur }}{{ number_format((float) ($statement['total_charges'] ?? $itemsTotal), 2) }}</dd>
                        <dt>{{ ___('Total payments') }}</dt><dd class="ui-end">{{ $cur }}{{ number_format((float) ($statement['total_payments'] ?? 0), 2) }}</dd>
                        <dt class="ui-strong">{{ ___('Balance due') }}</dt><dd class="ui-end ui-strong">{{ $cur }}{{ number_format((float) ($statement['balance'] ?? $itemsTotal), 2) }}</dd>
                    </dl>
                    @if($canSettle && $status !== 'cancelled')
                        <form action="{{ route('hotel.pms.events.settle', $event->id) }}" method="post" class="ui-stack">
                            @csrf
                            {{-- Mirror the folio settle contract: idempotent on a per-render uuid
                                 (event settle ref hotel_event:settle:{uuid}, SPEC §5). --}}
                            <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                            <div>
                                <label class="ui-label">{{ ___('Amount') }}</label>
                                @php $due = (float) ($statement['balance'] ?? $itemsTotal); @endphp
                                <input type="number" step="0.01" min="0.01" name="amount" class="ui-input" value="{{ $due > 0 ? number_format($due, 2, '.', '') : '' }}" required>
                            </div>
                            <div>
                                <label class="ui-label">{{ ___('Method') }}</label>
                                <select name="method" class="ui-input">
                                    <option value="cash">{{ ___('Cash') }}</option>
                                    <option value="card">{{ ___('Card') }}</option>
                                    <option value="online">{{ ___('Online') }}</option>
                                </select>
                            </div>
                            <x-ui.button type="submit" icon="check">{{ ___('Record payment') }}</x-ui.button>
                        </form>
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

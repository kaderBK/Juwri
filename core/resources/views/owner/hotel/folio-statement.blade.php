{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@php
    $cur = $statement['currency'].' ';
    // HP-B3: a charge dated after check-out landed on a settled folio (the late-charge
    // race, finding "Fixed write-off ref … late charges land on settled folios").
    $checkedOutAt = $stay->checked_out_at;
    $lateCharges = 0;
    if ($checkedOutAt) {
        foreach ($statement['charges'] as $c) {
            if (!empty($c['at']) && $c['at']->gt($checkedOutAt)) {
                $lateCharges++;
            }
        }
    }
@endphp

@section('title', ___('Folio').' #'.$stay->id)
@section('page_title', ___('Folio').' · '.$stay->guest_name)
@section('page_sub', ___('Room').' #'.($stay->room->table_no ?? $stay->table_id).' · '.___(str_replace('_',' ',$stay->status)))

@section('page_actions')
    <x-ui.button :href="route('hotel.stays')" variant="ghost" icon="back">{{ ___('Back') }}</x-ui.button>
    <x-ui.button :href="route('hotel.stays.print', $stay->id)" target="_blank" variant="secondary" icon="print">{{ ___('Print') }}</x-ui.button>
@endsection

@section('content')
<div class="ui-form-grid">
    <div>
        @if($lateCharges > 0)
            <div class="ui-cluster ui-note" data-tone="warning">
                <x-ui.icon name="alert" size="18" />
                <span>{{ ___('This folio received charges after check-out. Review and adjust before it is considered final.') }}</span>
            </div>
        @endif
        <div class="ui-card">
            <div class="ui-card__head"><h3 class="ui-section-title">{{ ___('Statement') }}</h3></div>
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead><tr>
                        <th>{{ ___('Date') }}</th><th>{{ ___('Description') }}</th>
                        <th class="ui-end">{{ ___('Charge') }}</th><th class="ui-end">{{ ___('Payment') }}</th>
                    </tr></thead>
                    <tbody>
                        @forelse(collect($statement['charges'])->map(fn($r)=>$r+['k'=>'c'])->merge(collect($statement['payments'])->map(fn($r)=>$r+['k'=>'p']))->sortBy('id') as $row)
                            <tr>
                                <td class="ui-muted" data-label="{{ ___('Date') }}">{{ optional($row['at'])->format('d M, H:i') }}</td>
                                <td data-label="{{ ___('Description') }}">
                                    {{ $row['reason'] }}
                                    @if($row['k']==='c' && $checkedOutAt && !empty($row['at']) && $row['at']->gt($checkedOutAt))
                                        <span class="ui-tag" data-tone="warning">{{ ___('Late charge') }}</span>
                                    @endif
                                    {{-- A3 contract (tolerated when absent): lane A may flag a line that needs manual adjustment. --}}
                                    @if(!empty($row['needs_adjustment']))
                                        <span class="ui-tag" data-tone="danger">{{ ___('Needs manual adjustment') }}</span>
                                    @endif
                                </td>
                                <td class="ui-end" data-label="{{ ___('Charge') }}">{{ $row['k']==='c' ? $cur.number_format($row['amount'],2) : '—' }}</td>
                                <td class="ui-end" data-label="{{ ___('Payment') }}">{{ $row['k']==='p' ? $cur.number_format($row['amount'],2) : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="ui-text-2 ui-center">{{ ___('No charges yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr><th colspan="2" class="ui-end">{{ ___('Total charges') }}</th><th class="ui-end">{{ $cur.number_format($statement['total_charges'],2) }}</th><th></th></tr>
                        <tr><th colspan="2" class="ui-end">{{ ___('Total payments') }}</th><th></th><th class="ui-end">{{ $cur.number_format($statement['total_payments'],2) }}</th></tr>
                        <tr class="ui-table__total"><th colspan="2" class="ui-end">{{ ___('Balance due') }}</th><th colspan="2" class="ui-end">{{ $cur.number_format($statement['balance'],2) }}</th></tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div>
        @if($stay->isActive())
        <div class="ui-card">
            <div class="ui-card__head"><h4 class="ui-section-title">{{ ___('Record payment') }}</h4></div>
            <div class="ui-card__body">
                <form action="{{ route('hotel.folio.settle', $stay->id) }}" method="post" class="ui-stack">
                    @csrf
                    {{-- HP merge seam: A4 makes settle idempotent on a per-form-render uuid
                         (HotelFolioController::settle → hotel_folio:settle:{uuid}), so a
                         double-submitted form records ONE payment. The controller only gets
                         that protection if the form actually embeds the key. --}}
                    <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                    <div>
                        <label class="ui-label">{{ ___('Amount') }}</label>
                        <input type="number" step="0.01" min="0.01" name="amount" class="ui-input" value="{{ $statement['balance'] > 0 ? $statement['balance'] : '' }}" required>
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
            </div>
        </div>
        @endif

        {{-- HPMS-B6: post an extra service to this folio (A7's hotel.pms.services.post).
             Route::has-guarded so it stays hidden until lane A ships the PMS routes;
             the service list is supplied by the folio controller ($services) — pre-merge
             it is absent and the card does not render. Field names per SPEC §5:
             service_id, qty, idempotency_key (hidden per render → one post per submit). --}}
        @php $pmsServices = collect($services ?? []); @endphp
        @if($stay->isActive() && Route::has('hotel.pms.services.post'))
        <div class="ui-card">
            <div class="ui-card__head"><h4 class="ui-section-title">{{ ___('Post service') }}</h4></div>
            <div class="ui-card__body">
                @if($pmsServices->isEmpty())
                    <p class="ui-muted">
                        {{ ___('No extra services defined yet.') }}
                        @if(Route::has('hotel.pms.services'))
                            <a href="{{ route('hotel.pms.services') }}">{{ ___('Add services') }}</a>
                        @endif
                    </p>
                @else
                    <form action="{{ route('hotel.pms.services.post', $stay->id) }}" method="post" class="ui-stack">
                        @csrf
                        <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <div>
                            <label class="ui-label">{{ ___('Service') }}</label>
                            <select name="service_id" class="ui-input" required>
                                @foreach($pmsServices as $svc)
                                    <option value="{{ $svc->id }}">{{ $svc->name }} — {{ $cur }}{{ number_format((float) ($svc->price ?? 0), 2) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="ui-label">{{ ___('Quantity') }}</label>
                            <input type="number" name="qty" class="ui-input" value="1" min="1" step="1" required>
                        </div>
                        <x-ui.button type="submit" variant="secondary" icon="plus">{{ ___('Post to folio') }}</x-ui.button>
                    </form>
                @endif
            </div>
        </div>
        @endif

        {{-- HP-B3: manual charge / adjustment (A5's hotel.folio.adjust endpoint).
             Hidden until lane A ships the route (Route::has guard = pre-merge safe).
             Signed amount (positive = extra charge, negative = credit/void) + reason. --}}
        @if(Route::has('hotel.folio.adjust'))
        <div class="ui-card">
            <div class="ui-card__head"><h4 class="ui-section-title">{{ ___('Manual charge / adjustment') }}</h4></div>
            <div class="ui-card__body">
                <form action="{{ route('hotel.folio.adjust', $stay->id) }}" method="post" class="ui-stack">
                    @csrf
                    {{-- HP merge seam: A5's adjust endpoint is idempotent on the same uuid
                         contract as settle — embed a per-render key so a double-submit posts
                         ONE adjustment, not two. --}}
                    <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                    <div>
                        <label class="ui-label">{{ ___('Amount') }} <span class="ui-muted">({{ ___('negative to credit / void') }})</span></label>
                        <input type="number" step="0.01" name="amount" class="ui-input" placeholder="0.00" required>
                    </div>
                    <div>
                        <label class="ui-label">{{ ___('Reason') }}</label>
                        <input type="text" name="reason" class="ui-input" maxlength="255" required placeholder="{{ ___('e.g. Minibar, laundry, correction') }}">
                    </div>
                    <x-ui.button type="submit" variant="secondary" icon="pencil">{{ ___('Post adjustment') }}</x-ui.button>
                </form>
            </div>
        </div>
        @endif

        @if($stay->isActive())
        <div class="ui-card" x-data="{ typed: '' }">
            <div class="ui-card__head"><h4 class="ui-section-title">{{ ___('Check out') }}</h4></div>
            <div class="ui-card__body ui-stack">
                <form action="{{ route('hotel.folio.checkout', $stay->id) }}" method="post" data-confirm="{{ ___('Check this guest out?') }}">
                    @csrf
                    <p class="ui-muted">{{ ___('Requires a zero folio balance.') }}</p>
                    <x-ui.button type="submit" variant="secondary" icon="external">{{ ___('Check out') }}</x-ui.button>
                </form>
                @if($statement['balance'] > 0.001)
                <hr>
                {{-- HP-B3: type-to-confirm write-off — re-type the exact balance to arm the button. --}}
                <p class="ui-small ui-text-danger">{{ ___('Write off the remaining balance and check out. Type the balance amount to confirm.') }}</p>
                <input type="text" x-model="typed" class="ui-input ui-input--sm" inputmode="decimal"
                       placeholder="{{ number_format($statement['balance'], 2, '.', '') }}">
                <form action="{{ route('hotel.folio.checkout', $stay->id) }}" method="post">
                    @csrf
                    <input type="hidden" name="write_off" value="1">
                    {{-- Plain button: @js + :disabled bind reliably on a real element
                         (component-TAG attributes don't compile @js). --}}
                    <button type="submit" class="ui-btn ui-btn--danger ui-btn--sm"
                        :disabled="typed.trim() !== @js(number_format($statement['balance'], 2, '.', ''))">
                        {{ ___('Write off & check out') }}
                    </button>
                </form>
                @endif
            </div>
        </div>

        {{-- HP-B3: fix a guest-name typo (re-authorises charge-to-room matching) and
             cancel a mistaken zero-balance check-in. A11's endpoints (Route::has guards). --}}
        @if(Route::has('hotel.folio.stay.update'))
        <div class="ui-card">
            <div class="ui-card__head"><h4 class="ui-section-title">{{ ___('Edit stay') }}</h4></div>
            <div class="ui-card__body">
                <form action="{{ route('hotel.folio.stay.update', $stay->id) }}" method="post" class="ui-stack">
                    @csrf
                    <div>
                        <label class="ui-label">{{ ___('Guest name') }}</label>
                        <input type="text" name="guest_name" class="ui-input" value="{{ $stay->guest_name }}" maxlength="120" required>
                    </div>
                    <div>
                        <label class="ui-label">{{ ___('Phone') }}</label>
                        <input type="text" name="guest_phone" class="ui-input" value="{{ $stay->guest_phone }}" maxlength="40">
                    </div>
                    <x-ui.button type="submit" variant="ghost" icon="check">{{ ___('Save changes') }}</x-ui.button>
                </form>
            </div>
        </div>
        @endif

        @if(Route::has('hotel.folio.stay.cancel') && $statement['balance'] <= 0.001)
        <form action="{{ route('hotel.folio.stay.cancel', $stay->id) }}" method="post" data-confirm="{{ ___('Cancel this stay? Only allowed at a zero balance.') }}" data-confirm-danger>
            @csrf
            <x-ui.button type="submit" variant="ghost" size="sm" icon="error">{{ ___('Cancel stay') }}</x-ui.button>
        </form>
        @endif
        @else
        <div class="ui-card"><div class="ui-text-2 ui-card__body">{{ ___('This stay is closed.') }}</div></div>
        @endif
    </div>
</div>
@endsection

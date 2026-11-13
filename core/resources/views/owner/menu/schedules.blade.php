{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Schedules'))
@section('page_title', ___('Schedules'))
@section('page_sub', ___('Happy hours and day-part pricing, on your :noun clock.', ['noun' => store_noun(null, 'poss_gen')]))

@section('page_actions')
    @include('owner.partials.context-store-select', ['showBranch' => false])
@endsection

@section('content')

@if(session('success'))
    <div class="ui-note" data-tone="success">{{ session('success') }}</div>
@endif
@if(isset($errors) && $errors->any())
    <div class="ui-note" data-tone="danger">{{ $errors->first() }}</div>
@endif

@php
    $dayLabels = [
        'mon' => ___('Mon'), 'tue' => ___('Tue'), 'wed' => ___('Wed'), 'thu' => ___('Thu'),
        'fri' => ___('Fri'), 'sat' => ___('Sat'), 'sun' => ___('Sun'),
    ];
    $scopeLabels = [
        'store'    => ___('Whole menu'),
        'category' => ___('Selected categories'),
        'item'     => ___('Selected items'),
    ];
    $typeLabels = [
        'percent'     => ___('Percent off'),
        'amount'      => ___('Amount off'),
        'fixed_price' => ___('Fixed price'),
    ];
    $orderTypeLabels = [
        'on-table'     => ___('Dine-in'),
        'takeaway'     => ___('Takeaway'),
        'delivery'     => ___('Delivery'),
        'room_service' => ___('Room service'),
    ];
@endphp

<div class="ui-cluster ui-note ui-bordered ui-small" data-tone="neutral">
    <x-ui.icon name="clock" size="16" />
    <span>{{ ___('Times are your :noun clock', ['noun' => store_noun(null, 'poss_gen')]) }}: <strong>{{ $storeNow }}</strong> ({{ $timezone }})</span>
</div>

{{-- ── Storefront display ──────────────────────────────────────────────── --}}
<div class="ui-card"><div class="ui-card__body">
    <h3 class="ui-section-title">{{ ___('Out-of-hours items') }}</h3>
    <form method="post" action="{{ route('stores.schedules.options') }}?store_id={{ $rid }}" class="ui-cluster">
        @csrf
        <label class="ui-check">
            <input type="checkbox" name="menu_show_unavailable" value="1" @checked($showUnavailable)>
            <span>{{ ___('Show items outside their hours, greyed out') }}</span>
        </label>
        <button class="ui-btn ui-btn--secondary ui-btn--sm" type="submit">{{ ___('Save') }}</button>
    </form>
    <p class="ui-muted">{{ ___('Off (default) hides them. Either way they cannot be ordered outside their hours.') }}</p>
</div></div>

{{-- ── Existing schedules ──────────────────────────────────────────────── --}}
<div class="ui-card"><div class="ui-card__body">
    <h3 class="ui-section-title">{{ ___('Schedules') }}</h3>
    <p class="ui-muted">{{ ___('Schedules never stack — the cheapest matching one wins, and coupons still apply on top of the bill.') }}</p>

    @forelse($schedules as $s)
        @php
            $sDays = array_filter(explode(',', (string) $s->days));
            $sTypes = array_filter(explode(',', (string) $s->order_types));
            $sTargets = \Juwri\Core\Support\Views\OwnerOpsViewHelpers::scheduleTargetIdsAsInts($s->targets);
            $isLive = in_array($s->id, $liveIds, true);
        @endphp
        <div class="ui-bordered">
            <div class="ui-cluster">
                <strong>{{ $s->name }}</strong>
                @if($isLive)
                    <span class="ui-tag" data-tone="success">{{ ___('Active now') }}</span>
                @elseif(!$s->active)
                    <span class="ui-tag" data-tone="neutral">{{ ___('Disabled') }}</span>
                @else
                    <span class="ui-tag" data-tone="neutral">{{ ___('Scheduled') }}</span>
                @endif
                @if($s->label)<span class="ui-tag" data-tone="warning">{{ $s->label }}</span>@endif
            </div>

            <form method="post" action="{{ route('stores.schedules.update', $s->id) }}?store_id={{ $rid }}" class="ui-form-grid">
                @csrf
                <div>
                    <label class="ui-label" for="sched-name-{{ $s->id }}">{{ ___('Name') }}</label>
                    <input name="name" id="sched-name-{{ $s->id }}" class="ui-input" value="{{ $s->name }}" required maxlength="80">
                </div>
                <div>
                    <label class="ui-label" for="sched-label-{{ $s->id }}">{{ ___('Customer label') }}</label>
                    <input name="label" id="sched-label-{{ $s->id }}" class="ui-input" value="{{ $s->label }}" maxlength="60"
                           placeholder="{{ ___('Happy Hour') }}">
                </div>
                @if(count($menuLanguages) > 1)
                    @foreach($menuLanguages as $ml)
                    <div>
                        <label class="ui-label" for="sched-label-{{ $ml->code }}-{{ $s->id }}">{{ ___('Customer label') }} — {{ $ml->name }}</label>
                        <input name="label_i18n[{{ $ml->code }}]" id="sched-label-{{ $ml->code }}-{{ $s->id }}" class="ui-input" maxlength="60"
                               value="{{ $s->label_translations->{$ml->code} ?? '' }}"
                               placeholder="{{ $s->label ?: ___('Happy Hour') }}">
                    </div>
                    @endforeach
                @endif
                <div>
                    <label class="ui-label" for="sched-priority-{{ $s->id }}">{{ ___('Priority') }}</label>
                    <input name="priority" id="sched-priority-{{ $s->id }}" type="number" min="0" max="9999" class="ui-input" value="{{ (int) $s->priority }}">
                </div>
                <div class="ui-cluster">
                    <label class="ui-check">
                        <input type="checkbox" name="active" value="1" @checked($s->active)>
                        <span>{{ ___('Enable') }}</span>
                    </label>
                </div>

                <div>
                    <label class="ui-label" for="sched-scope-{{ $s->id }}">{{ ___('Applies to') }}</label>
                    <select name="scope" id="sched-scope-{{ $s->id }}" class="ui-input" data-schedule-scope>
                        @foreach($scopes as $sc)
                            <option value="{{ $sc }}" @selected($s->scope === $sc)>{{ $scopeLabels[$sc] ?? $sc }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="ui-label" for="sched-type-{{ $s->id }}">{{ ___('Discount type') }}</label>
                    <select name="adjustment_type" id="sched-type-{{ $s->id }}" class="ui-input">
                        @foreach($types as $t)
                            <option value="{{ $t }}" @selected($s->adjustment_type === $t)>{{ $typeLabels[$t] ?? $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="ui-label" for="sched-value-{{ $s->id }}">{{ ___('Value') }}</label>
                    <input name="adjustment_value" id="sched-value-{{ $s->id }}" type="number" step="0.001" min="0" class="ui-input"
                           value="{{ $s->adjustment_value }}" required>
                </div>

                <div class="ui-form-grid__full" data-schedule-targets>
                    <label class="ui-label" for="sched-targets-{{ $s->id }}">{{ ___('Categories or items') }}</label>
                    <select name="target_ids[]" id="sched-targets-{{ $s->id }}" class="ui-input" multiple size="5">
                        <optgroup label="{{ ___('Categories') }}" data-target-group="category">
                            @foreach($categories as $c)
                                <option value="{{ $c->id }}"
                                        @selected($s->scope === 'category' && in_array((int) $c->id, $sTargets, true))>{{ $c->name }}</option>
                            @endforeach
                        </optgroup>
                        <optgroup label="{{ ___('Menu') }}" data-target-group="item">
                            @foreach($items as $m)
                                <option value="{{ $m->id }}"
                                        @selected($s->scope === 'item' && in_array((int) $m->id, $sTargets, true))>{{ $m->name }}</option>
                            @endforeach
                        </optgroup>
                    </select>
                    <div class="ui-field__help">{{ ___('Ignored when the schedule applies to the whole menu.') }}</div>
                </div>

                <div>
                    <label class="ui-label" for="sched-starts-at-{{ $s->id }}">{{ ___('From') }}</label>
                    <input name="starts_at" id="sched-starts-at-{{ $s->id }}" type="time" class="ui-input" value="{{ $s->starts_at ? substr($s->starts_at, 0, 5) : '' }}">
                </div>
                <div>
                    <label class="ui-label" for="sched-ends-at-{{ $s->id }}">{{ ___('To') }}</label>
                    <input name="ends_at" id="sched-ends-at-{{ $s->id }}" type="time" class="ui-input" value="{{ $s->ends_at ? substr($s->ends_at, 0, 5) : '' }}">
                </div>
                <div>
                    <label class="ui-label" for="sched-starts-on-{{ $s->id }}">{{ ___('Starts on') }}</label>
                    <input name="starts_on" id="sched-starts-on-{{ $s->id }}" type="date" class="ui-input" value="{{ $s->starts_on?->format('Y-m-d') }}">
                </div>
                <div>
                    <label class="ui-label" for="sched-ends-on-{{ $s->id }}">{{ ___('Ends on') }}</label>
                    <input name="ends_on" id="sched-ends-on-{{ $s->id }}" type="date" class="ui-input" value="{{ $s->ends_on?->format('Y-m-d') }}">
                </div>

                <div>
                    <div class="ui-label">{{ ___('Days') }}</div>
                    <div class="ui-cluster">
                        @foreach($dayCodes as $d)
                            <label class="ui-check">
                                <input type="checkbox" name="days[]" value="{{ $d }}"
                                       @checked(in_array($d, $sDays, true))>
                                <span>{{ $dayLabels[$d] }}</span>
                            </label>
                        @endforeach
                    </div>
                    <div class="ui-field__help">{{ ___('No day selected means every day.') }}</div>
                </div>
                <div>
                    <div class="ui-label">{{ ___('Order types') }}</div>
                    <div class="ui-cluster">
                        @foreach($orderTypes as $ot)
                            <label class="ui-check">
                                <input type="checkbox" name="order_types[]" value="{{ $ot }}"
                                       @checked(in_array($ot, $sTypes, true))>
                                <span>{{ $orderTypeLabels[$ot] ?? $ot }}</span>
                            </label>
                        @endforeach
                    </div>
                    <div class="ui-field__help">{{ ___('No type selected means every order type.') }}</div>
                </div>

                <div class="ui-form-grid__full ui-cluster">
                    <button class="ui-btn ui-btn--sm" type="submit">{{ ___('Save') }}</button>
                </div>
            </form>

            <div class="ui-cluster">
                <form method="post" action="{{ route('stores.schedules.toggle', $s->id) }}?store_id={{ $rid }}">
                    @csrf
                    <button class="ui-btn ui-btn--secondary ui-btn--sm" type="submit">
                        {{ $s->active ? ___('Disable') : ___('Enable') }}
                    </button>
                </form>
                <form method="post" action="{{ route('stores.schedules.destroy', $s->id) }}?store_id={{ $rid }}"
                      data-confirm="{{ ___('Delete this schedule? Slots stop being offered immediately.') }}" data-confirm-danger>
                    @csrf @method('DELETE')
                    <button class="ui-btn ui-btn--danger ui-btn--sm" type="submit">{{ ___('Delete') }}</button>
                </form>
            </div>
        </div>
    @empty
        <p class="ui-text-2">{{ ___('No schedules yet.') }}</p>
    @endforelse
</div></div>

{{-- ── New schedule ────────────────────────────────────────────────────── --}}
<div class="ui-card"><div class="ui-card__body">
    <h3 class="ui-section-title">{{ ___('Add a schedule') }}</h3>
    <form method="post" action="{{ route('stores.schedules.store') }}?store_id={{ $rid }}" class="ui-form-grid">
        @csrf
        <div>
            <label class="ui-label" for="sched-name-new">{{ ___('Name') }}</label>
            <input name="name" id="sched-name-new" class="ui-input" required maxlength="80" placeholder="{{ ___('Happy Hour') }}">
        </div>
        <div>
            <label class="ui-label" for="sched-label-new">{{ ___('Customer label') }}</label>
            <input name="label" id="sched-label-new" class="ui-input" maxlength="60" placeholder="{{ ___('Happy Hour') }}">
        </div>
        @if(count($menuLanguages) > 1)
            @foreach($menuLanguages as $ml)
            <div>
                <label class="ui-label" for="sched-label-{{ $ml->code }}-new">{{ ___('Customer label') }} — {{ $ml->name }}</label>
                <input name="label_i18n[{{ $ml->code }}]" id="sched-label-{{ $ml->code }}-new" class="ui-input" maxlength="60" placeholder="{{ ___('Happy Hour') }}">
            </div>
            @endforeach
        @endif
        <div>
            <label class="ui-label" for="sched-priority-new">{{ ___('Priority') }}</label>
            <input name="priority" id="sched-priority-new" type="number" min="0" max="9999" class="ui-input" value="0">
        </div>
        <div class="ui-cluster">
            <label class="ui-check">
                <input type="checkbox" name="active" value="1" checked>
                <span>{{ ___('Enable') }}</span>
            </label>
        </div>

        <div>
            <label class="ui-label" for="sched-scope-new">{{ ___('Applies to') }}</label>
            <select name="scope" id="sched-scope-new" class="ui-input" data-schedule-scope>
                @foreach($scopes as $sc)
                    <option value="{{ $sc }}">{{ $scopeLabels[$sc] ?? $sc }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="ui-label" for="sched-type-new">{{ ___('Discount type') }}</label>
            <select name="adjustment_type" id="sched-type-new" class="ui-input">
                @foreach($types as $t)
                    <option value="{{ $t }}">{{ $typeLabels[$t] ?? $t }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="ui-label" for="sched-value-new">{{ ___('Value') }}</label>
            <input name="adjustment_value" id="sched-value-new" type="number" step="0.001" min="0" class="ui-input" value="0" required>
        </div>

        <div class="ui-form-grid__full" data-schedule-targets>
            <label class="ui-label" for="sched-targets-new">{{ ___('Categories or items') }}</label>
            <select name="target_ids[]" id="sched-targets-new" class="ui-input" multiple size="5">
                <optgroup label="{{ ___('Categories') }}" data-target-group="category">
                    @foreach($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </optgroup>
                <optgroup label="{{ ___('Menu') }}" data-target-group="item">
                    @foreach($items as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach
                </optgroup>
            </select>
        </div>

        <div>
            <label class="ui-label" for="sched-starts-at-new">{{ ___('From') }}</label>
            <input name="starts_at" id="sched-starts-at-new" type="time" class="ui-input">
        </div>
        <div>
            <label class="ui-label" for="sched-ends-at-new">{{ ___('To') }}</label>
            <input name="ends_at" id="sched-ends-at-new" type="time" class="ui-input">
        </div>
        <div>
            <label class="ui-label" for="sched-starts-on-new">{{ ___('Starts on') }}</label>
            <input name="starts_on" id="sched-starts-on-new" type="date" class="ui-input">
        </div>
        <div>
            <label class="ui-label" for="sched-ends-on-new">{{ ___('Ends on') }}</label>
            <input name="ends_on" id="sched-ends-on-new" type="date" class="ui-input">
        </div>

        <div>
            <div class="ui-label">{{ ___('Days') }}</div>
            <div class="ui-cluster">
                @foreach($dayCodes as $d)
                    <label class="ui-check">
                        <input type="checkbox" name="days[]" value="{{ $d }}">
                        <span>{{ $dayLabels[$d] }}</span>
                    </label>
                @endforeach
            </div>
        </div>
        <div>
            <div class="ui-label">{{ ___('Order types') }}</div>
            <div class="ui-cluster">
                @foreach($orderTypes as $ot)
                    <label class="ui-check">
                        <input type="checkbox" name="order_types[]" value="{{ $ot }}">
                        <span>{{ $orderTypeLabels[$ot] ?? $ot }}</span>
                    </label>
                @endforeach
            </div>
        </div>

        <div class="ui-form-grid__full">
            <button class="ui-btn" type="submit">{{ ___('Add a schedule') }}</button>
        </div>
    </form>
</div></div>

@push('scripts')
<script nonce="{{ csp_nonce() }}">
/* Show the target picker only for a narrowed scope, and show only the group
   that scope means. Progressive enhancement: with JS off the picker is simply
   always visible and the server drops targets for a store-wide rule anyway. */
(function () {
    function sync(select) {
        var form = select.closest('form');
        if (!form) return;
        var wrap = form.querySelector('[data-schedule-targets]');
        if (!wrap) return;
        var scope = select.value;
        wrap.style.display = scope === 'store' ? 'none' : '';
        Array.prototype.forEach.call(wrap.querySelectorAll('[data-target-group]'), function (g) {
            g.disabled = g.getAttribute('data-target-group') !== scope;
            g.style.display = g.disabled ? 'none' : '';
        });
    }
    Array.prototype.forEach.call(document.querySelectorAll('[data-schedule-scope]'), function (select) {
        select.addEventListener('change', function () { sync(select); });
        sync(select);
    });
})();
</script>
@endpush

@endsection

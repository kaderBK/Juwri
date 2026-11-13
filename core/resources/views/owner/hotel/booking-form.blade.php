{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    HPMS-B2 — reusable booking create/edit form (a partial, not a page).
    Consumed by bookings.blade (create) and booking-show.blade (edit).

    Expected @include data:
      $action     (string)  pre-resolved form action URL (route() called by the caller,
                            behind Route::has(); the partial never resolves it itself)
      $booking    (object|null) existing booking for edit, or null for create
      $roomTypes  (iterable) plain objects: id, name, base_rate, max_occupancy
      $ratePlans  (iterable) plain objects: id, name
      $rooms      (iterable) plain objects: id, table_no, label
      $currency   (string)  store currency code, pinned at quote time
      $availUrl   (string|null) GET availability endpoint, or null pre-merge

    Field names are the SPEC §5 contract — guest_name, guest_phone, room_type_id,
    rate_plan_id, room_id, check_in, check_out, adults, children, notes,
    idempotency_key. A missing field ships an inert feature (hotel-polish lesson).
--}}
@php
    $booking   = $booking ?? null;
    $roomTypes = $roomTypes ?? [];
    $ratePlans = $ratePlans ?? [];
    $rooms     = $rooms ?? [];
    $currency  = $currency ?? '';
    $availUrl  = $availUrl ?? null;
    $isEdit    = (bool) ($booking->id ?? false);
@endphp

<form action="{{ $action }}" method="post" class="ui-stack"
      x-data="pmsBookingForm()" data-avail-url="{{ $availUrl }}" data-currency="{{ $currency }}">
    @csrf
    {{-- Money-adjacent POST: a per-render idempotency key so a double-submit creates
         ONE booking / one confirm (SPEC §5 — hidden on every money-adjacent form). --}}
    <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">

    <div class="ui-form-grid">
        <div>
            <label class="ui-label">{{ ___('Guest name') }}</label>
            <input type="text" name="guest_name" class="ui-input" maxlength="120" required value="{{ \Juwri\Core\Support\Views\OwnerOpsViewHelpers::bookingFormVal($booking, 'guest_name') }}">
        </div>
        <div>
            <label class="ui-label">{{ ___('Phone (optional)') }}</label>
            <input type="text" name="guest_phone" class="ui-input" maxlength="40" value="{{ \Juwri\Core\Support\Views\OwnerOpsViewHelpers::bookingFormVal($booking, 'guest_phone') }}">
        </div>
    </div>

    <div class="ui-form-grid">
        <div>
            <label class="ui-label">{{ ___('Room type') }}</label>
            <select name="room_type_id" class="ui-input" required x-model="roomTypeId" @change="refreshQuote()">
                <option value="">{{ ___('Select a type') }}</option>
                @foreach($roomTypes as $t)
                    <option value="{{ $t->id }}" @selected((int) \Juwri\Core\Support\Views\OwnerOpsViewHelpers::bookingFormVal($booking, 'room_type_id') === (int) $t->id)>{{ $t->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="ui-label">{{ ___('Rate plan') }}</label>
            <select name="rate_plan_id" class="ui-input">
                <option value="">{{ ___('Standard') }}</option>
                @foreach($ratePlans as $p)
                    <option value="{{ $p->id }}" @selected((int) \Juwri\Core\Support\Views\OwnerOpsViewHelpers::bookingFormVal($booking, 'rate_plan_id') === (int) $p->id)>{{ $p->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="ui-label">{{ ___('Room (optional)') }}</label>
            <select name="room_id" class="ui-input">
                <option value="">{{ ___('Assign later') }}</option>
                @foreach($rooms as $r)
                    <option value="{{ $r->id }}" @selected((int) \Juwri\Core\Support\Views\OwnerOpsViewHelpers::bookingFormVal($booking, 'room_id') === (int) $r->id)>#{{ $r->table_no }}{{ ($r->label ?? '') ? ' · '.$r->label : '' }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="ui-form-grid">
        <div>
            <label class="ui-label">{{ ___('Check-in') }}</label>
            <input type="date" name="check_in" class="ui-input" required value="{{ \Juwri\Core\Support\Views\OwnerOpsViewHelpers::bookingFormVal($booking, 'check_in') }}" x-model="checkIn" @change="refreshQuote()">
        </div>
        <div>
            <label class="ui-label">{{ ___('Check-out') }}</label>
            <input type="date" name="check_out" class="ui-input" required value="{{ \Juwri\Core\Support\Views\OwnerOpsViewHelpers::bookingFormVal($booking, 'check_out') }}" x-model="checkOut" @change="refreshQuote()">
        </div>
        <div>
            <label class="ui-label">{{ ___('Adults') }}</label>
            <input type="number" name="adults" class="ui-input" min="1" max="20" value="{{ \Juwri\Core\Support\Views\OwnerOpsViewHelpers::bookingFormVal($booking, 'adults', 2) }}">
        </div>
        <div>
            <label class="ui-label">{{ ___('Children') }}</label>
            <input type="number" name="children" class="ui-input" min="0" max="20" value="{{ \Juwri\Core\Support\Views\OwnerOpsViewHelpers::bookingFormVal($booking, 'children', 0) }}">
        </div>
    </div>

    <div>
        <label class="ui-label">{{ ___('Notes') }}</label>
        <textarea name="notes" class="ui-input" rows="2" maxlength="1000">{{ \Juwri\Core\Support\Views\OwnerOpsViewHelpers::bookingFormVal($booking, 'notes') }}</textarea>
    </div>

    {{-- Live availability + quote. The panel is only wired when lane A's availability
         route exists (Route::has via $availUrl); pre-merge the JS no-ops and the form
         still submits a plain booking (server re-quotes authoritatively). --}}
    <div class="ui-cluster ui-cluster--between ui-note ui-bordered" data-tone="neutral"
         x-show="quoteState !== 'idle'" x-cloak>
        <span x-show="quoteState === 'loading'" class="ui-text-2">{{ ___('Checking availability…') }}</span>
        <template x-if="quoteState === 'ok'">
            <span>
                <span class="ui-tag" :data-tone="available > 0 ? 'success' : 'danger'"
                      x-text="available > 0 ? @js(___('Available')) + ' (' + available + ')' : @js(___('No rooms free'))"></span>
                <span>{{ ___('Estimated total') }}: <strong><span x-text="currency"></span> <span x-text="nightlyTotal"></span></strong>
                    <span class="ui-muted">(<span x-text="nights"></span> {{ ___('nights') }})</span>
                </span>
            </span>
        </template>
        <span x-show="quoteState === 'error'" class="ui-text-danger">{{ ___('Could not check availability — the booking still saves and re-prices on the server.') }}</span>
    </div>

    <div class="ui-cluster ui-cluster--end">
        <x-ui.button type="submit" icon="check">
            {{ $isEdit ? ___('Save booking') : ___('Create booking') }}
        </x-ui.button>
    </div>
</form>

@once
@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    function pmsBookingForm() {
        return {
            roomTypeId: '', checkIn: '', checkOut: '',
            quoteState: 'idle', available: 0, nightlyTotal: '', nights: 0, currency: '',
            init() {
                this.currency = this.$el.dataset.currency || '';
                // Seed from any server-rendered values (edit mode).
                this.roomTypeId = this.$el.querySelector('[name=room_type_id]')?.value || '';
                this.checkIn = this.$el.querySelector('[name=check_in]')?.value || '';
                this.checkOut = this.$el.querySelector('[name=check_out]')?.value || '';
            },
            refreshQuote() {
                const url = this.$el.dataset.availUrl;
                if (!url || !this.roomTypeId || !this.checkIn || !this.checkOut) { return; }
                this.quoteState = 'loading';
                const q = new URLSearchParams({ check_in: this.checkIn, check_out: this.checkOut, room_type_id: this.roomTypeId });
                fetch(url + '?' + q.toString(), { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then((data) => {
                        const types = (data && data.types) || [];
                        const row = types.find((t) => String(t.id) === String(this.roomTypeId));
                        if (!row) { this.quoteState = 'error'; return; }
                        this.available = row.available || 0;
                        this.nightlyTotal = row.nightly_total != null ? row.nightly_total : '';
                        this.nights = row.nights || 0;
                        this.currency = (data && data.currency) || this.currency;
                        this.quoteState = 'ok';
                    })
                    .catch(() => { this.quoteState = 'error'; });
            },
        };
    }
</script>
@endpush
@endonce

{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Hotel settings'))
@section('page_title', ___('Guest info hub'))
@section('page_sub', $store->title.' - '.___('Wi-Fi, house rules, breakfast and reception contact shown to guests.'))

@section('page_actions')
    <x-ui.button :href="route('hotel.guest.info', $store->slug)" target="_blank" variant="secondary" icon="external">{{ ___('Preview guest page') }}</x-ui.button>
@endsection

@php
    $wifi = $hotelSettings['wifi'] ?? [];
    $bf = $hotelSettings['breakfast'] ?? [];
    $contact = $hotelSettings['contact'] ?? [];
    $deptMap = $hotelSettings['departments'] ?? [];
    $typeLabels = ['housekeeping'=>___('Housekeeping'),'maintenance'=>___('Maintenance'),'amenity'=>___('Amenity'),'staff_call'=>___('Staff call'),'custom'=>___('Custom')];
@endphp

@section('content')
<div class="ui-stack" style="max-width:720px;">
    {{-- H11 — master module toggle (owner). Reachable whenever the plan grants hotel. --}}
    <div class="ui-card">
        <div class="ui-cluster ui-cluster--between ui-card__body">
            <div>
                <h3 class="ui-section-title">{{ ___('Hotel guest services') }}</h3>
                <p class="ui-muted">{{ $hotelOn ? ___('The hotel module is ON — rooms, stays and requests are visible.') : ___('Turn the hotel module on to show rooms, stays, folios and service requests.') }}</p>
            </div>
            <form action="{{ route('hotel.mode') }}" method="post"
                @if($hotelOn && ($openFolios['count'] ?? 0) > 0) data-confirm="{{ ___(':count guest(s) still have an open folio balance of :total. Turning the hotel module off blocks settling or checking them out until you turn it back on. Turn off anyway?', ['count' => $openFolios['count'], 'total' => $statementCurrency.number_format($openFolios['total'], 2)]) }}" data-confirm-danger @endif>
                @csrf
                <input type="hidden" name="hotel_mode" value="{{ $hotelOn ? '0' : '1' }}">
                {{-- HP-B6: the owner's explicit confirm for the wind-down guard in toggleMode(). --}}
                @if($hotelOn)<input type="hidden" name="confirm" value="1">@endif
                <x-ui.button type="submit" :variant="$hotelOn ? 'secondary' : 'primary'" :icon-left="$hotelOn ? 'toggle-right' : 'toggle-left'">
                    {{ $hotelOn ? ___('Turn off') : ___('Turn on') }}
                </x-ui.button>
            </form>
        </div>
        @if($hotelOn && ($openFolios['count'] ?? 0) > 0)
            <div class="ui-card__foot">
                <div class="ui-cluster ui-cluster--between ui-note" data-tone="warning">
                    <span>{{ ___(':count guest(s) checked in with an open folio balance of :total.', ['count' => $openFolios['count'], 'total' => $statementCurrency.number_format($openFolios['total'], 2)]) }}</span>
                    <a href="{{ route('hotel.stays') }}">{{ ___('Review stays') }}</a>
                </div>
            </div>
        @endif
    </div>

<form action="{{ route('hotel.info.save') }}" method="post" class="ui-stack">
    @csrf
    {{-- HPMS-B8 — booking engine: online-booking mode, no-show policy, waitlist turn.
         $booking is supplied by HotelInfoController::edit at the SPEC §5 defaults. --}}
    @php $booking = $booking ?? ['online' => 'off', 'noshow_grace' => '18', 'noshow_fee' => '0', 'waitlist_turn' => '20']; @endphp
    <div class="ui-card">
        <div class="ui-card__head"><h3 class="ui-section-title">{{ ___('Online booking & reservations') }}</h3></div>
        <div class="ui-card__body ui-form-grid">
            <div>
                <label class="ui-label" for="hotel-online-booking">{{ ___('Online booking mode') }}</label>
                <select name="hotel_online_booking" id="hotel-online-booking" class="ui-input">
                    <option value="off" @selected(($booking['online'] ?? 'off') === 'off')>{{ ___('Off — no public booking page') }}</option>
                    <option value="request" @selected(($booking['online'] ?? '') === 'request')>{{ ___('Request — guests ask, you confirm') }}</option>
                    <option value="instant" @selected(($booking['online'] ?? '') === 'instant')>{{ ___('Instant — confirmed when a room is free') }}</option>
                </select>
                <div class="ui-field__help">{{ ___('Off hides the public booking page. Request creates a pending booking you confirm. Instant confirms immediately when a room is available (no deposit is taken).') }}</div>
            </div>
            <div>
                <label class="ui-label" for="waitlist-avg-turn-minutes">{{ ___('Waitlist: minutes per table turn') }}</label>
                <input type="number" name="waitlist_avg_turn_minutes" id="waitlist-avg-turn-minutes" class="ui-input" value="{{ $booking['waitlist_turn'] ?? '20' }}" min="1" max="600">
                <div class="ui-field__help">{{ ___('Used to estimate the wait quoted to walk-in guests on the waitlist.') }}</div>
            </div>
            <div>
                <label class="ui-label" for="hotel-noshow-grace-hours">{{ ___('No-show grace period (hours)') }}</label>
                <input type="number" name="hotel_noshow_grace_hours" id="hotel-noshow-grace-hours" class="ui-input" value="{{ $booking['noshow_grace'] ?? '18' }}" min="0" max="240">
                <div class="ui-field__help">{{ ___('Hours after the arrival date before a confirmed booking is automatically marked as a no-show.') }}</div>
            </div>
            <div>
                <label class="ui-label" for="hotel-noshow-fee">{{ ___('No-show fee') }} <span class="ui-muted">{{ $statementCurrency }}</span></label>
                <input type="number" name="hotel_noshow_fee" id="hotel-noshow-fee" class="ui-input" value="{{ $booking['noshow_fee'] ?? '0' }}" step="0.01" min="0">
                <div class="ui-field__help">{{ ___('Recorded as an alert when a booking is marked no-show — not charged automatically (no card is held).') }}</div>
            </div>
        </div>
    </div>

    <div class="ui-card">
        <div class="ui-card__head"><h3 class="ui-section-title">{{ ___('Wi-Fi') }}</h3></div>
        <div class="ui-card__body ui-form-grid">
            <div><label class="ui-label" for="wifi-name">{{ ___('Network name') }}</label><input type="text" id="wifi-name" name="wifi_name" class="ui-input" value="{{ $wifi['name'] ?? '' }}"></div>
            <div><label class="ui-label" for="wifi-password">{{ ___('Password') }}</label><input type="text" id="wifi-password" name="wifi_password" class="ui-input" value="{{ $wifi['password'] ?? '' }}"></div>
        </div>
    </div>

    <div class="ui-card">
        <div class="ui-card__head"><h3 class="ui-section-title">{{ ___('House rules') }}</h3></div>
        <div class="ui-card__body">
            <textarea name="house_rules" class="ui-input" rows="4">{{ $hotelSettings['house_rules'] ?? '' }}</textarea>
        </div>
    </div>

    <div class="ui-card">
        <div class="ui-card__head"><h3 class="ui-section-title">{{ ___('Breakfast') }}</h3></div>
        <div class="ui-card__body ui-form-grid">
            <div><label class="ui-label" for="breakfast-hours">{{ ___('Hours') }}</label><input type="text" id="breakfast-hours" name="breakfast_hours" class="ui-input" value="{{ $bf['hours'] ?? '' }}" placeholder="07:00 – 10:30"></div>
            <div><label class="ui-label" for="breakfast-cutoff">{{ ___('Pre-order cutoff') }}</label><input type="text" id="breakfast-cutoff" name="breakfast_cutoff" class="ui-input" value="{{ $bf['cutoff'] ?? '' }}" placeholder="22:00"></div>
        </div>
    </div>

    <div class="ui-card">
        <div class="ui-card__head"><h3 class="ui-section-title">{{ ___('Reception contact') }}</h3></div>
        <div class="ui-card__body ui-form-grid">
            <div><label class="ui-label" for="contact-phone">{{ ___('Phone') }}</label><input type="text" id="contact-phone" name="contact_phone" class="ui-input" value="{{ $contact['phone'] ?? '' }}"></div>
            <div><label class="ui-label" for="contact-whatsapp">{{ ___('WhatsApp') }}</label><input type="text" id="contact-whatsapp" name="contact_whatsapp" class="ui-input" value="{{ $contact['whatsapp'] ?? '' }}"></div>
            <div class="ui-form-grid__full"><label class="ui-label" for="contact-note">{{ ___('Note') }}</label><input type="text" id="contact-note" name="contact_note" class="ui-input" value="{{ $contact['note'] ?? '' }}"></div>
        </div>
    </div>

    {{-- HP-B6 — Charge to room: tray charge + token binding. --}}
    <div class="ui-card">
        <div class="ui-card__head"><h3 class="ui-section-title">{{ ___('Charge to room') }}</h3></div>
        <div class="ui-card__body ui-form-grid">
            <div>
                <label class="ui-label" for="hotel-tray-charge">{{ ___('Tray / service charge') }}</label>
                <input type="text" name="hotel_tray_charge" id="hotel-tray-charge" class="ui-input" value="{{ $trayCharge }}" placeholder="{{ ___('e.g. 5 or 10%') }}">
                <div class="ui-field__help">{{ ___('Added to every room-service order. A flat amount ("5") or a percentage of the subtotal ("10%").') }}</div>
            </div>
            <div>
                <div class="ui-label ui-block">{{ ___('Room token binding') }}</div>
                <input type="hidden" name="token_required" value="0">
                <div class="ui-check">
                    <input type="checkbox" id="tokenRequired" name="token_required" value="1" @checked($tokenRequired)>
                    <label for="tokenRequired">{{ ___('Require the scanned room QR token to charge to a room') }}</label>
                </div>
                <div class="ui-field__help">{{ ___('On by default. A guest can only charge to a room from the QR code in that room and must give the full name on the reservation. Reprint older room QR codes so they carry the token.') }}</div>
            </div>
        </div>
    </div>

    {{-- HP-B6 — Property management system (PMS) connector. Secrets are never
         re-displayed; leave a token blank to keep the stored value. --}}
    <div class="ui-card">
        <div class="ui-card__head"><h3 class="ui-section-title">{{ ___('Property management system (PMS)') }}</h3></div>
        <div class="ui-card__body ui-form-grid">
            <div>
                <label class="ui-label" for="pms-provider">{{ ___('Provider') }}</label>
                <select name="pms_provider" id="pms-provider" class="ui-input">
                    @foreach($pmsProviders as $val => $lbl)
                        <option value="{{ $val }}" @selected(($pms['provider'] ?? 'null') === $val)>{{ $lbl }}</option>
                    @endforeach
                </select>
                <div class="ui-field__help">{{ ___('Mirror folio charges to your PMS. Leave as “None” to keep folios local.') }}</div>
            </div>
            <div>
                <label class="ui-label" for="pms-base-url">{{ ___('Base URL') }}</label>
                <input type="text" name="pms_base_url" id="pms-base-url" class="ui-input" value="{{ $pms['base_url'] ?? '' }}" placeholder="https://api.mews.com">
            </div>
            <div>
                <label class="ui-label" for="pms-service-id">{{ ___('Service ID') }}</label>
                <input type="text" name="pms_service_id" id="pms-service-id" class="ui-input" value="{{ $pms['service_id'] ?? '' }}">
            </div>
            <div>
                <label class="ui-label" for="pms-client-token">{{ ___('Client token') }}</label>
                <input type="password" name="pms_client_token" id="pms-client-token" class="ui-input" autocomplete="off" placeholder="{{ ($pms['has_client_token'] ?? false) ? '•••••••• ('.___('saved').')' : '' }}">
            </div>
            <div>
                <label class="ui-label" for="pms-access-token">{{ ___('Access token') }}</label>
                <input type="password" name="pms_access_token" id="pms-access-token" class="ui-input" autocomplete="off" placeholder="{{ ($pms['has_access_token'] ?? false) ? '•••••••• ('.___('saved').')' : '' }}">
            </div>
        </div>
    </div>

    <div class="ui-card">
        <div class="ui-card__head"><h3 class="ui-section-title">{{ ___('Department routing') }}</h3></div>
        <div class="ui-card__body">
            <p class="ui-muted">{{ ___('Route each request type to a kitchen station acting as a department.') }}</p>
            @if($departments->where('active', true)->isEmpty())
                {{-- HP-B5: no stations = every request routes to nobody. Point the owner
                     at where departments are actually managed (kitchen stations). --}}
                <div class="ui-note" data-tone="accent">
                    {{ ___('Departments are managed as kitchen stations. Create a station first, then route request types to it here.') }}
                    @if(Route::has('stations.index'))
                        <a href="{{ route('stations.index') }}">{{ ___('Manage kitchen stations') }}</a>
                    @endif
                </div>
            @endif
            <div class="ui-form-grid">
                @foreach($typeLabels as $type => $lbl)
                    <div>
                        <label class="ui-label" for="dept-{{ $type }}">{{ $lbl }}</label>
                        <select name="departments[{{ $type }}]" id="dept-{{ $type }}" class="ui-input">
                            <option value="">{{ ___('Unassigned') }}</option>
                            @foreach($departments as $dept)
                                <option value="{{ $dept->id }}" @selected((int)($deptMap[$type] ?? 0) === (int)$dept->id)>{{ $dept->name }}{{ $dept->active ? '' : ' ('.___('inactive').')' }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- HP-B7 — where new service requests alert staff. --}}
    <div class="ui-card">
        <div class="ui-card__head"><h3 class="ui-section-title">{{ ___('Request alerts') }}</h3></div>
        <div class="ui-card__body ui-stack">
            <input type="hidden" name="alerts_notif" value="0">
            <div class="ui-check">
                <input type="checkbox" id="alertsNotif" name="alerts_notif" value="1" @checked($alerts['notif'])>
                <label for="alertsNotif">{{ ___('Dashboard notification centre') }}</label>
            </div>
            <input type="hidden" name="alerts_whatsapp" value="0">
            <div class="ui-check">
                <input type="checkbox" id="alertsWhatsapp" name="alerts_whatsapp" value="1" @checked($alerts['whatsapp'])>
                <label for="alertsWhatsapp">{{ ___('WhatsApp to the store number') }}</label>
            </div>
            <input type="hidden" name="alerts_push" value="0">
            <div class="ui-check">
                <input type="checkbox" id="alertsPush" name="alerts_push" value="1" @checked($alerts['push'])>
                <label for="alertsPush">{{ ___('Web push') }}</label>
            </div>
            <div class="ui-field__help">{{ ___('Web push requires web push (VAPID) setup before notifications are delivered.') }}</div>
        </div>
    </div>

    <div><x-ui.button type="submit" icon="check">{{ ___('Save') }}</x-ui.button></div>
</form>
</div>
@endsection

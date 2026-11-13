{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Theme Studio B0.7 — {% widget 'waitlist' %}. Join the walk-in waitlist via the
    SAME public endpoint the Blade themes post to (publicView.waitlist.join,
    WaitlistController@publicJoin → back() with the `waitlist_joined` flash).
    WidgetTag gates it on features.waitlist (= postOptions.enable_waitlist).
--}}
@php
    $mbwId = 'mbw-waitlist';
    $mbwErrors = isset($errors) && old('_mbw') === 'waitlist' ? $errors : null;
    $mbwJoined = session('waitlist_joined');
@endphp
@if($widgetBase)@include('store_templates._partials.widgets._base')@endif
<button type="button" class="mbw-btn mbw-waitlist__trigger" data-mbw-open="waitlist" aria-haspopup="dialog" aria-controls="{{ $mbwId }}-dialog">
    <svg class="mbw-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    <span>{{ $widgetLabel ?? ___('Join the Waitlist') }}</span>
</button>
@if($widgetFirst)
<dialog class="mbw-dialog mbw-waitlist" id="{{ $mbwId }}-dialog" aria-labelledby="{{ $mbwId }}-title" @if($mbwJoined || ($mbwErrors && $mbwErrors->any())) data-mbw-autoopen @endif>
    @include('store_templates._partials.widgets._head', ['mbwTitle' => ___('Join the Waitlist')])
    <div class="mbw-body">
        @if(is_array($mbwJoined))
            <div class="mbw-done" role="status">
                <b>{{ ___("You're on the list!") }}</b>
                <p>{{ ___('Position :pos · ~:min min wait', ['pos' => $mbwJoined['position'] ?? '', 'min' => $mbwJoined['wait_minutes'] ?? '']) }}</p>
            </div>
        @else
            @if($mbwErrors && $mbwErrors->any())
                <p class="mbw-alert" role="alert">{{ $mbwErrors->first() }}</p>
            @endif
            <form class="mbw-form" action="{{ route('publicView.waitlist.join', $store->slug) }}" method="POST">
                @csrf
                <input type="hidden" name="_mbw" value="waitlist">
                <label class="mbw-field">{{ ___('Your Name') }}
                    <input class="mbw-input" name="name" required maxlength="120" autocomplete="name" value="{{ old('name', $customer->name ?? '') }}">
                </label>
                <label class="mbw-field">{{ ___('Phone number') }} ({{ ___('optional') }})
                    <input class="mbw-input" name="phone" maxlength="30" inputmode="tel" autocomplete="tel" dir="ltr" value="{{ old('phone', $customer->phone ?? '') }}">
                </label>
                <label class="mbw-field">{{ ___('Party Size') }}
                    <input class="mbw-input" name="party_size" type="number" min="1" max="50" required inputmode="numeric" value="{{ old('party_size', 2) }}">
                </label>
                <button class="mbw-btn mbw-submit" type="submit">{{ ___('Join Waitlist') }}</button>
            </form>
        @endif
    </div>
</dialog>
@endif

{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- book: staff, day and time pickers of the choose-a-time pages. Staff and day belong to the refresh form (GET: the day's times
     are the server's), the time to the hold form (POST). $pre is the id prefix. --}}
@php
    $__c = $vm['page']['checkout'];
    $__sl = $__c['slots'];
    $__staff = $__c['staff'] ?? [];
@endphp
@if ($__staff)
<section class="c-pick" aria-labelledby="{{ $pre }}-staff-h"><h2 class="c-pick__title" id="{{ $pre }}-staff-h">{{ $kit->s('Who would you like?') }}</h2>
    <div class="c-staff" role="radiogroup" aria-labelledby="{{ $pre }}-staff-h">
    @foreach ($__staff as $__m)
        <label class="c-staff__opt"><input class="c-staff__input u-vh" type="radio" name="staff" value="{{ $__m['id'] }}" form="tp-refresh" @checked(($__c['selected_staff'] ?? 'any') === $__m['id'])><span class="c-staff__face"><span class="c-staff__ring">@if (! empty($__m['photo']))<img class="c-staff__img" src="{{ $__m['photo']['src'] }}" alt="" width="56" height="56" decoding="async" loading="lazy">@else{{ $kit->icon($__m['id'] === 'any' ? 'users' : 'user') }}@endif</span><span class="c-staff__name">{{ $__m['name'] }}</span>@if (! empty($__m['role']))<span class="c-staff__role">{{ $__m['role'] }}</span>@endif</span></label>
    @endforeach
    </div>
</section>
@endif
<section class="c-pick" aria-labelledby="{{ $pre }}-day-h"><div class="c-pick__head"><h2 class="c-pick__title" id="{{ $pre }}-day-h">{{ $__sl['title'] ?? '' }}</h2></div>
    <div class="c-days" role="radiogroup" aria-labelledby="{{ $pre }}-day-h">
    @foreach ($__sl['days'] ?? [] as $__d)
        <label class="c-day{{ $__d['state'] === 'closed' ? ' is-closed' : '' }}"><input class="c-day__input u-vh" type="radio" name="date" value="{{ $__d['id'] }}" form="tp-refresh" @checked($__d['state'] === 'selected') @disabled($__d['state'] === 'closed')><span class="c-day__face"><span class="c-day__dow">{{ $__d['label'] }}</span><span class="c-day__num">@if ($__d['state'] === 'closed')<s>{{ $__d['sub'] }}</s><span class="u-sr">, {{ $kit->s('Closed') }}</span>@else{{ $__d['sub'] }}@endif</span><span class="c-day__check" aria-hidden="true">{{ $kit->icon('check') }}</span></span></label>
    @endforeach
    </div>
</section>
@foreach ($__sl['groups'] as $__gi => $__g)
<section class="c-pick" aria-labelledby="{{ $pre }}-slots-{{ $__gi }}"><h3 class="c-pick__title" id="{{ $pre }}-slots-{{ $__gi }}">{{ $__g['label'] }}</h3>
    <div class="c-slots" role="radiogroup" aria-labelledby="{{ $pre }}-slots-{{ $__gi }}">
    @foreach ($__g['slots'] as $__s)
        @php($__taken = $__s['state'] !== 'available')
        <label class="c-slot{{ $__taken ? ' is-taken' : '' }}"><input class="c-slot__input u-vh" type="radio" name="starts_at" value="{{ $__s['id'] }}" form="tp-hold" data-range="{{ $__s['sub'] ?? '' }}" @disabled($__taken) @required($loop->first && $__gi === 0)><span class="c-slot__face"><span class="c-slot__check" aria-hidden="true">{{ $kit->icon('check') }}</span><bdi class="c-slot__time">@if ($__taken)<s>{{ $__s['label'] }}</s><span class="u-sr">, {{ $kit->s('Taken') }}</span>@else{{ $__s['label'] }}@endif</bdi></span></label>
    @endforeach
    </div>
</section>
@endforeach
@if (! empty($__sl['note']))<p class="c-note c-note--warn">{{ $kit->icon('alert-triangle') }}<span>{{ $__sl['note'] }}</span></p>@endif
@if (! empty($__sl['timezone_note']))<p class="c-note c-note--tz">{{ $kit->icon('clock') }}<span>{{ $kit->flow($__sl['timezone_note']) }}</span></p>@endif

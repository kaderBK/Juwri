{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- book: a day strip and the free times of one laundry slot (pickup / drop-off, or delivery). Days belong to the refresh form (GET: the
     day's times are the server's), the time to the hold form (POST). $pre id prefix, $picker the SlotPicker, $dateName / $slotName the
     field names, $required whether a time must be chosen (the first radio carries `required`). --}}
<section class="c-pick" aria-labelledby="{{ $pre }}-day-h"><div class="c-pick__head"><h2 class="c-pick__title" id="{{ $pre }}-day-h">{{ $picker['title'] }}</h2></div>
    <div class="c-days" role="radiogroup" aria-labelledby="{{ $pre }}-day-h">
    @foreach ($picker['days'] ?? [] as $__d)
        <label class="c-day{{ $__d['state'] === 'closed' ? ' is-closed' : '' }}"><input class="c-day__input u-vh" type="radio" name="{{ $dateName }}" value="{{ $__d['id'] }}" form="tp-refresh" @checked($__d['state'] === 'selected') @disabled($__d['state'] === 'closed')><span class="c-day__face"><span class="c-day__dow">{{ $__d['label'] }}</span><span class="c-day__num">@if ($__d['state'] === 'closed')<s>{{ $__d['sub'] }}</s><span class="u-sr">, {{ $kit->s('Closed') }}</span>@else{{ $__d['sub'] }}@endif</span><span class="c-day__check" aria-hidden="true">{{ $kit->icon('check') }}</span></span></label>
    @endforeach
    </div>
</section>
@foreach ($picker['groups'] as $__gi => $__g)
<section class="c-pick" aria-labelledby="{{ $pre }}-slots-{{ $__gi }}"><h3 class="c-pick__title" id="{{ $pre }}-slots-{{ $__gi }}">{{ $__g['label'] }}</h3>
    <div class="c-slots" role="radiogroup" aria-labelledby="{{ $pre }}-slots-{{ $__gi }}">
    @foreach ($__g['slots'] as $__s)
        @php($__taken = $__s['state'] === 'full')
        <label class="c-slot{{ $__taken ? ' is-taken' : '' }}"><input class="c-slot__input u-vh" type="radio" name="{{ $slotName }}" value="{{ $__s['id'] }}" form="tp-hold" data-book-slot="{{ $slotName }}" @checked($__s['state'] === 'selected') @disabled($__taken) @required($required && $__gi === 0 && $loop->first)><span class="c-slot__face"><span class="c-slot__check" aria-hidden="true">{{ $kit->icon('check') }}</span><bdi class="c-slot__time">@if ($__taken)<s>{{ $__s['label'] }}</s><span class="u-sr">, {{ $kit->s('Taken') }}</span>@else{{ $__s['label'] }}@endif</bdi></span></label>
    @endforeach
    </div>
</section>
@endforeach
@if (! empty($picker['note']))<p class="c-note c-note--warn">{{ $kit->icon('alert-triangle') }}<span>{{ $picker['note'] }}</span></p>@endif
@if (! empty($picker['timezone_note']))<p class="c-note c-note--tz">{{ $kit->icon('clock') }}<span>{{ $kit->flow($picker['timezone_note']) }}</span></p>@endif

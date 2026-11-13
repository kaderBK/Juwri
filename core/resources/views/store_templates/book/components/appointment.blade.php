{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- book: the appointment card — when, what, with whom, where (InfoRow[]) --}}
<section class="c-appt c-card-block" aria-labelledby="{{ $id ?? 'appt-h' }}"><h2 class="c-section__title" id="{{ $id ?? 'appt-h' }}">{{ $title }}</h2>
    <ul class="c-appt__rows">
    @foreach ($rows as $__r)
        <li class="c-appt__row"><span class="c-appt__ico">{{ $kit->icon($__r['icon'] ?? 'info') }}</span><span class="c-appt__text"><span class="c-appt__label">{{ $kit->flow($__r['label']) }}</span>@if (! empty($__r['sub']))<span class="c-appt__sub">{{ $kit->flow($__r['sub']) }}</span>@endif</span></li>
    @endforeach
    </ul>
    @if (! empty($change))<a class="c-btn c-btn--ghost c-appt__change" href="{{ $change['url'] }}">{{ $change['label'] }}</a>@endif
</section>

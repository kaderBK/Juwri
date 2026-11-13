{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- brew: the receipt's lines (compact rows: quantity, name, choices, the guest's own note such as the name on the cup, price) --}}
<ul class="c-lines c-lines--receipt">
@foreach ($quote['lines'] as $__l)
    <li class="c-rline"><div class="c-rline__text"><p class="c-rline__name"><span class="c-rline__qty">{{ $__l['qty'].' ×' }}</span> {{ $__l['name'] }}</p>@if (! empty($__l['choices_label']))<p class="c-rline__choices">{{ $kit->flow($__l['choices_label']) }}</p>@endif @if (! empty($__l['note']))<p class="c-line__note">{{ $__l['note'] }}</p>@endif</div><p class="c-rline__price">{{ $kit->money($__l['line_total']) }}</p></li>
@endforeach
</ul>

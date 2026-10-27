{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- the store's legal identity, in the footer of every kit theme (contract section 4.1): nothing when store.identity is absent;
     hidden on kiosk screens unless the store turned context.kiosk.show_identity on --}}
@php
    $__idn = $vm['store']['identity'] ?? null;
    $__k = $vm['context']['kiosk'] ?? [];
    $__rows = [];
    if ($__idn && ! (! empty($__k['on']) && empty($__k['show_identity']))) {
        $__rows[] = [$kit->s('Legal name'), e($__idn['legal_name'])];
        foreach (['commercial_registration' => 'Commercial registration', 'tax_registration' => 'Tax number'] as $__f => $__fallback) {
            if (! empty($__idn[$__f])) {
                $__rows[] = [! empty($__idn[$__f]['label']) ? $__idn[$__f]['label'] : $kit->s($__fallback), $kit->ltr($__idn[$__f]['number'])->toHtml()];
            }
        }
        if (! empty($__idn['address'])) {
            $__rows[] = [$kit->s('Registered address'), e($__idn['address'])];
        }
    }
@endphp
@if ($__rows)
<dl class="c-identity">@foreach ($__rows as [$__label, $__value])<div class="c-identity__row"><dt>{{ $__label }}</dt><dd>{{ new \Illuminate\Support\HtmlString($__value) }}</dd></div>@endforeach</dl>
@endif

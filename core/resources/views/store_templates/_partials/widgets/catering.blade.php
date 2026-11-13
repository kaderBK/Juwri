{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Theme Studio B0.7 — {% widget 'catering' %}. Hosts the SHARED catering form
    (_partials/catering-form, posting to publicView.catering.request) inside this
    widget's own dialog. `cateringInline` tells the partial the dialog owns the
    placement (no relocation into a Blade theme's DOM). WidgetTag gates it on
    features.catering (= storefront_catering_enabled, as the Blade themes do).
--}}
@php
    $mbwId = 'mbw-catering';
    // The catering endpoint validates without our marker; `headcount` is a field
    // only its form has, so an old() value means the error bag is this form's.
    $mbwReopen = session('catering_success')
        || (isset($errors) && $errors->any() && old('headcount') !== null);
@endphp
@if($widgetBase)@include('store_templates._partials.widgets._base')@endif
<button type="button" class="mbw-btn mbw-catering__trigger" data-mbw-open="catering" data-catering-open aria-haspopup="dialog" aria-controls="{{ $mbwId }}-dialog">
    <svg class="mbw-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11h18M5 11a7 7 0 0 1 14 0M12 4V2M2 15h20M4 15l1 4h14l1-4"/></svg>
    <span>{{ $widgetLabel ?? ___('Catering & Events') }}</span>
</button>
@if($widgetFirst)
<dialog class="mbw-dialog mbw-catering" id="{{ $mbwId }}-dialog" aria-labelledby="tpCateringTitle" @if($mbwReopen) data-mbw-autoopen @endif>
    <div class="mbw-head">
        <span></span>
        <button type="button" class="mbw-x" data-mbw-close aria-label="{{ ___('Close') }}">
            <svg class="mbw-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
    </div>
    <div class="mbw-body">
        @include('store_templates._partials.catering-form', ['cateringInline' => true])
    </div>
</dialog>
@endif

{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- TAX-RUN F0: fiscal receipt block. Renders NOTHING for restaurants with no
     country set or a null-profile country (Qatar/Kuwait/Algeria stay unchanged).
     Expects: $store (Post), $order (Order). Optional: $fiscalExtra (array). --}}
@php
    // Master kill-switch OFF → no fiscal surfaces at all (receipt falls back to
    // its pre-run rendering). Per-country switch OFF → that country's block hidden.
    $__fiscalOn = \App\Services\FeatureRegistry::isEnabled('fiscalization');
    $__profile = $__fiscalOn ? \Juwri\Modules\Fiscal\Services\FiscalRegistry::for($store ?? null) : null;
    if ($__profile && ! \App\Services\FeatureRegistry::isEnabled('fiscal_' . strtolower($__profile->country()))) {
        $__profile = null;
    }
    $__fields = $__profile ? $__profile->requiredReceiptFields() : [];
    $__ctx = null;
    $__qr = null;
    if ($__profile && $__fields && isset($order)) {
        try {
            $__ctx = \Juwri\Modules\Ordering\Services\OrderFiscalContext::for($store, $order, $fiscalExtra ?? []);
            if (in_array('qr', $__fields, true)) {
                $__qr = $__profile->qr($__ctx);
            }
        } catch (\Throwable $e) {
            $__ctx = null;
        }
    }
    $__opts = isset($store) ? store_options($store->id) : null;
    $__simplified = $__profile
        && in_array('simplified_title', $__fields, true)
        && isset($order)
        && $__profile->isSimplified((float) $order->total);
@endphp
@if($__profile && $__ctx)
    <hr class="ui-print__rule">
    @if(in_array('simplified_title', $__fields, true) || in_array('vat_number', $__fields, true))
        <div class="ui-print__center ui-print__strong">{{ $__profile->invoiceTitle($__simplified) }}</div>
    @endif
    @if($__ctx->sellerName)
        <div class="ui-print__center">{{ $__ctx->sellerName }}</div>
    @endif
    @if(in_array('vat_number', $__fields, true) && $__ctx->vatNumber)
        <div class="ui-print__center">{{ ___('VAT No.') }}: {{ $__ctx->vatNumber }}</div>
    @endif
    @if($__ctx->extra('number'))
        <div class="ui-print__center">{{ ___('Invoice No.') }}: {{ $__ctx->extra('number') }}</div>
    @endif
    @if(in_array('cr_number', $__fields, true) && $__ctx->crNumber)
        <div class="ui-print__center">{{ ___('CR No.') }}: {{ $__ctx->crNumber }}</div>
    @endif
    @if($__qr)
        @php
            /* PARITY-RUN T10: routed through QrRenderService like every other QR,
               but deliberately MONOCHROME — a fiscal QR is a compliance artefact
               printed on a 1-bit thermal head, where brand colours, gradients and a
               logo overlay dither into something that will not scan. Error
               correction is raised to Q because thermal paper fades. */
            $__svg = app(\Juwri\Modules\Tables\Services\Qr\QrRenderService::class)->svg(
                $__qr,
                \Juwri\Modules\Tables\Models\QrDesign::monochrome(store_qr_settings(isset($store) ? (int) $store->id : null, ['size' => 140, 'ec' => 'Q']))
            );
        @endphp
        @if($__svg)
            <div class="ui-print__center ui-print__block">{!! $__svg !!}</div>
        @endif
    @endif
@endif

{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Site block: section_app_screens — "A menu that feels like an app": three
     kit phone frames with mock screens in place of the old screenshot carousel. --}}
@php
    $sbScreens = [
        ['qr', ___('QR Menu'), [___('See the menu'), ___('Reserve a table')]],
        ['orders', ___('Online Ordering'), [___('Order now'), ___('Delivery'), ___('Pickup')]],
        ['chat', ___('WhatsApp Orders'), [___('WhatsApp us'), ___('Leave a tip')]],
    ];
@endphp
<section class="mk-sec mk-sec--tint sb-block" data-site-block="section_app_screens">
    <div class="mk-wrap">
        <div class="mk-sec__head">
            <h2 class="mk-h2">{{ ___('A menu that feels like an app, without the download.') }}</h2>
        </div>
        <div class="sb-screens" aria-hidden="true">
            @foreach ($sbScreens as [$sbIcon, $sbTitle, $sbRows])
                <div class="sb-phone">
                    <div class="sb-phone__screen">
                        <span class="sb-phone__avatar"><x-ui.icon :name="$sbIcon" size="22" /></span>
                        <b>{{ $sbTitle }}</b>
                        @foreach ($sbRows as $sbI => $sbRow)
                            <span class="sb-phone__btn{{ $sbI === 0 ? ' sb-phone__btn--primary' : '' }}">{{ $sbRow }}</span>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

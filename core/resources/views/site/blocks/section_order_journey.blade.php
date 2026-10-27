{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Site block: section_order_journey — "The honest comparison": us vs
     delivery marketplaces vs legacy POS. The first column is brand_title(), so
     a white-label reseller sees its own name. --}}
@php
    $sbRows = [
        [___('Commission per order'), ['ok', '0%'], ['no', '15–30%'], ['ok', '0%']],
        [___('Your customer data'), ['ok', ___('Yours')], ['no', ___('Theirs')], [null, ___('Partial')]],
        [___('Setup time'), ['ok', ___('Same afternoon')], [null, ___('Days')], ['no', ___('Weeks + hardware')]],
        [___('QR & WhatsApp ordering'), ['ok', ___('Built in')], ['no', ___('No')], [null, ___('Add-on')]],
        [___('Kitchen screens & POS'), ['ok', ___('Included')], ['no', ___('No')], [null, ___('Extra modules')]],
        [___('Price'), ['ok', ___('Flat, from free')], [null, ___('A cut of your revenue')], ['no', ___('Big upfront cost')]],
    ];
@endphp
<section class="mk-sec sb-block" data-site-block="section_order_journey">
    <div class="mk-wrap">
        <div class="mk-sec__head">
            <span class="mk-eyebrow">{{ ___('Why switch') }}</span>
            <h2 class="mk-h2">{{ ___('The honest comparison.') }}</h2>
        </div>
        <div class="mk-table-wrap">
            <table class="mk-table sb-compare">
                <thead>
                    <tr>
                        <td></td>
                        <th scope="col" class="is-us">{{ brand_title() }}</th>
                        <th scope="col">{{ ___('Delivery marketplaces') }}</th>
                        <th scope="col">{{ ___('Legacy POS') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sbRows as [$sbLabel, $sbUs, $sbMkt, $sbLegacy])
                        <tr>
                            <th scope="row">{{ $sbLabel }}</th>
                            @foreach ([$sbUs, $sbMkt, $sbLegacy] as $sbI => $sbCell)
                                <td @class(['is-us' => $sbI === 0])><span @class(['mk-yes' => $sbCell[0] === 'ok', 'mk-no' => $sbCell[0] === 'no'])>{{ $sbCell[1] }}</span></td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</section>

{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Coupons C5: redemption log rows (admin, store owner and reseller screens).
     $redemptions = paginator|collection of CouponRedemptionLog rows; $showCode = show the code column (batch views). --}}
@php
    $showCode = $showCode ?? false;
    $surfaceLabels = [
        'store_order' => ___('Store order'),
        'pos' => ___('POS'),
        'plan_checkout' => ___('Plan checkout'),
        'reseller_checkout' => ___('Reseller checkout'),
    ];
    $rowItems = $redemptions instanceof \Illuminate\Contracts\Pagination\Paginator ? $redemptions->items() : $redemptions;
@endphp
@if (collect($rowItems)->isEmpty())
    <x-ui.empty icon="gift" :title="___('No redemptions yet.')" />
@else
    <div class="ui-dt__scroll">
        <table class="ui-table">
            <thead>
                <tr>
                    <th>{{ ___('When') }}</th>
                    @if ($showCode)<th>{{ ___('Code') }}</th>@endif
                    <th>{{ ___('Who') }}</th>
                    <th>{{ ___('Reference') }}</th>
                    <th>{{ ___('Channel') }}</th>
                    <th class="ui-table__num">{{ ___('Discount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rowItems as $r)
                    @php
                        $refLabel = match ($r['ref_type']) {
                            'order' => $r['ref_id'] ? ___('Order #:n', ['n' => $r['ref_id']]) : '—',
                            'transaction' => ___('Payment #:n', ['n' => $r['ref_id']]),
                            default => ___('Subscription #:n', ['n' => $r['ref_id']]),
                        };
                        $savedLabel = '—';
                        if ($r['saved_minor'] !== null) {
                            $currency = (string) ($r['currency'] ?? '');
                            $exponent = $currency !== '' ? \Juwri\Core\Money\Currencies::exponent($currency) : 2;
                            $major = number_format(((int) $r['saved_minor']) / (10 ** $exponent), $exponent, '.', '');
                            $savedLabel = trim($major.' '.$currency);
                        }
                    @endphp
                    <tr>
                        <td data-label="{{ ___('When') }}">{{ $r['when'] ? date_formating($r['when'], 'd M, Y H:i') : '—' }}</td>
                        @if ($showCode)<td data-label="{{ ___('Code') }}"><span class="ui-mono" dir="ltr">{{ $r['code'] }}</span></td>@endif
                        <td data-label="{{ ___('Who') }}">{{ $r['who_name'] ?: ___('Guest') }} @if ($r['who_id'])<small class="ui-ltr">#{{ $r['who_id'] }}</small>@endif</td>
                        <td data-label="{{ ___('Reference') }}">{{ $refLabel }}</td>
                        <td data-label="{{ ___('Channel') }}">{{ $surfaceLabels[$r['surface']] ?? $r['surface'] }}</td>
                        <td class="ui-table__num ui-ltr" data-label="{{ ___('Discount') }}">{{ $savedLabel }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

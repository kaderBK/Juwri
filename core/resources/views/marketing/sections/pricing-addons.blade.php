{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- RB-10: promises strip + add-ons under the plan cards. Figures from config/pricing_page.php (PRICING.md §8/§8a). --}}
@php
    $pa = config('pricing_page');
    $cur = $pa['currency'];
    $agent = $pa['addons']['phone_agent'];
@endphp
<ul class="mk-center mk-pricing-promises" aria-label="{{ ___('What every plan includes') }}">
    <li>{{ ___('No setup fee') }}</li>
    <li>{{ ___('No commission on orders') }}</li>
    <li>{{ ___('Cancel any time') }}</li>
    <li>{{ ___('Prices exclude VAT') }}</li>
</ul>

<section class="mk-sec" id="addons">
    <div class="mk-wrap mk-sec__head">
        <h2 class="mk-h2">{{ ___('Optional add-ons') }}</h2>
        <p class="mk-lead">{{ ___('Add only what you need. Unused credits stay for 12 months.') }}</p>
    </div>
    <div class="mk-wrap">
        <dl class="mk-addons">
            <div class="mk-addon" id="addon-phone-agent">
                <dt>{{ ___('AI phone agent') }}</dt>
                <dd>
                    <strong>{{ $cur }} {{ number_format($agent['sar']) }}</strong> {{ ___('per location per month') }}
                    <span>{{ ___(':minutes minutes included, no per-order fee. Cloud only.', ['minutes' => $agent['minutes']]) }}</span>
                </dd>
            </div>
            <div class="mk-addon" id="addon-zatca">
                <dt>{{ ___('ZATCA signing service') }}</dt>
                <dd>
                    <strong>{{ $cur }} {{ $pa['addons']['zatca_signing']['sar'] }}</strong> {{ ___('per store per month') }}
                    <span>{{ ___('For Starter stores that need e-invoicing. Included in Pro and Chain.') }}</span>
                </dd>
            </div>
            <div class="mk-addon" id="addon-delivery-relay">
                <dt>{{ ___('Delivery-app relay') }}</dt>
                <dd>
                    <strong>{{ $cur }} {{ $pa['addons']['delivery_relay']['sar'] }}</strong> {{ ___('per store per month') }}
                    <span>{{ ___('Per connected delivery app.') }}</span>
                </dd>
            </div>
        </dl>
        <p class="mk-center">
            <a href="{{ route('compare.show', 'odoo') }}">{{ ___('Compare :brand with Odoo', ['brand' => seo_brand()]) }}</a>
        </p>
    </div>
</section>

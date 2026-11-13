{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Site block: section_audiences — "Bigger than one restaurant?": hotels
     (main card with feature chips), agencies, food halls and developers. --}}
@php
    $sbHotelChips = [
        ___('Booking calendar'), ___('Room types & rates'), ___('Charge to room'), ___('Folio ledger'),
        ___('Breakfast pre-order'), ___('Housekeeping'), ___('Guest info hub'),
    ];
@endphp
<section class="mk-sec sb-block" data-site-block="section_audiences">
    <div class="mk-wrap">
        <div class="mk-sec__head">
            <h2 class="mk-h2">{{ ___('Bigger than one restaurant? Good.') }}</h2>
        </div>
        <div class="sb-aud">
            <article class="mk-card sb-aud__main">
                <span class="mk-ico" aria-hidden="true"><x-ui.icon name="bed" size="22" /></span>
                <h3>{{ ___('Hotels run the whole stay on it') }}</h3>
                <p>{{ ___('A booking calendar with room types and rate plans runs the front desk. Room QRs greet guests by name, orders charge to the room, breakfast is pre-ordered the night before, and housekeeping requests route to the right department.') }}</p>
                <ul class="mk-chips">
                    @foreach ($sbHotelChips as $sbChip)
                        <li>{{ $sbChip }}</li>
                    @endforeach
                </ul>
            </article>
            <article class="mk-card">
                <h3>{{ ___('Agencies resell it as their own') }}</h3>
                <p>{{ ___('Full white-label: your domain, your logo, your plans and prices, your wallet and payouts. Clients never see our name.') }}</p>
                <div class="mk-card__links">
                    @if (Route::has('partners.index'))
                        <a href="{{ route('partners.index') }}">{{ ___('See the reseller program') }}</a>
                    @else
                        <span class="mk-small">{{ ___('Reseller panel with a landing page builder') }}</span>
                    @endif
                </div>
            </article>
            <article class="mk-card">
                <h3>{{ ___('Food halls run every kitchen on one cart') }}</h3>
                <p>{{ ___('A multi-vendor marketplace out of the box: one storefront, one cart across vendors, and payouts split per kitchen.') }}</p>
                <div class="mk-card__links"><span class="mk-small">{{ ___('Fusion marketplace, built in') }}</span></div>
            </article>
            <article class="mk-card">
                <h3>{{ ___('Developers extend it') }}</h3>
                <p>{{ ___('Build on the API, publish themes and apps to the marketplace, or wire AI agents to the MCP servers.') }}</p>
                <div class="mk-card__links"><a href="{{ url('/developers') }}">{{ ___('Read the developer docs') }}</a></div>
            </article>
        </div>
    </div>
</section>

{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Site block: section_integrations — the outside services the platform
     plugs into, grouped (names as chips; no brand artwork).
     Source of truth for the list: rev/third-parties/THIRD-PARTIES.csv. --}}
@php
    $sbGroups = [
        ['settings', ___('POS and hardware'), ['Foodics', 'Marn', 'Square', 'Sunmi']],
        ['delivery', ___('Delivery channels'), ['Deliverect', 'Jahez', 'HungerStation', 'Keeta', 'Zomato', 'Swiggy']],
        ['refresh', ___('Automation'), ['Zapier', 'Make', 'n8n', 'REST API']],
        ['chat', ___('Messaging'), ['WhatsApp', 'Twilio', 'Msegat', 'wa.me']],
        ['star', ___('Google'), ['Google Wallet', 'Reserve with Google', 'Business Profile', 'Google Reviews']],
        ['pin', ___('Maps and import'), ['OpenStreetMap', 'Google Maps', 'GloriaFood']],
    ];
@endphp
<section class="mk-sec sb-block" data-site-block="section_integrations">
    <div class="mk-wrap">
        <div class="mk-sec__head">
            <h2 class="mk-h2">{{ ___('Plays well with your stack.') }}</h2>
            <p class="mk-lead">{{ ___('Keep your POS, your delivery apps and your phone number — and automate the rest with Zapier, Make or n8n.') }}</p>
        </div>
        <div class="mk-grid mk-grid--3">
            @foreach ($sbGroups as [$sbIcon, $sbTitle, $sbNames])
                <article class="mk-card">
                    <h3 class="sb-card-title"><span class="mk-ico" aria-hidden="true"><x-ui.icon :name="$sbIcon" size="22" /></span>{{ $sbTitle }}</h3>
                    <ul class="mk-chips">
                        @foreach ($sbNames as $sbName)
                            <li>{{ $sbName }}</li>
                        @endforeach
                    </ul>
                </article>
            @endforeach
        </div>
        <p class="mk-note">{{ ___('One Integrations hub shows every connection with live status — plus signed webhooks and a scoped REST API for anything custom.') }}</p>
        @if (Route::has('apps.store.index'))
            <div class="mk-actions"><a href="{{ route('apps.store.index') }}" class="ui-btn ui-btn--secondary">{{ ___('Browse the app marketplace') }}</a></div>
        @endif
    </div>
</section>

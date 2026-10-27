{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@if (Route::has('partners.index'))
    <section class="mk-sec">
        <div class="mk-wrap">
            <div class="mk-band">
                <div>
                    <span class="mk-eyebrow">{{ ___('For agencies & freelancers') }}</span>
                    <h2 class="mk-h2">{{ ___('Run an agency? Resell it white-label.') }}</h2>
                    <p>{{ ___('Buy a seat wholesale, sell your own branded plan and keep the margin — your domain, your logo, your prices. Clients never see our name.') }}</p>
                </div>
                <div class="mk-actions">
                    @if (Route::has('partners.index'))<a href="{{ route('partners.index') }}" class="ui-btn">{{ ___('See the reseller program') }}</a>@endif
                    @if (Route::has('partners.signup'))<a href="{{ route('partners.signup') }}" class="ui-btn ui-btn--secondary">{{ ___('Start free') }}</a>@endif
                </div>
            </div>
        </div>
    </section>
@endif

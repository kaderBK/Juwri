{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- C1 · Empty-state chooser (SPEC T1-3). Rendered by the builder only while the
     store has ZERO blocks and a preset catalog is available. Replaces the plain
     empty state with: Start blank · one curated card per starter archetype
     (basic-tier first; locked pro cards upsell, never dead-end) · Generate with AI
     (C2, hidden unless `ai_biolink` allows) · Browse all 38.

     No inline script or style here — the builder owns the single nonced pair
     (CSP). All interactions are wired from the builder script by the data hooks. --}}
@php
    $archetypeLabels = [
        'restaurant_essential' => ___('Restaurant essentials'),
        'cafe'                 => ___('Café'),
        'delivery_late_night'  => ___('Late-night delivery'),
        'fine_dining'          => ___('Fine dining'),
        'minimal'              => ___('Minimal'),
        'social_grid'          => ___('Social grid'),
    ];
@endphp

<x-ui.card :title="___('Start your bio page')" class="bio-chooser" data-biolink-chooser>

    <p class="ui-muted">{{ ___('Pick a starting point — we’ll add the right blocks with your real store details. You can edit everything after.') }}</p>

    <div class="ui-form-grid">
        {{-- Card 1 · Start blank --------------------------------------------- --}}
        <div>
            <button type="button" class="ui-stack bio-starter-card ui-btn ui-btn--secondary ui-w-full ui-fill ui-center ui-bordered"
                    data-biolink-start-blank style="min-height:190px;">
                <span class="ui-cluster ui-cluster--center ui-inline ui-text-2" style="width:44px;height:44px;">
                    <x-ui.shell.nav-icon glyph="plus" style="font-size:1.1rem;" />
                </span>
                <span class="ui-strong">{{ ___('Start blank') }}</span>
            </button>
        </div>

        {{-- Cards 2–7 · one curated preset per archetype --------------------- --}}
        @foreach($starterCards as $card)
            @php
                $slug = $card['preset'];
                $label = $archetypeLabels[$card['archetype']] ?? ucwords(str_replace('_', ' ', $card['archetype']));
            @endphp
            <div>
                @if($card['allowed'])
                    <form method="post" action="{{ $applyUrl }}" class="ui-fill" data-starter-apply>
                        @csrf
                        <input type="hidden" name="preset" value="{{ $slug }}">
                        <button type="submit" class="ui-stack bio-starter-card ui-btn ui-btn--secondary ui-w-full ui-fill ui-center ui-bordered"
                                style="min-height:190px;">
                            <span class="ui-block ui-w-full" style="max-width:96px;">
                                @include('bio.presets.thumbs.'.$slug)
                            </span>
                            <span class="ui-strong">{{ $label }}</span>
                        </button>
                    </form>
                @else
                    {{-- Locked (pro-tier) preset: an upsell, never a dead button. --}}
                    <a href="{{ route('biolink.index') }}" class="ui-stack bio-starter-card bio-starter-locked ui-btn ui-btn--secondary ui-w-full ui-fill ui-center ui-bordered ui-relative"
                       style="min-height:190px;">
                        <span class="ui-tag ui-absolute ui-top-0 ui-end-0" data-tone="neutral"><x-ui.shell.nav-icon glyph="lock" />{{ ___('Pro') }}</span>
                        <span class="ui-block ui-w-full" style="max-width:96px;opacity:.75;">
                            @include('bio.presets.thumbs.'.$slug)
                        </span>
                        <span class="ui-strong">{{ $label }}</span>
                    </a>
                @endif
            </div>
        @endforeach

        {{-- Card · Generate with AI (C2) — injected only when ai_biolink allows. --}}
        @includeWhen(($aiEnabled ?? false), 'owner.biolink._chooser-ai')
    </div>

    {{-- Browse all 38 ---------------------------------------------------------- --}}
    <div>
        <button type="button" class="ui-btn ui-btn--sm ui-btn--link" data-biolink-browse-toggle aria-expanded="false">
            <x-ui.shell.nav-icon glyph="apps" />{{ ___('Browse all 38 designs') }}
        </button>
        <div class="ui-hidden" data-biolink-browse>
            <p class="ui-muted">{{ ___('Click a design to apply it. Locked designs open your plan options.') }}</p>
            {{-- Shared apply form the browse thumbs submit into (JS sets the preset). --}}
            <form method="post" action="{{ $applyUrl }}" data-biolink-browse-form class="ui-hidden">
                @csrf
                <input type="hidden" name="preset" value="" data-browse-preset>
            </form>
            <div data-biolink-browse-grid>
                @include('bio.presets._gallery')
            </div>
        </div>
    </div>
</x-ui.card>

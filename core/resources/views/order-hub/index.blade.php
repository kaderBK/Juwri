{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('order-hub.layout')
@section('title', ___('Order hub'))
@section('content')
    {{-- Search (Snoonu-style pill, client-side filter over the store grid) --}}
    <div class="hub-search">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        <input id="hubSearch" type="search" autocomplete="off" placeholder="{{ ___('Search for stores or products') }}">
    </div>

    {{-- Category chips (from live menu categories across stores) + guest favourites --}}
    <div class="hub-chips" id="hubChips">
        <button class="hub-chip on" data-cat="">{{ ___('All') }}</button>
        <button class="hub-chip" data-cat="__fav">&#10084;&#65039; {{ ___('Favorites') }}</button>
        @foreach($allCats as $c)
            <button class="hub-chip" data-cat="{{ mb_strtolower($c) }}">{{ $c }}</button>
        @endforeach
    </div>

    {{-- Featured: top-rated stores, horizontal snap carousel (Snoonu "Popular Brands") --}}
    @php $featured = \Juwri\Core\Support\ViewHelpers::topRatedStores($stores); @endphp
    @if($featured->count() >= 2)
        <div class="hub-h"><h2>{{ ___('Featured') }}</h2><span class="muted">{{ ___('Top rated') }}</span></div>
        <div class="hub-hscroll">
            @foreach($featured as $i => $s)
                <a class="hub-fcard hub-anim" style="--i: {{ $i }}" href="{{ route('orderhub.store', $s->slug) }}">
                    <div class="cv">
                        @if($s->hub_cover)<img src="{{ $s->hub_cover }}" alt="" loading="lazy" data-csp-error="remove">@endif
                    </div>
                    <div class="bd">
                        <div class="nm">{{ $s->title }}</div>
                        <div class="mt"><span class="hub-star">★ {{ number_format($s->hub_rating, 1) }}</span> ({{ $s->hub_reviews }})</div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif

    <div class="hub-h">
        <h2>{{ ___('All stores') }}</h2>
        <span class="muted">{{ ___('Order from several stores — one checkout.') }}</span>
    </div>

    <div class="hub-grid" id="hubStores">
        @forelse($stores as $i => $s)
            <a class="hub-store hub-anim" style="--i: {{ min($i, 8) }}" href="{{ route('orderhub.store', $s->slug) }}"
               data-store="{{ $s->id }}"
               data-name="{{ mb_strtolower($s->title) }}"
               data-cats="{{ mb_strtolower(implode('|', $s->hub_cats)) }}">
                <div class="cov">
                    @if($s->hub_cover)<img src="{{ $s->hub_cover }}" alt="" loading="lazy" data-csp-error="remove">@endif
                    @if($s->hub_logo)<img class="logo" src="{{ $s->hub_logo }}" alt="" loading="lazy" data-csp-error="remove">@endif
                    <button type="button" class="hub-heart" data-fav aria-label="{{ ___('Favorites') }}">&#10084;</button>
                </div>
                <div class="bd">
                    <div class="nm">{{ $s->title }}</div>
                    <div class="meta">
                        @if($s->hub_rating)<span class="hub-star">★ {{ number_format($s->hub_rating, 1) }}</span><span>({{ $s->hub_reviews }})</span>@endif
                        @if($s->address)<span>· {{ \Illuminate\Support\Str::limit($s->address, 32) }}</span>@endif
                    </div>
                    @if($s->hub_cats)
                        <div class="hub-tags">
                            @foreach($s->hub_cats as $c)<span class="hub-tag">{{ $c }}</span>@endforeach
                        </div>
                    @endif
                </div>
            </a>
        @empty
            <div class="hub-empty" style="grid-column:1/-1">
                <div class="e">&#127978;</div>
                {{ ___('No stores available yet.') }}
            </div>
        @endforelse
    </div>
    <p class="hub-empty" id="hubNoMatch" style="display:none">{{ ___('No stores match your search.') }}</p>
@endsection

@push('scripts')
<script nonce="{{ csp_nonce() }}">
    // Live filter (search + category + favourites) and guest-favourite hearts.
    (function () {
        const q = document.getElementById('hubSearch');
        const chips = document.getElementById('hubChips');
        const cards = Array.from(document.querySelectorAll('#hubStores .hub-store'));
        const none = document.getElementById('hubNoMatch');
        let cat = '';

        // Hydrate hearts from localStorage.
        const favs = new Set(hub.favs());
        cards.forEach(c => {
            const h = c.querySelector('[data-fav]');
            if (h && favs.has(parseInt(c.dataset.store, 10))) h.classList.add('on');
        });

        function apply() {
            const term = (q.value || '').trim().toLowerCase();
            let shown = 0;
            cards.forEach(c => {
                const okTerm = !term || c.dataset.name.includes(term) || c.dataset.cats.includes(term);
                const okCat = !cat
                    || (cat === '__fav' ? c.querySelector('[data-fav]').classList.contains('on')
                                        : c.dataset.cats.split('|').includes(cat));
                const on = okTerm && okCat;
                c.style.display = on ? '' : 'none';
                if (on) shown++;
            });
            none.style.display = shown ? 'none' : '';
        }
        q && q.addEventListener('input', apply);
        chips && chips.addEventListener('click', (e) => {
            const b = e.target.closest('.hub-chip'); if (!b) return;
            chips.querySelectorAll('.hub-chip').forEach(x => x.classList.remove('on'));
            b.classList.add('on'); cat = b.dataset.cat; apply();
        });
        // Heart taps: toggle + persist, never navigate.
        document.getElementById('hubStores').addEventListener('click', (e) => {
            const h = e.target.closest('[data-fav]'); if (!h) return;
            e.preventDefault(); e.stopPropagation();
            const card = h.closest('.hub-store');
            const on = hub.toggleFav(parseInt(card.dataset.store, 10));
            h.classList.toggle('on', on);
            h.classList.remove('hub-pop'); void h.offsetWidth; h.classList.add('hub-pop');
            if (cat === '__fav') apply();
        });
    })();
</script>
@endpush

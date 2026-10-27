{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<section class="lm-screen has-tabbar" data-screen="home">
    <div class="lm-hdr" style="margin-top:4px">
        <div style="display:flex;gap:10px;align-items:center;min-width:0">
            @if($customer && $customer->avatar_url)
                @if((string) ($customer->avatar_url) !== '')<img class="lm-avatar" src="{{ $customer->avatar_url }}" alt="" data-csp-error="hide">@endif
            @else
                <span class="lm-avatar-fallback" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($customer->name ?? $store->title, 0, 1)) }}</span>
            @endif
            <div class="lm-display" style="font-size:18px">{{ ___('AI Beauty,') }}</div>
        </div>
        <button type="button" class="lm-iconbtn lm-bellbtn" id="lmBellBtn" aria-label="{{ ___('Notifications') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0112 0c0 5 2 7 2 7H4s2-2 2-7z"/><path d="M10 20a2 2 0 004 0"/></svg>
            <i class="lm-bell-dot" hidden aria-hidden="true"></i>
        </button>
    </div>

    {{-- Crave-parity status row: Open-Now chip + (multi-store) branch switch. --}}
    <div class="lm-statusrow">
        <span class="lm-openchip @if(! $lmIsOpen) is-closed @endif" data-mb-openchip data-mb-closed-class="is-closed">
            <i aria-hidden="true"></i><span data-mb-open-text>{{ $lmIsOpen ? ___('Open now') : ___('Closed') }}@if($lmHoursToday) · {{ $lmHoursToday }}@endif</span>
        </span>
        @if($lmShowBranches)
            <label class="lm-branchsel" aria-label="{{ ___('Branch') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-5.7-7-11a7 7 0 1114 0c0 5.3-7 11-7 11z"/><circle cx="12" cy="10" r="2.6"/></svg>
                <select id="lmBranchSel">
                    @foreach($lmBranches as $branch)
                        <option value="{{ request()->fullUrlWithQuery(['branch_id' => $branch->id]) }}" @if(($lmActiveBranch->id ?? null) === $branch->id) selected @endif>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
    </div>

    @if($lmBanners->count())
        {{-- Promo banners (admin-managed, $menuBanners) — swipeable slider. --}}
        <div class="lm-banners" id="lmBanners">
            <div class="lm-banner-track" id="lmBannerTrack">
                @foreach($lmBanners as $b)
                    @php
                        $lmBu = trim((string) ($b->link_url ?? ''));
                        $lmBext = \Illuminate\Support\Str::startsWith($lmBu, ['http://', 'https://']);
                    @endphp
                    {{-- A real external URL stays a normal link; an empty or
                         in-app ("#…") link_url is intercepted in _script and
                         routed to the catalog screen so a banner is never a
                         dead tap (memory: fixed-overlay hides CTA target). --}}
                    <a class="lm-banner" @if($lmBext) href="{{ storefront_localized_link($b->link_url) }}" target="_blank" rel="noopener" @else href="#" data-banner-nav="home" @endif>
                        @if($b->image)
                            @if((string) ($b->image_url ?? media_url('storage/menu_banners/', $b->image, $store->id ?? null)) !== '')<img class="lm-banner-img" src="{{ $b->image_url ?? media_url('storage/menu_banners/', $b->image, $store->id ?? null) }}" alt="" data-csp-error="hide">@endif
                        @endif
                        <span class="lm-banner-copy">
                            @if($b->subtitle)<span class="lm-banner-kick">{{ $b->subtitle }}</span>@endif
                            <span class="lm-banner-title">{{ $b->title }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
            @if($lmBanners->count() > 1)<div class="lm-banner-dots" id="lmBannerDots" aria-hidden="true"></div>@endif
        </div>
    @endif

    {{-- F3 stories rail — populated + shown by fetchStories() in
         partials/_script.blade.php; hidden by default so an empty feed (or a
         no-JS visitor) never shows an empty strip. --}}
    <div class="lm-stories" id="lmStoriesRail" hidden></div>

    <div class="lm-display" style="font-size:26px;padding:0 0 4px">
        <span class="lm-muted">{{ ___('always here for your skin') }}</span>
    </div>

    <div class="lm-routinecard">
        <div class="lm-ringbox">
            {!! $ring(40) !!}
            <span class="lm-pct" id="lmRoutinePct">40%</span>
        </div>
        <div class="lm-routinebody">
            <div class="lm-rt">{{ ___('Daily Routine') }}</div>
            <div class="lm-rs" id="lmRoutineSub">{{ ___('Scan your face to build a personalized routine.') }}</div>
            <button type="button" class="lm-rbtn" data-nav="scan">
                <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z" fill="var(--lm-accent-2)"/></svg>
                {{ ___('Analyze now') }}
            </button>
        </div>
    </div>

    {{-- Search + Wishlist (client-side only, no backend for a single-store
         catalog): live filter over the loaded product grid below, matching
         the pattern other themes' own search boxes use (see e.g.
         zevoa/torque #zvSearch/#tqSearch) — query kept in JS, cards toggled
         via `hidden`, nothing persisted. Wishlist already existed on lumiere
         (the heart buttons above/below, WISH_KEY in partials/_script.blade.php)
         and is untouched. --}}
    <div class="lm-searchrow">
        <span class="lm-search-ic" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/></svg>
        </span>
        <input type="search" class="lm-search" id="lmSearchInput" autocomplete="off" inputmode="search"
               placeholder="{{ ___('Search products…') }}" aria-label="{{ ___('Search products') }}">
    </div>

    @if($lmCategories->count() > 1)
        {{-- Category filter chips (client-side over the loaded grid). --}}
        <div class="lm-catrow" id="lmCatRow" role="tablist" aria-label="{{ ___('Categories') }}">
            <button type="button" class="lm-catchip is-on" data-cat="" role="tab" aria-selected="true">{{ ___('All') }}</button>
            @foreach($lmCategories as $cat)
                <button type="button" class="lm-catchip" data-cat="{{ $cat->id }}" role="tab" aria-selected="false">{{ $cat->name }}</button>
            @endforeach
        </div>
    @endif

    <div class="lm-sectrow">
        <h2 class="lm-label">{{ ___('Best solution product') }}</h2>
        <button type="button" class="lm-see" data-see-all style="font-size:12.5px;color:var(--lm-accent-2);font-weight:600;cursor:pointer">{{ ___('See all') }}</button>
    </div>
    @if($items->isEmpty())
        <p class="lm-empty">{{ ___('Nothing here yet.') }}</p>
    @else
        <div class="lm-grid" id="lmProductGrid">
            @foreach($items as $m)
                @php $jd = $jsItems[$m->id]; @endphp
                <article class="lm-pcard" data-lm-pid="{{ $m->id }}">
                    <div class="lm-ptilewrap">
                        @if($jd['soldOut'])
                            <span class="lm-soldout-tag">{{ ___('Sold out') }}</span>
                        @endif
                        <a href="?item={{ $m->id }}" class="lm-media-link" data-open-item-link="{{ $m->id }}" aria-label="{{ $jd['name'] }}">
                            @if($jd['img'])
                                @if((string) ($jd['img']) !== '')<img src="{{ $jd['img'] }}" alt="" loading="lazy" data-csp-error="hide">@endif
                            @else
                                <div class="lm-ptilewrap-ph" role="img" aria-label="{{ $jd['name'] }}">
                                    <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M12 2c4 5 8 9.5 8 14a8 8 0 01-16 0c0-4.5 4-9 8-14z"/></svg>
                                </div>
                            @endif
                        </a>
                        <button type="button" class="lm-corner lm-heart" data-heart="{{ $m->id }}" aria-pressed="false" aria-label="{{ ___('Add to favorites') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20s-7-4.5-9.5-9A5 5 0 0112 5a5 5 0 019.5 6c-2.5 4.5-9.5 9-9.5 9z"/></svg>
                        </button>
                        <button type="button" class="lm-corner lm-openarrow" data-open="{{ $m->id }}" aria-label="{{ ___('Open') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17L17 7M9 7h8v8"/></svg>
                        </button>
                    </div>
                    <a href="?item={{ $m->id }}" class="lm-pinfo" data-open-item-link="{{ $m->id }}">
                        @if($jd['brand'])
                            <div class="lm-pbrand">{{ $jd['brand'] }}</div>
                        @endif
                        <div class="lm-pname">{{ $jd['name'] }}</div>
                        <div class="lm-prow"><span class="lm-price">{{ $jd['priceFormatted'] }}</span></div>
                        @if($jd['stockBadge'] && ! $jd['soldOut'])
                            <span class="lm-stock lm-stock--{{ $jd['stockBadge']['type'] }}">{{ $jd['stockBadge']['text'] }}</span>
                        @endif
                    </a>
                </article>
            @endforeach
        </div>
        <p class="lm-empty" id="lmSearchEmpty" hidden>{{ ___('No products match your search.') }}</p>
    @endif

    <div id="lmWatchlistSect">
    @if($watchItems->isNotEmpty())
        <div class="lm-sectrow">
            <h2 class="lm-label">{{ ___("Today's Self-Care Watchlist") }}</h2>
            <button type="button" class="lm-see" data-see-all style="font-size:12.5px;color:var(--lm-accent-2);font-weight:600;cursor:pointer">{{ ___('See all') }}</button>
        </div>
        <div class="lm-wrow">
            @foreach($watchItems as $m)
                @php $jd = $jsItems[$m->id]; @endphp
                <div class="lm-wcard">
                    <a href="?item={{ $m->id }}" class="lm-wimg" data-open-item-link="{{ $m->id }}" aria-label="{{ $jd['name'] }}">
                        @if($jd['img'])
                            @if((string) ($jd['img']) !== '')<img src="{{ $jd['img'] }}" alt="" loading="lazy" data-csp-error="hide">@endif
                        @else
                            <div class="lm-ptilewrap-ph"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M12 2c4 5 8 9.5 8 14a8 8 0 01-16 0c0-4.5 4-9 8-14z"/></svg></div>
                        @endif
                    </a>
                    @if($jd['brand'])
                        <div class="lm-wb">{{ $jd['brand'] }}</div>
                    @endif
                    <div class="lm-wn">{{ $jd['name'] }}</div>
                    <div class="lm-wp">{{ $jd['priceFormatted'] }}</div>
                    <button type="button" class="lm-wadd" data-add="{{ $m->id }}" @if($jd['soldOut']) disabled @endif aria-label="{{ $vocab['add_to_cart'] ?? ___('Add to cart') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                    </button>
                </div>
            @endforeach
        </div>
    @endif
    </div>
</section>

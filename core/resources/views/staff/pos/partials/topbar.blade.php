{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- POS-V2 topbar (P0-4 frame, UI-KIT §2): logo · persistent search · Select Table
     CTA · wifi/queue sync indicator · theme toggle · language · store/branch · lock
     · dashboard. Rail owns Home/Tables/Orders/Settings; topbar keeps the switchers. --}}
<div class="topbar">
    <span class="rlabel">{{ ___('POS') }}</span>

    {{-- T1-5: persistent live search — diacritics-insensitive filter (in
         visibleItems); / or F2 focuses, Esc clears. readonly-until-focus keeps
         Chrome from autofilling the account username into it. --}}
    <input id="pos-search" class="pos-topbar-search" type="search" x-model.debounce.200ms="search" autocomplete="off" name="pos-menu-search"
           readonly
           @focus="activePanel = 'home'; $el.removeAttribute('readonly')"
           @keydown.escape="clearSearch(); $el.blur()"
           @keydown.enter="searchEnter()"
           placeholder="{{ ___('Search menu…') }}" aria-label="{{ ___('Search menu…') }}">

    {{-- Select Table green CTA → opens the Tables panel (T3 fills it). --}}
    <button type="button" class="pos-cta-btn" @click="goPanel('tables')">
        <span>@include('staff.pos.partials.icon', ['name' => 'table', 'size' => 18])</span>{{ ___('Select Table') }}
    </button>

    {{-- wifi/queue indicator: green online · amber queued n · red offline; click = manual sync --}}
    <button type="button" class="pos-topbar-ind" :class="{'is-offline': offline, 'is-queued': !offline && queued > 0}"
            @click="flush()" :title="'{{ ___('Sync now') }}'">
        <span class="dot"></span>
        <span x-text="offline ? '{{ ___('Offline') }}' : (queued > 0 ? '{{ ___('Queued') }} ' + queued : '{{ ___('Online') }}')"></span>
    </button>

    {{-- PARITY-RUN T1: printer status chip. Hidden entirely when the store has no
         configured printers (or the feature is off), so nothing new appears for a
         register that still prints through the browser dialog. --}}
    <div class="pos-printbox" x-show="printEnabled" x-cloak @click.outside="printMenu = false">
        <button type="button" class="pos-topbar-ind" :class="{'is-offline': printStatus === 'error'}"
                @click="printMenu = !printMenu" :title="printLastError || '{{ ___('Printers') }}'">
            <span class="dot"></span>
            <span x-text="printChipLabel()"></span>
        </button>
        <div class="pos-print-menu" x-show="printMenu" x-cloak>
            <template x-for="p in printProfiles" :key="p.id">
                <div class="pos-print-row">
                    <span class="nm" x-text="p.name"></span>
                    <button type="button" @click="testPrinter(p)">{{ ___('Test print') }}</button>
                    <button type="button" x-show="lastOrderId" @click="printLastTo(p, 'new_order')">{{ ___('Receipt') }}</button>
                    <button type="button" x-show="lastOrderId" @click="printLastTo(p, 'kot')">{{ ___('Kitchen ticket') }}</button>
                </div>
            </template>
            <div class="pos-print-empty" x-show="!printProfiles.length">{{ ___('No printers configured.') }}</div>
        </div>
    </div>

    <span class="sp"></span>

    {{-- T1-7: keyboard shortcuts help (also "?" / F1) --}}
    <button type="button" class="pos-help-toggle" @click="toggleHelp()" title="{{ ___('Keyboard shortcuts') }}">?</button>
    {{-- P0-3: light/dark theme toggle (persisted in localStorage['pos-theme']) --}}
    <button type="button" class="pos-theme-toggle" @click="posToggleTheme()" title="{{ ___('Toggle theme') }}">☾</button>

    @if($menuLanguages->count() > 1)
        {{-- Menu language switcher: persists the choice in the Quick_user_lang_code
             cookie (same one the storefront + Menu accessors use) then reloads. --}}
        <select @change="switchLang($event.target.value)" title="{{ ___('Language') }}">
            @foreach($menuLanguages as $lng)
                <option value="{{ $lng->code }}" @selected($currentLang === $lng->code)>{{ $lng->name }}</option>
            @endforeach
        </select>
    @endif

    @if($isOwner && $stores->count() > 1)
        <select @change="location.href='{{ url('/pos') }}?store='+$el.value" title="{{ ___('Restaurant') }}">
            @foreach($stores as $r)
                <option value="{{ $r->id }}" @selected($r->id == $store->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    @else
        <span class="muted">{{ $store->title }}</span>
    @endif

    @if($branches->count())
        <select x-model.number="branch" @change="reloadForBranch()" title="{{ ___('Branch') }}">
            @foreach($branches as $b)
                <option value="{{ $b->id }}">{{ $b->name }}</option>
            @endforeach
        </select>
    @endif

    {{-- P2: open the customer-facing display on a second screen/tab --}}
    <a class="exit" href="#" @click.prevent="openDisplay()">{{ ___('Open customer display') }}</a>
    {{-- P3: lock the register (quick-switch) --}}
    <a class="exit" href="#" @click.prevent="lock()">{{ ___('Lock') }}</a>
    @if($isOwner)
        <a class="exit" href="{{ url('/dashboard') }}">{{ ___('Dashboard') }} →</a>
    @endif
</div>

{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- T3-2: Select Table screen — guest-count filter, legend, pos-table-tile grid
     grouped by zone. Tap a tile → assign flow (T3-3). --}}
<div class="pos-panel" x-show="activePanel === 'tables'" x-cloak x-init="initMerge()">
    <div class="pos-panel-head">
        <h2>{{ ___('Select Table') }}</h2>

        {{-- Gap 1: table STATE filter (stored-status parity with the Floor Map) --}}
        <div class="pos-seatfilter">
            <button type="button" :class="{on: stateFilter === 'all'}" @click="setStateFilter('all')">{{ ___('All tables') }}</button>
            <button type="button" :class="{on: stateFilter === 'vacant'}" @click="setStateFilter('vacant')">{{ ___('Vacant') }}</button>
            <button type="button" :class="{on: stateFilter === 'reserved'}" @click="setStateFilter('reserved')">{{ ___('Reserved') }}</button>
            <button type="button" :class="{on: stateFilter === 'occupied'}" @click="setStateFilter('occupied')">{{ ___('Occupied') }}</button>
            <button type="button" x-show="mergeEnabled" x-cloak :class="{on: stateFilter === 'merged'}" @click="setStateFilter('merged')">{{ ___('Merged party') }}</button>
            <button type="button" :class="{on: stateFilter === 'blocked'}" @click="setStateFilter('blocked')">{{ ___('Blocked') }}</button>
        </div>

        {{-- guest-count (capacity) segmented filter --}}
        <div class="pos-seatfilter">
            <button type="button" :class="{on: seatFilter === 'all'}" @click="setSeatFilter('all')">{{ ___('All') }}</button>
            <button type="button" :class="{on: seatFilter === '1-2'}" @click="setSeatFilter('1-2')">1–2</button>
            <button type="button" :class="{on: seatFilter === '4-6'}" @click="setSeatFilter('4-6')">4–6</button>
            <button type="button" :class="{on: seatFilter === '6+'}" @click="setSeatFilter('6+')">6+</button>
        </div>

        {{-- PARITY-RUN T4: merge mode. Hidden entirely when the store is not
             entitled (or the platform switch is off) — the endpoint refuses too,
             so this is presentation, not the gate. --}}
        <button type="button" class="pos-panel-close" x-show="mergeEnabled" x-cloak
                style="position:static;font-size:13px;width:auto;padding:0 12px"
                :class="{on: mergeMode}" @click="toggleMergeMode()"
                {{-- JS-encoded, never a quoted echo: a translation containing an
                     apostrophe (fr "l'addition") would close the JS string and
                     silently break Alpine. --}}
                x-text="mergeMode ? @js(___('Cancel')) : @js(___('Merge tables'))"></button>

        <button type="button" class="pos-panel-close" @click="activePanel = 'home'" title="{{ ___('Close') }}">&times;</button>
    </div>

    {{-- PARITY-RUN T4: merge-mode action bar (multi-select → confirm). --}}
    <div class="pos-mergebar" x-show="mergeMode" x-cloak>
        <span class="grow" x-text="mergeSelection.length
            ? @js(___('Selected tables') . ': ') + mergeSelection.map(id => mergeTableName(id)).join(' · ')
            : @js(___('Tap the tables to merge. The first one keeps the bill.'))"></span>
        <button type="button" @click="mergeSelection = []" x-show="mergeSelection.length">{{ ___('Clear') }}</button>
        <button type="button" class="go" :disabled="mergeSelection.length < 2" @click="openMergeDialog()">
            {{ ___('Merge tables') }}
        </button>
    </div>

    {{-- legend --}}
    <div class="pos-legend">
        <span><i class="dot is-vacant"></i> {{ ___('Vacant') }}</span>
        <span><i class="dot is-occupied"></i> {{ ___('Occupied') }}</span>
        <span><i class="dot is-served"></i> {{ ___('Served') }}</span>
        <span><i class="dot is-reserved"></i> {{ ___('Reserved') }}</span>
        <span><i class="dot is-blocked"></i> {{ ___('Blocked') }}</span>
        <span x-show="mergeEnabled" x-cloak><i class="dot is-merged"></i> {{ ___('Merged party') }}</span>
    </div>

    <div class="pos-panel-body">
        <p class="muted" x-show="tablesLoading" style="padding:16px">{{ ___('Loading…') }}</p>

        <template x-for="grp in tableZones()" :key="grp.zone">
            <div class="pos-zone">
                <h3 class="pos-zone-title" x-show="grp.zone" x-text="grp.zone"></h3>
                <div class="pos-tile-grid">
                    <template x-for="t in grp.tables" :key="t.id">
                        {{-- PARITY-RUN T4: in merge mode a tap selects instead of
                             seating; mergeTapped() returns true when it consumed it. --}}
                        <button type="button" class="pos-table-tile"
                                :class="['is-' + t.status, mergeSelected(t) ? 'is-selected' : '']"
                                @click="mergeTapped(t) || mergeSecondaryTapped(t) || selectTable(t)">
                            <span class="pos-merge-pick" x-show="mergeSelected(t)" x-cloak x-text="mergeSelectionIndex(t)"></span>
                            <span class="pos-tile-name" x-text="t.name"></span>
                            <span class="pos-tile-seats">
                                <span x-text="mergeEnabled && t.merge_seats ? t.merge_seats : t.seats"></span> {{ ___('seats') }}
                            </span>
                            {{-- merged party badge + release --}}
                            <span class="pos-tile-merge" x-show="t.merge_label" x-cloak x-text="t.merge_label"></span>
                            {{-- A <button> may not nest inside a <button>, so this is a
                                 span with the button role + its own keyboard handler. --}}
                            <span class="pos-tile-unmerge" role="button" tabindex="0"
                                  x-show="!mergeMode && t.merge_is_primary" x-cloak
                                  @click.stop="unmergeFromTile(t)"
                                  @keydown.enter.stop.prevent="unmergeFromTile(t)"
                                  @keydown.space.stop.prevent="unmergeFromTile(t)">{{ ___('Unmerge') }}</span>
                            <span class="pos-tile-covers" x-show="t.covers" x-text="t.covers + ' {{ ___('guests') }}'"></span>
                            <span class="pos-tile-covers" x-show="t.status === 'reserved'"
                                  x-text="'{{ ___('Booked') }} ' + (t.reserved_at ? bookedTime(t.reserved_at) : '') + (t.reserved_name ? ' · ' + t.reserved_name : '')"></span>
                            <span class="pos-age-chip" x-show="t.since && t.status !== 'vacant' && t.status !== 'reserved'" x-text="ageChip(t.since)"></span>
                            <span class="pos-tile-total" x-show="t.order_total" x-text="money(t.order_total)"></span>
                            <i class="dot" :class="'is-' + t.status"></i>
                        </button>
                    </template>
                </div>
            </div>
        </template>

        <div class="pos-empty" x-show="tablesLoaded && !tableCount()">
            <span class="ico">@include('staff.pos.partials.icon', ['name' => 'table', 'size' => 40])</span>
            <div>{{ ___('No tables match this filter.') }}</div>
        </div>
    </div>
</div>

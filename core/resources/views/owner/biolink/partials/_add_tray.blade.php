{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- bio-t15 C1 · "Add a block" tray — extracted verbatim from builder.blade.php
     (was inline in the left column). Uses the parent's PHP-block variables
     ($blockGroups, $blockTypes, $typeIcons, $typeLabels, $atLimit) — an
     include directive shares the including view's variable scope, so nothing
     is passed explicitly. A type listed in $blockGroups ahead of Lane A
     landing it in BLOCK_TYPES simply stays invisible here (array_intersect),
     no crash. --}}
<x-ui.card :title="___('Add a block')">
    @if($atLimit)
        <p class="ui-muted">{{ ___('You have reached your block limit on this plan. Remove a block or upgrade to add more.') }}</p>
    @endif
    {{-- bio-t15 C6 · with the Tier 1/2 catalog the tray can carry up to 28
         types (SPEC §2), so a quick client-side filter keeps it scannable —
         the 4 group headers still order things when the box is empty. --}}
    <input type="search" class="ui-input ui-input--sm" data-addtray-filter
           placeholder="{{ ___('Search blocks…') }}" aria-label="{{ ___('Search blocks') }}">
    <div class="bio-addtray" data-addtray>
        @foreach($blockGroups as $groupName => $groupTypes)
            @php $shown = array_values(array_intersect($groupTypes, $blockTypes)); @endphp
            @if(! empty($shown))
                <div class="bio-addtray__group" data-addtray-group>
                    <div class="bio-addtray__head">{{ $groupName }}</div>
                    <div class="bio-addtray__chips">
                        @foreach($shown as $type)
                            <button type="button" class="bio-addchip" data-add-block="{{ $type }}"
                                    data-addtray-chip data-chip-label="{{ mb_strtolower($typeLabels[$type] ?? $type) }}" @disabled($atLimit)>
                                <x-ui.shell.nav-icon :glyph="$typeIcons[$type] ?? 'link'" />{{ $typeLabels[$type] ?? $type }}
                                @if(in_array($type, $proBlockTypes ?? [], true))
                                    <span class="bio-addchip__pro" title="{{ ___('Available on the Pro plan') }}">{{ ___('PRO') }}</span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
        <p class="ui-muted ui-hidden" data-addtray-empty>{{ ___('No blocks match your search.') }}</p>
    </div>
</x-ui.card>

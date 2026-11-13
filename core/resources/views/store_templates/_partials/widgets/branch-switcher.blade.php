{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Theme Studio B0.7 — {% widget 'branch-switcher' %}. Pick a branch of a
    multi-branch store. Same mechanism as the Blade themes (partials/branch-switcher,
    picnic's branch list): plain links carrying ?branch_id=<id>, which publicView
    reads to apply per-branch availability + prices. No JS of its own. Renders
    nothing unless the store has more than one active branch.
--}}
@php
    $mbwId = 'mbw-branch-switcher';
    $mbwActiveId = $activeBranch->id ?? null;
    $branches = collect(\Juwri\Core\Support\Views\StoreViewHelpers::branchInstances($branches));
@endphp
@if($branches->count() > 1)
@if($widgetBase)@include('store_templates._partials.widgets._base')@endif
<button type="button" class="mbw-btn mbw-btn--ghost mbw-branch-switcher__trigger" data-mbw-open="branch-switcher" aria-haspopup="dialog" aria-controls="{{ $mbwId }}-dialog">
    <svg class="mbw-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 10c0 4.4-8 12-8 12s-8-7.6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
    <span>{{ $widgetLabel ?? ($activeBranch->name ?? ___('Choose branch')) }}</span>
</button>
@if($widgetFirst)
<dialog class="mbw-dialog mbw-branch-switcher" id="{{ $mbwId }}-dialog" aria-labelledby="{{ $mbwId }}-title">
    @include('store_templates._partials.widgets._head', ['mbwTitle' => ___('Choose branch')])
    <div class="mbw-body">
        <ul class="mbw-list">
            <li>
                <a class="mbw-row" href="{{ request()->fullUrlWithoutQuery(['branch_id']) }}" @if(! $mbwActiveId) aria-current="true" @endif>
                    <span>{{ ___('All locations') }}</span>
                </a>
            </li>
            @foreach($branches as $mbwBranch)
                <li>
                    <a class="mbw-row" href="{{ request()->fullUrlWithQuery(['branch_id' => $mbwBranch->id]) }}" @if((int) $mbwActiveId === (int) $mbwBranch->id) aria-current="true" @endif>
                        <span>{{ $mbwBranch->name }}</span>
                        <span class="mbw-muted">{{ $mbwBranch->isOpenNow() ? ___('Open now') : ___('Closed now') }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</dialog>
@endif
@endif

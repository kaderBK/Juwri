{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- #76 Customer-facing branch switcher (new branch_id system). Theme-agnostic, self-contained. --}}
@if(!empty($branches) && $branches->count() > 1)
    @php $activeBranchId = (isset($activeBranch) && $activeBranch) ? $activeBranch->id : ''; @endphp
    <div class="mbc-branch-switcher" role="group" aria-label="{{ ___('Choose branch') }}"
         style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 auto 16px;max-width:640px;padding:10px 14px;border-radius:14px;background:rgba(127,127,127,.10);border:1px solid rgba(127,127,127,.22);backdrop-filter:blur(6px);">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:.7;flex:none">
            <path d="M20 10c0 4.4-8 12-8 12s-8-7.6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>
        </svg>
        <span style="font-size:13px;font-weight:600;opacity:.75">{{ ___('Branch') }}</span>
        <select aria-label="{{ ___('Branch') }}"
                data-csp-branch-switch="true"
                style="flex:1;min-width:160px;font:inherit;font-size:14px;padding:8px 12px;border-radius:10px;border:1px solid rgba(127,127,127,.3);background:rgba(255,255,255,.06);color:inherit;cursor:pointer">
            <option value="">{{ ___('All locations') }}</option>
            @foreach($branches as $b)
                <option value="{{ $b->id }}" @selected($activeBranchId == $b->id)>
                    {{ $b->name }}@unless($b->isOpenNow()) — {{ ___('Closed') }}@endunless
                </option>
            @endforeach
        </select>
        @if(isset($activeBranch) && $activeBranch)
            <span style="font-size:12px;opacity:.7;white-space:nowrap">
                @if($activeBranch->isOpenNow()){{ ___('Open now') }}@else{{ ___('Closed now') }}@endif
            </span>
        @endif
    </div>
@endif

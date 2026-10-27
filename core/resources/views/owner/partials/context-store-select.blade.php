{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P1-3: write-through store/branch selector. Instead of a page-local
     ?store_id filter that can disagree with the sidebar, this posts the
     shared dashboard.context route (session-scoped) and redirects back to the
     current page — so the page and the sidebar switcher can NEVER diverge. --}}
@php
    $ctxStores = function_exists('dashboard_stores') ? dashboard_stores() : collect();
    $ctxStore = function_exists('dashboard_current_store') ? dashboard_current_store() : null;
    $ctxBranch = function_exists('dashboard_current_branch') ? dashboard_current_branch() : null;
    $ctxBranches = $ctxStore
        ? \Juwri\Core\Models\Branch::where('store_id', $ctxStore->id)->orderBy('position')->orderBy('id')->get()
        : collect();
    $showBranch = ($showBranch ?? true);
@endphp
@if($ctxStores->isNotEmpty() && Route::has('dashboard.context'))
    <form method="POST" action="{{ route('dashboard.context') }}" class="context-store-select ui-ctx" autocomplete="off">
        @csrf
        <input type="hidden" name="redirect_to" value="{{ request()->getRequestUri() }}">
        <input type="hidden" name="context_action" value="">
        <span class="ui-ctx__label" aria-hidden="true"><x-ui.icon name="store" size="16" /></span>
        <select name="store_id" class="ui-input ui-ctx__select" data-ui-do="set-submit" data-ui-field="context_action" data-ui-value="store" aria-label="{{ ___('Store') }}">
            @foreach($ctxStores as $o)
                <option value="{{ $o->id }}" @selected($ctxStore && $o->id === $ctxStore->id)>{{ $o->title }}</option>
            @endforeach
        </select>
        @if($showBranch && $ctxBranches->isNotEmpty())
            <select name="branch_id" class="ui-input ui-ctx__select" data-ui-do="set-submit" data-ui-field="context_action" data-ui-value="branch" aria-label="{{ ___('Branch') }}">
                <option value="">{{ ___('All branches') }}</option>
                @foreach($ctxBranches as $o)
                    <option value="{{ $o->id }}" @selected($ctxBranch && $o->id === $ctxBranch->id)>{{ $o->name }}</option>
                @endforeach
            </select>
        @endif
    </form>
@endif

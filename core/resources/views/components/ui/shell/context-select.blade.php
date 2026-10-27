{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.shell.context-select /> — store / branch switcher that writes through the
  shared dashboard.context route (session-scoped) and returns to this page, so a
  page filter and the sidebar can never disagree. Selects submit on change
  ([data-ui-autosubmit], assets/owner-ui/layout.js).
--}}
@props(['branches' => true])
@php
    $__stores = function_exists('dashboard_stores') ? dashboard_stores() : collect();
    $__store = function_exists('dashboard_current_store') ? dashboard_current_store() : null;
    $__branch = function_exists('dashboard_current_branch') ? dashboard_current_branch() : null;
    $__branches = ($branches && $__store)
        ? \Juwri\Core\Models\Branch::where('store_id', $__store->id)->orderBy('position')->orderBy('id')->get()
        : collect();
@endphp
@if ($__stores->isNotEmpty() && Route::has('dashboard.context'))
    <form method="POST" action="{{ route('dashboard.context') }}" class="ui-ctxform context-store-select" autocomplete="off">
        @csrf
        <input type="hidden" name="redirect_to" value="{{ request()->getRequestUri() }}">
        <input type="hidden" name="context_action" value="">
        <label class="ui-ctxform__row">
            <span class="ui-ctxform__label">{{ ___('Store') }}</span>
            <select name="store_id" class="ui-ctxform__select" data-ui-autosubmit data-ui-action="store">
                @foreach ($__stores as $__o)
                    <option value="{{ $__o->id }}" @selected($__store && $__o->id === $__store->id)>{{ $__o->title }}</option>
                @endforeach
            </select>
        </label>
        @if ($__branches->isNotEmpty())
            <label class="ui-ctxform__row">
                <span class="ui-ctxform__label">{{ ___('Branch') }}</span>
                <select name="branch_id" class="ui-ctxform__select" data-ui-autosubmit data-ui-action="branch">
                    <option value="">{{ ___('All branches') }}</option>
                    @foreach ($__branches as $__o)
                        <option value="{{ $__o->id }}" @selected($__branch && $__o->id === $__branch->id)>{{ $__o->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
    </form>
@endif

{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Staff accounts'))
@section('page_title', ___('Staff accounts'))
@section('page_sub', ___('Manager, cashier, kitchen, and waiter logins per store.'))

@section('content')
@php
    $byRest = $staff->groupBy('store_id');
@endphp

<div class="ui-scope ui-account ui-staff">
{{-- Hero --}}
<section class="ui-card ui-account__hero">
    <div>
        <p class="ui-overline">{{ ___('Team') }}</p>
        <h2 class="ui-account__name">{{ ___('Store staff') }}</h2>
        <p class="ui-account__row-sub">{{ ___('Each staff member logs into a single store with role-scoped access.') }}</p>
    </div>
    <dl class="ui-account__facts ui-staff__facts">
        <div><dt>{{ ___('Members') }}</dt><dd>{{ $staff->count() }}</dd></div>
        <div><dt>{{ ___('Stores') }}</dt><dd>{{ $stores->count() }}</dd></div>
    </dl>
</section>

{{-- P6-220 UI: when the plan's staff cap was lowered (or the plan rolled back) the controller
     switched staff members off to fit — accounts and history remain, just `active=false`.
     Surface that here so the owner isn't surprised by the sudden "Disabled" row, and link
     straight to the upgrade page if the route is registered. --}}
@if(($planSuspended ?? 0) > 0)
    <x-ui.banner tone="warning">
        {{ ___(':n staff members were switched off because your plan allows :limit active staff per store. They cannot sign in until switched back on. Nothing was deleted. Upgrade your plan to switch them all back on.', ['n' => $planSuspended, 'limit' => $staffLimit]) }}
        @if(\Illuminate\Support\Facades\Route::has('user.plans'))
            <a href="{{ route('user.plans') }}">{{ ___('Upgrade plan') }}</a>
        @endif
    </x-ui.banner>
@endif

@if ($stores->isNotEmpty())
    <x-ui.card :title="___('Add staff member')">
        <form action="{{ route('staff.store') }}" method="post" class="ui-staff__add">
            @csrf
            <div class="ui-staff__field">
                <label class="ui-label" for="sf-name">{{ ___('Name') }}</label>
                <input id="sf-name" type="text" name="name" class="ui-input" required maxlength="80" autocomplete="off">
            </div>
            <div class="ui-staff__field">
                <label class="ui-label" for="sf-email">{{ ___('Email') }}</label>
                <input id="sf-email" type="email" name="email" class="ui-input ui-ltr" required maxlength="120" autocomplete="off" inputmode="email">
            </div>
            <div class="ui-staff__field">
                <label class="ui-label" for="sf-password">{{ ___('Password') }}</label>
                <input id="sf-password" type="password" name="password" class="ui-input" required minlength="6" autocomplete="new-password">
            </div>
            <div class="ui-staff__field">
                <label class="ui-label" for="sf-store">{{ ___('Store') }}</label>
                <select id="sf-store" name="store_id" class="ui-input" required>
                    @foreach ($stores as $r)
                        <option value="{{ $r->id }}">{{ $r->title }}</option>
                    @endforeach
                </select>
            </div>
            {{-- BL-036 A2b: assign a branch on creation (empty = all branches of the store). --}}
            <div class="ui-staff__field">
                <label class="ui-label" for="sf-branch">{{ ___('Branch') }}</label>
                <select id="sf-branch" name="branch_id" class="ui-input">
                    <option value="">{{ ___('All branches') }}</option>
                    @foreach ($stores as $r)
                        @php $storeBranches = $branches->where('store_id', $r->id); @endphp
                        @if ($storeBranches->isNotEmpty())
                            <optgroup label="{{ $r->title }}">
                                @foreach ($storeBranches as $b)
                                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                                @endforeach
                            </optgroup>
                        @endif
                    @endforeach
                </select>
            </div>
            <div class="ui-staff__field">
                <label class="ui-label" for="sf-role">{{ ___('Role') }}</label>
                <select id="sf-role" name="role" class="ui-input" required>
                    <option value="manager">{{ ___('Manager') }}</option>
                    <option value="cashier">{{ ___('Cashier') }}</option>
                    <option value="kitchen">{{ ___('Kitchen') }}</option>
                    <option value="waiter">{{ ___('Waiter') }}</option>
                </select>
            </div>
            <div class="ui-staff__field">
                <label class="ui-label" for="sf-pin">{{ ___('PIN (optional)') }}</label>
                <input id="sf-pin" type="text" name="pin" class="ui-input ui-ltr" maxlength="6" pattern="\d{4,6}" inputmode="numeric" autocomplete="off">
            </div>
            <div class="ui-staff__submit">
                <x-ui.button type="submit" icon="plus">{{ ___('Add staff') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>
@endif

@if ($staff->isEmpty())
    <div class="ui-card"><x-ui.empty :title="___('No staff yet')" :body="___('Add managers, cashiers, kitchen, or waiters to delegate access.')" icon="users" /></div>
@else
    @foreach ($byRest as $restId => $members)
        @php $rest = $stores->firstWhere('id', $restId); @endphp
        {{-- BL-036 A2b: the permission sheet only offers switches for features this store has (hotel keys need the
             hotel; `feature` keys need the plan): StaffController::index builds the groups per store. --}}
        @php $permGroups = $permGroupsByStore[$restId] ?? []; @endphp
        <x-ui.card :title="$rest?->title ?? '—'" variant="flush">
            <x-slot:actions><x-ui.tag>{{ $members->count() }} {{ ___('members') }}</x-ui.tag></x-slot:actions>
            <div>
                <table class="ui-table ui-staff__table">
                    <thead><tr><th>{{ ___('Name') }}</th><th>{{ ___('Email') }}</th><th>{{ ___('Role') }}</th><th>{{ ___('Branch') }}</th><th>{{ ___('Active') }}</th><th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th></tr></thead>
                    <tbody>
                        @foreach ($members as $m)
                            <tr>
                                <td data-primary><strong>{{ $m->name }}</strong></td>
                                <td data-label="{{ ___('Email') }}"><span class="ui-ltr ui-staff__email">{{ $m->email }}</span></td>
                                <td data-label="{{ ___('Role') }}"><x-ui.tag :tone="\Juwri\Core\Support\Views\OwnerOpsViewHelpers::staffRoleTone($m->role)">{{ ___($m->role) }}</x-ui.tag></td>
                                <td data-label="{{ ___('Branch') }}">
                                    {{-- BL-036 A2b: owners assign (or clear) a staff member's branch. --}}
                                    @php $memberBranches = $branches->where('store_id', $m->store_id); @endphp
                                    @if ($memberBranches->isNotEmpty())
                                        <form action="{{ route('staff.update', $m->id) }}" method="post" class="ui-staff__branch">
                                            @csrf @method('PUT')
                                            <input type="hidden" name="name" value="{{ $m->name }}">
                                            <input type="hidden" name="role" value="{{ $m->role }}">
                                            <label class="ui-visually-hidden" for="sb-{{ $m->id }}">{{ ___('Branch') }}</label>
                                            <select id="sb-{{ $m->id }}" name="branch_id" class="ui-input">
                                                <option value="">{{ ___('All branches') }}</option>
                                                @foreach ($memberBranches as $b)
                                                    <option value="{{ $b->id }}" @selected((int) $m->branch_id === (int) $b->id)>{{ $b->name }}</option>
                                                @endforeach
                                            </select>
                                            <x-ui.button type="submit" size="sm" variant="secondary">{{ ___('Save') }}</x-ui.button>
                                        </form>
                                    @else
                                        <span class="ui-muted">{{ ___('All branches') }}</span>
                                    @endif
                                </td>
                                <td data-label="{{ ___('Active') }}">
                                    {{-- P6-220 UI: a disabled member with a plan-suspension timestamp was
                                         turned off by the staff-cap rule, not by the owner. Show the
                                         same "Off: plan limit" wording as the banner above + the
                                         warning tone so the cause matches at a glance. --}}
                                    @if(!$m->active && !empty($m->plan_suspended_at))
                                        <x-ui.pill :tone="'warning'">{{ ___('Off: plan limit') }}</x-ui.pill>
                                    @else
                                        <x-ui.pill :tone="$m->active ? 'success' : 'neutral'">{{ $m->active ? ___('Active') : ___('Disabled') }}</x-ui.pill>
                                    @endif
                                </td>
                                <td class="ui-staff__actions">
                                    {{-- BL-036 A2b: per-staff permissions live in a sheet (one per member), grouped
                                         switches pre-set from the role default (overrides win). All switches ride
                                         ONE form: StaffController::update stores the map; name, role and branch_id
                                         ride along because update() requires them and clears branch_id when absent. --}}
                                    <x-ui.button size="sm" variant="secondary" type="button" :data-ui-open="'perms-'.$m->id">
                                        {{ ___('Permissions') }}
                                        <span class="ui-muted">{{ $permCounts[$m->id] ?? 0 }}</span>
                                    </x-ui.button>
                                    <x-ui.sheet :id="'perms-'.$m->id" class="ui-staff__perm-sheet" :title="___('Permissions for :name', ['name' => $m->name])" :description="___('Starts from the :role role. Changes apply to this member only.', ['role' => ___($m->role)])">
                                        <form action="{{ route('staff.update', $m->id) }}" method="post" id="perms-form-{{ $m->id }}" class="ui-staff__perm-form">
                                            @csrf @method('PUT')
                                            <input type="hidden" name="name" value="{{ $m->name }}">
                                            <input type="hidden" name="role" value="{{ $m->role }}">
                                            <input type="hidden" name="branch_id" value="{{ $m->branch_id }}">
                                            @foreach ($permGroups as $groupLabel => $perms)
                                                <fieldset class="ui-staff__perm-group">
                                                    <legend>{{ ___($groupLabel) }}</legend>
                                                    @foreach ($perms as $pk => $spec)
                                                        <x-ui.switch :id="'pm-'.$pk.'-'.$m->id" :name="'permissions['.$pk.']'" off="0" :checked="$m->hasPermission($pk)" :label="___($spec['label'])" />
                                                    @endforeach
                                                </fieldset>
                                            @endforeach
                                        </form>
                                        <x-slot:footer>
                                            <x-ui.button variant="secondary" type="button" data-ui-close>{{ ___('Cancel') }}</x-ui.button>
                                            <x-ui.button variant="dark" type="submit" :form="'perms-form-'.$m->id">{{ ___('Save permissions') }}</x-ui.button>
                                        </x-slot:footer>
                                    </x-ui.sheet>
                                    {{-- P1-19b: this staff member's own passkeys; the owner can revoke one (lost phone, left a shared device). --}}
                                    @if($m->passkeys->isNotEmpty())
                                        <details class="ui-staff__passkeys" data-staff-passkeys="{{ $m->id }}">
                                            <summary class="ui-small">{{ ___('Passkeys: :count', ['count' => $m->passkeys->count()]) }}</summary>
                                            <ul class="ui-list">
                                                @foreach($m->passkeys as $pk)
                                                    <li class="ui-list__item ui-cluster">
                                                        <span class="ui-small">{{ $pk->name }} · {{ $pk->last_used_at ? ___('Last used :date', ['date' => $pk->last_used_at->diffForHumans()]) : ___('Never used') }}</span>
                                                        <form action="{{ route('staff.passkeys.revoke', [$m->id, $pk->id]) }}" method="post" data-confirm="{{ ___('Remove this passkey? This staff member will no longer be able to sign in with it.') }}" data-confirm-danger>
                                                            @csrf @method('DELETE')
                                                            <button type="submit" class="ui-btn ui-btn--danger ui-btn--sm">{{ ___('Revoke') }}</button>
                                                        </form>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @endif
                                    @if(Route::has('staff.hrm.show') && \App\Services\FeatureRegistry::isEnabled('hrm_suite'))
                                        <a href="{{ route('staff.hrm.show', $m->id) }}" class="ui-btn ui-btn--secondary ui-btn--sm">{{ ___('HR file') }}</a>
                                    @endif
                                    <form action="{{ route('staff.destroy', $m->id) }}" method="post" data-confirm="{{ ___('Remove this staff member?') }}" data-confirm-danger>
                                        @csrf @method('DELETE')
                                        <button type="submit" class="ui-icon-btn ui-menu-item__delete" aria-label="{{ ___('Remove') }}" title="{{ ___('Remove') }}"><x-ui.icon name="trash" /></button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endforeach
@endif
</div>
@endsection

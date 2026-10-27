{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Branches'))
@section('page_title', ___('Branches'))
@section('page_sub', ___('Locations for this store brand. Each can have its own QR, orders and contact.'))

@section('content')
@php
    // P5-30: owner Google UI — per branch, compute eligibility + merchant id once
    // (the store's merchant rule: single eligible branch keeps `store-<id>`, several
    // publish `store-<id>-b<branch>`). Shared by the edit dialog and the summary card.
    $googleSource = app(\Juwri\Modules\Storefront\Services\GoogleOrdering\GoogleOrderingMerchantSource::class);
    $eligibleBranchIds = $storeId
        ? \Juwri\Core\Support\Views\StoreViewHelpers::eligibleBranchIds($googleSource->eligibleBranches((int) $storeId))
        : [];
    $storeSingle = count($eligibleBranchIds) === 1;
    // When the save bounced (validation error) we re-open the edit dialog with the
    // submitted input, so the merchant never loses their edit.
    $reopenBranchId = (int) old('edit_branch_id');
    $editInitial = [];
    if ($reopenBranchId) {
        $rb = $branches->firstWhere('id', $reopenBranchId);
        if ($rb) {
            $g = \Juwri\Core\Support\Views\StoreViewHelpers::branchGoogleData($rb, $eligibleBranchIds, $storeSingle, (int) $storeId);
            $editInitial = [
                'name' => (string) old('name', $rb->name),
                'address' => old('address', $rb->address),
                'phone' => old('phone', $rb->phone),
                'whatsapp' => old('whatsapp', $rb->whatsapp),
                'timezone' => old('timezone', $rb->timezone),
                'closed_message' => old('closed_message', $rb->closed_message),
                'google_place_id' => old('google_place_id', $rb->google_place_id),
                'active' => (bool) old('active', $rb->active),
                'accepting_orders' => (bool) old('accepting_orders', $rb->accepting_orders),
                'ot_on_table' => old('order_types.on-table', ''),
                'ot_takeaway' => old('order_types.takeaway', ''),
                'ot_delivery' => old('order_types.delivery', ''),
                'merchant_id' => $g['merchant_id'],
                'eligible' => $g['eligible'],
            ];
        }
    }
@endphp
<div class="ui-scope ui-branches"
    x-data="{ editId: {{ $reopenBranchId }}, edit: @js($editInitial) }"
    x-init="$nextTick(() => { if (editId) { const d = $refs.editDialog; if (d && ! d.open) d.showModal(); } })">
    <form method="get" action="{{ route('branches.index') }}">
        <select name="store_id" class="ui-input ui-mods__store" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            <option value="">{{ ___('Select store…') }}</option>
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($storeId == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>

    {{-- P6-288: shared timezone picker datalist. Rendered once per page so every
         timezone field can attach via list="tz-list" without re-emitting options. --}}
    <datalist id="tz-list" aria-hidden="true">
        @foreach(\DateTimeZone::listIdentifiers() as $tz)
            <option value="{{ $tz }}"></option>
        @endforeach
    </datalist>

    {{-- WA-S3: this page had no error output at all, so a rejected save just looked
         like nothing happened. Validation is useless if the merchant cannot see it. --}}
    @if($errors->any())
        <x-ui.banner tone="danger">
            <ul class="ui-branches__errors">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-ui.banner>
    @endif

    {{-- P6-220 UI: when the plan's branch cap was lowered (or the owner's plan rolled back)
         the controller switched off branches to fit — nothing was deleted, those rows just lost
         `active`. Surface this so the merchant knows WHY, with a direct upgrade link. --}}
    @if(($planSuspended ?? 0) > 0)
        <x-ui.banner tone="warning">
            {{ ___(':n branches were switched off because your plan allows :limit active branches per store. Nothing was deleted. To choose which stay on, switch one off and another on. Upgrade your plan to switch them all back on.', ['n' => $planSuspended, 'limit' => $branchLimit]) }}
            @if(\Illuminate\Support\Facades\Route::has('user.plans'))
                <a href="{{ route('user.plans') }}">{{ ___('Upgrade plan') }}</a>
            @endif
        </x-ui.banner>
    @endif

    {{-- session('success')/('error') already surface as toasts via partials.flash. --}}
    @include('owner.partials.store-limit-notice')

    @if(!$storeId)
        <div class="ui-card"><x-ui.empty :title="___('Pick a store to manage its branches.')" icon="store" /></div>
    @else
        <div class="ui-branches__tools">
            <x-ui.button :href="route('branches.reports', ['store_id' => $storeId])" variant="secondary" size="sm" icon="chart">
                {{ ___('Branch reports') }}
            </x-ui.button>
            <x-ui.button :href="route('branches.qr', $storeId)" variant="secondary" size="sm" icon="qr">
                {{ ___('Branch QR codes') }}
            </x-ui.button>
        </div>

        {{-- Storefront branch picker: customers pick the branch on the menu and at checkout. --}}
        <x-ui.card>
            <form method="post" action="{{ route('branches.switcher') }}">
                @csrf @method('PUT')
                <input type="hidden" name="store_id" value="{{ $storeId }}">
                <x-ui.switch name="allow_multi_branch_switch" off="0" :checked="$branchSwitcher" data-ui-autosubmit
                    :label="___('Customers choose a branch')"
                    :description="___('Show a branch picker on your menu and at checkout when you have more than one active branch.')" />
            </form>
        </x-ui.card>

        <x-ui.card :title="___('Add branch')">
            <form method="post" action="{{ route('branches.store') }}" class="ui-branches__add">
                @csrf
                <input type="hidden" name="store_id" value="{{ $storeId }}">
                <div class="ui-branches__field">
                    <label class="ui-label" for="br-name">{{ ___('Name') }}</label>
                    <input id="br-name" class="ui-input" name="name" placeholder="{{ ___('Downtown') }}" required>
                </div>
                <div class="ui-branches__field ui-branches__field--wide">
                    <label class="ui-label" for="br-address">{{ ___('Address') }}</label>
                    <input id="br-address" class="ui-input" name="address">
                </div>
                <div class="ui-branches__field">
                    <label class="ui-label" for="br-phone">{{ ___('Phone') }}</label>
                    <input id="br-phone" class="ui-input ui-ltr" name="phone" type="tel" inputmode="tel">
                </div>
                <div class="ui-branches__field">
                    <label class="ui-label" for="br-wa">{{ ___('WhatsApp') }}</label>
                    <input id="br-wa" class="ui-input ui-ltr" name="whatsapp" type="tel" inputmode="tel">
                </div>
                <x-ui.button type="submit" icon="plus">{{ ___('Add branch') }}</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card :title="___('Branches')">
            <x-slot:actions><x-ui.tag>{{ $branches->count() }}</x-ui.tag></x-slot:actions>
            @if($branches->isEmpty())
                <x-ui.empty :title="___('No branches yet.')" icon="store" />
            @else
                <div class="ui-branches__list">
                    @foreach ($branches as $b)
                        @php
                            $status = $b->statusLabel();
                            $statusTone = ['open'=>'success','closed'=>'warning','paused'=>'accent','inactive'=>'neutral'][$status] ?? 'neutral';
                            $statusText = ['open'=>___('Open'),'closed'=>___('Closed'),'paused'=>___('Paused'),'inactive'=>___('Inactive')][$status] ?? $status;
                            // P6-220 UI: an inactive branch with a plan-suspension timestamp was
                            // turned off by the cap rule, not by the owner. Replace the generic
                            // "Inactive" with the explicit "Off: plan limit" wording (same tone
                            // as the banner above) so the cause is obvious on every row.
                            if ($status === 'inactive' && ! empty($b->plan_suspended_at)) {
                                $statusTone = 'warning';
                                $statusText = ___('Off: plan limit');
                            }
                            $g = \Juwri\Core\Support\Views\StoreViewHelpers::branchGoogleData($b, $eligibleBranchIds, $storeSingle, (int) $storeId);
                            $branchEdit = [
                                'name' => $b->name,
                                'address' => $b->address,
                                'phone' => $b->phone,
                                'whatsapp' => $b->whatsapp,
                                'active' => (bool) $b->active,
                                'accepting_orders' => (bool) $b->accepting_orders,
                                'timezone' => $b->timezone,
                                'closed_message' => $b->closed_message,
                                // P5-30: branch's own Google place ID + its merchant/eligibility.
                                'google_place_id' => $b->google_place_id,
                                'merchant_id' => $g['merchant_id'],
                                'eligible' => $g['eligible'],
                                // D6: per-branch order-type overrides ('' inherit | '1' on | '0' off)
                                'ot_on_table' => \Juwri\Core\Support\Views\StoreViewHelpers::otOverrideState($b, 'on-table'),
                                'ot_takeaway' => \Juwri\Core\Support\Views\StoreViewHelpers::otOverrideState($b, 'takeaway'),
                                'ot_delivery' => \Juwri\Core\Support\Views\StoreViewHelpers::otOverrideState($b, 'delivery'),
                                // P5-31: optional overrides of the store's values ('' = use the store's)
                                'prep_time_minutes' => $b->prep_time_minutes === null ? '' : (string) $b->prep_time_minutes,
                                'delivery_fee' => $b->delivery_fee_minor === null ? '' : (string) $b->delivery_fee,
                                'min_order' => $b->min_order_minor === null ? '' : (string) $b->min_order,
                            ];
                            /* WA-S3: what a storefront order taken at this branch will really
                               do — the branch number, or whatever it falls back to. Merchants
                               could previously only guess. */
                            $waProblem = whatsapp_number_problem($b->whatsapp);
                            $waRouted  = storefront_whatsapp_number($storeId, $b, false);
                            $waIsOwn   = $waRouted !== '' && $waRouted === whatsapp_normalize_number($b->whatsapp);
                        @endphp
                        <article class="ui-branches__item">
                            <header class="ui-branches__head">
                                <strong class="ui-branches__name">{{ $b->name }}</strong>
                                <x-ui.pill :tone="$statusTone">{{ $statusText }}</x-ui.pill>
                            </header>
                            @if($b->address)
                                <p class="ui-branches__line"><x-ui.icon name="pin" size="16" />{{ $b->address }}</p>
                            @endif
                            @if($b->phone || $b->whatsapp)
                                <p class="ui-branches__line">
                                    <x-ui.icon name="phone" size="16" />
                                    <span class="ui-ltr">{{ $b->phone }}</span>
                                    @if($b->whatsapp)<span class="ui-branches__wa ui-ltr">WA: {{ $b->whatsapp }}</span>@endif
                                </p>
                            @endif
                            @if($waProblem)
                                <p class="ui-branches__route" data-tone="danger">{{ $waProblem }}</p>
                            @elseif($waRouted === '')
                                <p class="ui-branches__route" data-tone="danger">{{ ___('Orders here have no WhatsApp number to go to.') }}</p>
                            @elseif($waIsOwn)
                                <p class="ui-branches__route" data-tone="success">{{ ___('Orders here go to') }} <span class="ui-ltr">{{ $waRouted }}</span></p>
                            @else
                                <p class="ui-branches__route" data-tone="warning">{{ ___('No number on this branch — orders here fall back to') }} <span class="ui-ltr">{{ $waRouted }}</span></p>
                            @endif
                            <p class="ui-branches__line">
                                <x-ui.icon name="brand-google" size="16" />
                                @if($b->google_place_id)
                                    <span>{{ ___('Place ID set') }} <code class="ui-ltr" dir="ltr">{{ $b->google_place_id }}</code></span>
                                @else
                                    <span>{{ ___('Place ID missing') }}</span>
                                @endif
                            </p>
                            <div class="ui-branches__toggles">
                                @foreach (['active' => ___('Active'), 'accepting_orders' => ___('Accepting orders')] as $field => $fieldLabel)
                                    <form method="post" action="{{ route('branches.toggle', $b->id) }}">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="field" value="{{ $field }}">
                                        <x-ui.switch :id="'bt-'.$field.'-'.$b->id" name="value" off="0" :checked="(bool) $b->{$field}" :label="$fieldLabel" data-ui-autosubmit />
                                    </form>
                                @endforeach
                            </div>
                            <footer class="ui-branches__actions">
                                <x-ui.button type="button" variant="secondary" size="sm" icon="clock"
                                    @click="$refs['hoursDialog{{ $b->id }}'].showModal()">{{ ___('Hours') }}</x-ui.button>
                                <x-ui.button :href="route('branches.menu.edit', $b->id)" variant="secondary" size="sm" icon="products">{{ ___('Menu') }}</x-ui.button>
                                <span class="ui-branches__icons">
                                <button type="button" class="ui-icon-btn" aria-label="{{ ___('Edit') }}" title="{{ ___('Edit') }}"
                                    @click="editId = {{ $b->id }}; edit = {{ \Illuminate\Support\Js::from($branchEdit) }}; $refs.editDialog.showModal()">
                                    <x-ui.icon name="pencil" />
                                </button>
                                <form method="post" action="{{ route('branches.destroy', $b->id) }}" data-confirm="{{ ___('Delete this branch?') }}" data-confirm-danger>
                                    @csrf @method('DELETE')
                                    <button type="submit" class="ui-icon-btn ui-menu-item__delete" aria-label="{{ ___('Delete') }}" title="{{ ___('Delete') }}"><x-ui.icon name="trash" /></button>
                                </form>
                            </span>
                            </footer>
                        </article>
                    @endforeach
                </div>

                    {{-- Hidden per-branch hours forms (one per branch), opened by the hours dialog --}}
                    @foreach ($branches as $b)
                        <dialog class="ui-dialog" x-ref="hoursDialog{{ $b->id }}">
                            <div class="ui-dialog__panel ui-card">
                                <form method="post" action="{{ route('branches.hours', $b->id) }}">
                                    @csrf @method('PUT')
                                    <div class="ui-cluster ui-cluster--between ui-card__head">
                                        <h4 class="ui-section-title">{{ ___('Opening hours') }} — {{ $b->name }}</h4>
                                        <button type="button" class="ui-icon-btn" @click="$refs['hoursDialog{{ $b->id }}'].close()" aria-label="{{ ___('Close') }}">&times;</button>
                                    </div>
                                    <div class="ui-card__body">
                                        <div class="ui-field">
                                            <label class="ui-label" for="br-hours-tz-{{ $b->id }}">{{ ___('Timezone') }}</label>
                                            {{-- P6-288: attach to the shared #tz-list; old() keeps a rejected save visible. --}}
                                            <input id="br-hours-tz-{{ $b->id }}" class="ui-input" name="timezone" list="tz-list"
                                                value="{{ old('timezone', $b->timezone ?? config('app.timezone')) }}"
                                                placeholder="{{ ___('Search a city or IANA name, e.g. Asia/Riyadh') }}">
                                            <span class="ui-field__help">{{ ___('An IANA timezone identifier such as Asia/Riyadh or America/New_York. Pick the timezone your local opening hours are reported in.') }}</span>
                                        </div>
                                        @foreach(\Juwri\Core\Models\Branch::DAYS as $day)
                                            @php $dh = $b->dayHours($day); @endphp
                                            <div class="ui-cluster">
                                                <strong style="inline-size:48px">{{ ___(ucfirst($day)) }}</strong>
                                                <input class="ui-input" type="time" name="hours[{{ $day }}][open]" value="{{ $dh['open'] }}" style="inline-size:120px">
                                                <span>–</span>
                                                <input class="ui-input" type="time" name="hours[{{ $day }}][close]" value="{{ $dh['close'] }}" style="inline-size:120px">
                                                <label class="ui-check"><input type="checkbox" name="hours[{{ $day }}][closed]" value="1" @checked($dh['closed'])><span>{{ ___('Closed') }}</span></label>
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="ui-cluster ui-cluster--end ui-card__foot">
                                        <x-ui.button type="button" variant="ghost" @click="$refs['hoursDialog{{ $b->id }}'].close()">{{ ___('Cancel') }}</x-ui.button>
                                        <x-ui.button type="submit" icon="check">{{ ___('Save hours') }}</x-ui.button>
                                    </div>
                                </form>
                                <div class="ui-card__body" x-data="{ closedAll: true }">
                                    <h5 class="ui-section-title">{{ ___('Holidays and special hours') }}</h5>
                                    @forelse($b->hourExceptions as $ex)
                                        <div class="ui-cluster ui-cluster--between">
                                            <span><strong dir="ltr">{{ $ex->date->format('Y-m-d') }}</strong>
                                                {{ $ex->closed ? ___('Closed') : $ex->open.' – '.$ex->close }}
                                                @if($ex->note)<span class="ui-muted">{{ $ex->note }}</span>@endif</span>
                                            <form method="post" action="{{ route('branches.exceptions.destroy', [$b->id, $ex->id]) }}">
                                                @csrf @method('DELETE')
                                                <x-ui.button type="submit" variant="ghost">{{ ___('Delete') }}</x-ui.button>
                                            </form>
                                        </div>
                                    @empty
                                        <p class="ui-muted">{{ ___('No holidays or special hours yet.') }}</p>
                                    @endforelse
                                    <form method="post" action="{{ route('branches.exceptions.store', $b->id) }}" class="ui-cluster" style="margin-block-start: var(--ui-space-3)">
                                        @csrf
                                        <input class="ui-input" type="date" name="date" required style="inline-size:160px" aria-label="{{ ___('Date') }}">
                                        <label class="ui-check"><input type="checkbox" name="closed" value="1" :checked="closedAll" @change="closedAll = $event.target.checked"><span>{{ ___('Closed all day') }}</span></label>
                                        <span class="ui-cluster" x-show="!closedAll">
                                            <input class="ui-input" type="time" name="open" style="inline-size:120px" aria-label="{{ ___('Opening time') }}">
                                            <span>–</span>
                                            <input class="ui-input" type="time" name="close" style="inline-size:120px" aria-label="{{ ___('Closing time') }}">
                                        </span>
                                        <input class="ui-input" name="note" maxlength="120" placeholder="{{ ___('Note') }}" style="inline-size:180px">
                                        <x-ui.button type="submit" icon="check">{{ ___('Add date') }}</x-ui.button>
                                    </form>
                                </div>
                            </div>
                        </dialog>
                    @endforeach
            @endif
        </x-ui.card>

        <x-ui.card :title="___('Google branches')">
            <p class="ui-muted">{{ ___('Each branch is its own Google merchant. Set the branch\'s place ID so Google links bookings and orders to the right listing.') }}</p>
            <div class="ui-branches__list" style="margin-block-start: var(--ui-space-3)">
                @foreach ($branches as $b)
                    @php $g = \Juwri\Core\Support\Views\StoreViewHelpers::branchGoogleData($b, $eligibleBranchIds, $storeSingle, (int) $storeId); @endphp
                    <div class="ui-branches__item">
                        <p class="ui-branches__line">
                            <span class="ui-label">{{ ___('Branch') }}</span>
                            <strong class="ui-branches__name">{{ $b->name }}</strong>
                            @if($b->google_place_id)
                                <span data-tone="success">{{ ___('Place ID set') }} <code class="ui-ltr" dir="ltr">{{ $b->google_place_id }}</code></span>
                            @else
                                <span data-tone="warning">{{ ___('Place ID missing') }}</span>
                            @endif
                        </p>
                        <p class="ui-branches__route">{{ ___('Google merchant ID') }}: <code class="ui-ltr" dir="ltr">{{ $g['merchant_id'] ?? ___('Not published') }}</code></p>
                        <p class="ui-branches__route" @if($g['eligible']) data-tone="success" @endif>{{ $g['eligible'] ? ___('Eligible for Google') : ___('Not eligible: the branch must be active and have coordinates.') }}</p>
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        <dialog class="ui-dialog" x-ref="editDialog">
            <div class="ui-dialog__panel ui-card">
                <form method="post" :action="@js(url('branches')) + '/' + editId">
                    @csrf @method('PUT')
                    <input type="hidden" name="edit_branch_id" :value="editId">
                    <div class="ui-cluster ui-cluster--between ui-card__head">
                        <h4 class="ui-section-title">{{ ___('Edit branch') }}</h4>
                        <button type="button" class="ui-icon-btn" @click="$refs.editDialog.close()" aria-label="{{ ___('Close') }}">&times;</button>
                    </div>
                    <div class="ui-card__body ui-stack">
                        <div><label class="ui-label" for="edit-name">{{ ___('Name') }}</label><input class="ui-input" id="edit-name" name="name" x-model="edit.name" required></div>
                        <div><label class="ui-label" for="edit-address">{{ ___('Address') }}</label><input class="ui-input" id="edit-address" name="address" x-model="edit.address"></div>
                        <div><label class="ui-label" for="edit-phone">{{ ___('Phone') }}</label><input class="ui-input" id="edit-phone" name="phone" x-model="edit.phone"></div>
                        <div><label class="ui-label" for="edit-whatsapp">{{ ___('WhatsApp') }}</label><input class="ui-input" id="edit-whatsapp" name="whatsapp" x-model="edit.whatsapp"></div>
                        <div class="ui-field" @class(['ui-field--error' => $errors->has('google_place_id')])>
                            <label class="ui-field__label" for="edit-google-place-id">{{ ___('Google place ID') }}</label>
                            <div class="ui-field__control">
                                <input id="edit-google-place-id" class="ui-input ui-ltr" name="google_place_id" x-model="edit.google_place_id"
                                    aria-describedby="edit-google-place-id-help{{ $errors->has('google_place_id') ? ' edit-google-place-id-error' : '' }}"
                                    @if($errors->has('google_place_id')) aria-invalid="true" @endif>
                            </div>
                            <span class="ui-field__help" id="edit-google-place-id-help">{{ ___('Optional. The place ID of this branch\'s own Google Maps listing.') }}</span>
                            @error('google_place_id')
                                <p class="ui-field__help" role="alert" id="edit-google-place-id-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="ui-field">
                            {{-- P6-288: shared datalist picker, same treatment as the hours dialog. --}}
                            <label class="ui-label" for="edit-tz">{{ ___('Timezone') }}</label>
                            <input id="edit-tz" class="ui-input" name="timezone" list="tz-list" x-model="edit.timezone"
                                placeholder="{{ ___('Search a city or IANA name, e.g. Asia/Riyadh') }}">
                            <span class="ui-field__help">{{ ___('An IANA timezone identifier such as Asia/Riyadh or America/New_York. Pick the timezone your local opening hours are reported in.') }}</span>
                        </div>
                        <div><label class="ui-label" for="edit-closed-message">{{ ___('Closed message') }}</label><input class="ui-input" id="edit-closed-message" name="closed_message" x-model="edit.closed_message" placeholder="{{ ___('We are closed right now') }}"></div>
                        <label class="ui-check"><input type="checkbox" name="active" value="1" :checked="edit.active" @change="edit.active = $event.target.checked"><span>{{ ___('Active') }}</span></label>
                        <label class="ui-check"><input type="checkbox" name="accepting_orders" value="1" :checked="edit.accepting_orders" @change="edit.accepting_orders = $event.target.checked"><span>{{ ___('Accepting orders') }}</span></label>
                        {{-- D6: per-branch order-type overrides. "Inherit" = follow the store's flag. --}}
                        <div>
                            <div class="ui-label">{{ ___('Order types at this branch') }}</div>
                            <div class="ui-form-grid">
                                <div>
                                    <label class="ui-label ui-small" for="edit-ot-on-table">{{ ___('Dine-in') }}</label>
                                    <select class="ui-input ui-input--sm" id="edit-ot-on-table" name="order_types[on-table]" x-model="edit.ot_on_table">
                                        <option value="">{{ ___('Inherit') }}</option>
                                        <option value="1">{{ ___('On') }}</option>
                                        <option value="0">{{ ___('Off') }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="ui-label ui-small" for="edit-ot-takeaway">{{ ___('Takeaway') }}</label>
                                    <select class="ui-input ui-input--sm" id="edit-ot-takeaway" name="order_types[takeaway]" x-model="edit.ot_takeaway">
                                        <option value="">{{ ___('Inherit') }}</option>
                                        <option value="1">{{ ___('On') }}</option>
                                        <option value="0">{{ ___('Off') }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="ui-label ui-small" for="edit-ot-delivery">{{ ___('Delivery') }}</label>
                                    <select class="ui-input ui-input--sm" id="edit-ot-delivery" name="order_types[delivery]" x-model="edit.ot_delivery">
                                        <option value="">{{ ___('Inherit') }}</option>
                                        <option value="1">{{ ___('On') }}</option>
                                        <option value="0">{{ ___('Off') }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        {{-- P5-30: this branch is its own Google merchant — shown read-only. --}}
                        <div class="ui-field">
                            <span class="ui-field__label">{{ ___('Google merchant ID') }}</span>
                            <code class="ui-ltr" dir="ltr" x-text="edit.merchant_id ? edit.merchant_id : '{{ ___('Not published') }}'"></code>
                            <span class="ui-field__help" x-show="! edit.eligible">{{ ___('Not eligible: the branch must be active and have coordinates.') }}</span>
                        </div>
                        <div class="ui-field">
                            <span class="ui-field__label">{{ ___('Google eligibility') }}</span>
                            <p class="ui-field__help" x-show="edit.eligible">{{ ___('Eligible for Google') }}</p>
                            <p class="ui-field__help" x-show="! edit.eligible">{{ ___('Not eligible: the branch must be active and have coordinates.') }}</p>
                        </div>
                        {{-- P5-31: optional branch overrides; empty = use the store's value --}}
                        <div>
                            <div class="ui-label">{{ ___('Delivery settings at this branch') }}</div>
                            <p class="ui-small ui-muted">{{ ___('Leave empty to use the store setting.') }}</p>
                            <div class="ui-form-grid">
                                <div>
                                    <label class="ui-label ui-small" for="edit-prep-time">{{ ___('Prep time (minutes)') }}</label>
                                    <input class="ui-input ui-input--sm" id="edit-prep-time" type="number" min="0" max="1440" step="1" name="prep_time_minutes" x-model="edit.prep_time_minutes">
                                </div>
                                <div>
                                    <label class="ui-label ui-small" for="edit-delivery-fee">{{ ___('Delivery fee') }}</label>
                                    <input class="ui-input ui-input--sm" id="edit-delivery-fee" type="number" min="0" step="any" name="delivery_fee" x-model="edit.delivery_fee">
                                </div>
                                <div>
                                    <label class="ui-label ui-small" for="edit-min-order">{{ ___('Minimum order') }}</label>
                                    <input class="ui-input ui-input--sm" id="edit-min-order" type="number" min="0" step="any" name="min_order" x-model="edit.min_order">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="ui-cluster ui-cluster--end ui-card__foot">
                        <x-ui.button type="button" variant="ghost" @click="$refs.editDialog.close()">{{ ___('Cancel') }}</x-ui.button>
                        <x-ui.button type="submit" icon="check">{{ ___('Save') }}</x-ui.button>
                    </div>
                </form>
            </div>
        </dialog>
    @endif
</div>
@endsection

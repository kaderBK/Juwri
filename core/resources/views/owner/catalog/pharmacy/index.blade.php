{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    rev/multi-vertical Lane F1/F2 — OTC-ONLY (D3) pharmacy compliance list:
    age-gate flag + batch/expiry per item, a low-expiry alert, and the
    optional hide-when-expired store setting. A brand-new surface — never a
    fork of Lane A's retail item form.
--}}
@extends('owner.layout')

@section('title', ___('Compliance').' - '.$store->title)
@section('page_title', ___('OTC Compliance'))
@section('page_sub', $store->title)

@section('page_actions')
    @if(Route::has('retail.products.index'))
        <x-ui.button :href="route('retail.products.index', $store->id)" variant="secondary" icon="back">
            {{ ___('Back to :label', ['label' => $vocab['items'] ?? ___('Products')]) }}
        </x-ui.button>
    @endif
@endsection

@section('content')
<div class="ui-scope ui-pharm">
    <div class="ui-push__grid">
        <div class="ui-pharm__main">
            @if($isPharmacy)
                {{-- P1-23 (AUP 3.1): publish status, market and licence documents. --}}
                <x-ui.card :title="___('Licence and publishing')">
                    <x-ui.banner :tone="$publishReason === null ? 'success' : 'warning'">
                        {{ \Juwri\Modules\Pharmacy\Services\Pharmacy\PharmacyMarkets::reasonLabel($publishReason) }}
                    </x-ui.banner>
                    <dl class="ui-pharm__facts">
                        <dt>{{ ___('Your market') }}</dt>
                        <dd><span class="ui-ltr">{{ $marketCountry ?? '—' }}</span></dd>
                        <dt>{{ ___('Countries where pharmacy selling is enabled') }}</dt>
                        <dd>@if($enabledCountries === []){{ ___('None yet — we enable countries one by one after legal review.') }}@else<span class="ui-ltr">{{ implode(', ', $enabledCountries) }}</span>@endif</dd>
                        <dt>{{ ___('Most units of one medicine per order') }}</dt>
                        <dd>{{ $quantityCap }}@if($ownCap > 0 && $ownCap < $marketCap) ({{ ___('your limit; the market allows :max', ['max' => $marketCap]) }})@endif</dd>
                        <dt>{{ ___('Promotions on medicines') }}</dt>
                        <dd data-testid="pharmacy-promotions-rule">@if($medicinePromotionsBlocked ?? false){{ ___('Switched off in your market: medicines get no coupons, sale or happy-hour prices, combos or loyalty points. Products marked "not a medicine" are unaffected.') }}@else{{ ___('Allowed in your market.') }}@endif</dd>
                    </dl>

                    @if($licence)
                        <div class="ui-pharm__licence">
                            <strong>{{ ___('Latest licence') }}:</strong>
                            <span class="ui-ltr">{{ $licence->country }} · {{ $licence->licence_number }}</span> ·
                            {{ $licence->issuing_authority }} ·
                            {{ ___('expires :date', ['date' => $licence->expires_on->toDateString()]) }}
                            @if($licence->status === 'approved' && ! $licence->isExpired())
                                <x-ui.pill tone="success">{{ ___('Approved') }}</x-ui.pill>
                            @elseif($licence->status === 'approved')
                                <x-ui.pill tone="danger">{{ ___('Expired') }}</x-ui.pill>
                            @elseif($licence->status === 'rejected')
                                <x-ui.pill tone="danger">{{ ___('Not accepted') }}</x-ui.pill>
                            @else
                                <x-ui.pill tone="warning">{{ ___('Waiting for review') }}</x-ui.pill>
                            @endif
                            @if($licence->review_note)
                                <div class="ui-field__help">{{ $licence->review_note }}</div>
                            @endif
                        </div>
                    @endif

                    <form method="POST" action="{{ route('pharmacy.compliance.licence', $store->id) }}" enctype="multipart/form-data" class="ui-pharm__form">
                        @csrf
                        <div class="ui-pharm__pair">
                            <x-ui.field name="country" :label="___('Licence country (2-letter code, e.g. SA)')" :value="old('country', $licence?->country ?? $marketCountry)" maxlength="2" required />
                            <x-ui.field name="licence_number" :label="___('Licence number')" :value="old('licence_number')" required />
                        </div>
                        <div class="ui-pharm__pair">
                            <x-ui.field name="issuing_authority" :label="___('Issuing authority')" :value="old('issuing_authority')" required />
                            <x-ui.field name="expires_on" type="date" :label="___('Licence expiry date')" :value="old('expires_on')" required />
                        </div>
                        <div class="ui-pharm__pair">
                            <x-ui.field name="responsible_person_name" :label="___('Responsible pharmacist (where your country requires one)')" :value="old('responsible_person_name')" />
                            <x-ui.field name="responsible_person_registration" :label="___('Their registration number')" :value="old('responsible_person_registration')" />
                        </div>
                        <x-ui.field name="online_sales_permit" :label="___('Online-sales approval or permit (where your country issues one)')" :value="old('online_sales_permit')" />
                        <div>
                            <label class="ui-label" for="document">{{ ___('Scanned licence (PDF, JPG or PNG)') }}</label>
                            <input type="file" id="document" name="document" class="ui-input" accept=".pdf,.jpg,.jpeg,.png" required>
                            <div class="ui-field__help">{{ ___('Your pharmacy is published after we review these documents. Upload the renewed licence before this one expires: an expired licence unpublishes the store.') }}</div>
                        </div>
                        <div><x-ui.button type="submit" icon="check">{{ ___('Send for review') }}</x-ui.button></div>
                    </form>
                </x-ui.card>
            @endif

            @if($expiryOn && ($expiredItems->isNotEmpty() || $expiringSoonItems->isNotEmpty()))
                <x-ui.banner tone="warning">
                    @if($expiredItems->isNotEmpty())
                        <div>{{ ___(':count item(s) are past their expiry date.', ['count' => $expiredItems->count()]) }}</div>
                    @endif
                    @if($expiringSoonItems->isNotEmpty())
                        <div>{{ ___(':count item(s) expire within 30 days.', ['count' => $expiringSoonItems->count()]) }}</div>
                    @endif
                </x-ui.banner>
            @endif

            <x-ui.card :title="___('Items')" variant="flush">
                @if($items->isEmpty())
                    <x-ui.empty :title="___('No records found.')" icon="products" />
                @else
                    <table class="ui-table">
                        <thead>
                            <tr>
                                <th>{{ ___('Name') }}</th>
                                @if($ageGateOn)
                                    <th>{{ ___('Age-restricted') }}</th>
                                @endif
                                @if($expiryOn)
                                    <th>{{ ___('Batch') }}</th>
                                    <th>{{ ___('Expiry date') }}</th>
                                @endif
                                <th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($items as $item)
                                <tr>
                                    <td data-primary><strong>{{ $item->getRawOriginal('name') }}</strong></td>
                                    @if($ageGateOn)
                                        <td data-label="{{ ___('Age-restricted') }}">
                                            @if($item->age_restricted)
                                                <x-ui.pill tone="warning">{{ ___('Age-restricted') }}</x-ui.pill>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    @endif
                                    @if($expiryOn)
                                        <td data-label="{{ ___('Batch') }}"><span class="ui-ltr">{{ $item->batch_no ?: '—' }}</span></td>
                                        <td data-label="{{ ___('Expiry date') }}">
                                            @if($item->expiry_date)
                                                <span class="ui-pharm__expiry">
                                                    <span class="ui-ltr">{{ $item->expiry_date->toDateString() }}</span>
                                                    @if($item->is_expired)
                                                        <x-ui.pill tone="danger">{{ ___('Expired') }}</x-ui.pill>
                                                    @elseif($item->isExpiringWithin(30))
                                                        <x-ui.pill tone="warning">{{ ___('Expiring soon') }}</x-ui.pill>
                                                    @endif
                                                </span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    @endif
                                    <td class="ui-catalog__row-actions">
                                        <a href="{{ route('pharmacy.compliance.edit', [$store->id, $item->id]) }}" class="ui-icon-btn" aria-label="{{ ___('Edit') }}" title="{{ ___('Edit') }}"><x-ui.icon name="pencil" /></a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                @if($items->hasPages())
                    <div class="ui-catalog__pager">{{ $items->links() }}</div>
                @endif
            </x-ui.card>
        </div>

        <aside class="ui-pharm__side">
            @if($expiryOn || $ageGateOn)
                <x-ui.card :title="___('Compliance settings')">
                    <form method="POST" action="{{ route('pharmacy.compliance.settings', $store->id) }}" class="ui-pharm__form">
                        @csrf
                        @method('PUT')

                        @if($expiryOn)
                            <div class="ui-check">
                                <input type="checkbox" role="switch" name="hide_expired_items" id="hide_expired_items" value="1" @checked($hideExpiredEnabled)>
                                <label for="hide_expired_items">{{ ___('Automatically hide expired items from the storefront') }}</label>
                            </div>
                        @endif

                        @if($ageGateOn)
                            <div class="ui-check">
                                <input type="checkbox" role="switch" name="show_entry_gate" id="show_entry_gate" value="1" @checked($entryGateEnabled)>
                                <label for="show_entry_gate">{{ ___('Show the OTC notice as a pop-up when visitors arrive') }}</label>
                                <div class="ui-field__help">{{ ___('When off, the notice still appears as an always-visible banner on the storefront — only the blocking pop-up is removed.') }}</div>
                            </div>

                            <div>
                                <label class="ui-label" for="disclaimer_text">{{ ___('Storefront OTC disclaimer') }}</label>
                                <textarea id="disclaimer_text" name="disclaimer_text" class="ui-input" rows="4">{{ old('disclaimer_text', $disclaimerText) }}</textarea>
                                <div class="ui-field__help">{{ ___('Shown at the top of the storefront. Leave as-is to use the default wording.') }}</div>
                            </div>
                        @endif

                        @if($isPharmacy)
                            <div class="ui-check">
                                <input type="checkbox" role="switch" name="discreet_messages" id="discreet_messages" value="1" @checked($discreetMessagesEnabled)>
                                <label for="discreet_messages">{{ ___('Discreet customer messages') }}</label>
                                <div class="ui-field__help">{{ ___('Texts, WhatsApp messages and emails to customers say how many items there are, never the product names.') }}</div>
                            </div>
                        @endif

                        @if($isPharmacy)
                            <x-ui.field name="quantity_cap" type="number" min="0" :max="$marketCap" :label="___('Your limit per medicine and order (0 = the market limit)')" :value="old('quantity_cap', $ownCap)" />
                        @endif

                        <div><x-ui.button type="submit" icon="check">{{ ___('Save') }}</x-ui.button></div>
                    </form>
                </x-ui.card>
            @endif

            <x-ui.card :title="___('About OTC compliance')" variant="inset">
                <p class="ui-pharm__about">
                    {{ ___('This store is OTC (over-the-counter) only. This platform does not support prescriptions, pharmacist verification or controlled substances.') }}
                </p>
            </x-ui.card>
        </aside>
    </div>
</div>
@endsection

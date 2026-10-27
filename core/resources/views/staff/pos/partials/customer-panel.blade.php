{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- T2-2: customer search panel (pos-sheet). Opened from the cart header's
     "＋ Add customer". Search matches name/phone/email via the T2-1 endpoint;
     selecting a row attaches the customer (chip renders in the cart header). --}}
<div class="sheet" x-show="custOpen" x-cloak @keydown.escape.window="closeCustomers()">
    <div class="card2" @click.outside="closeCustomers()">
        <header>
            <h3>{{ ___('Attach customer') }}</h3>
            <span class="sp" style="flex:1"></span>
            <button class="closebtn" @click="closeCustomers()">&times;</button>
        </header>
        <div style="padding:12px 16px 0" x-show="!newOpen && !custDetail">
            <input class="pos-mini-input" style="width:100%" type="search" autocomplete="off"
                   x-model="custQuery" @input.debounce.250ms="onCustQuery()"
                   placeholder="{{ ___('Search name, phone or email…') }}">
            <button type="button" class="pos-cust-add" style="margin-top:8px" @click="openNew()">
                ＋ {{ ___('New customer') }}
            </button>
        </div>

        {{-- T2-3: inline create mini-form (name required, phone optional) --}}
        <div style="padding:12px 16px 0" x-show="newOpen" x-cloak>
            <input class="pos-mini-input" style="width:100%;margin-bottom:8px" type="text"
                   x-model="newCust.name" placeholder="{{ ___('Name') }}" @keydown.enter="createCustomer()">
            <input class="pos-mini-input" style="width:100%" type="tel" autocomplete="off"
                   x-model="newCust.phone" placeholder="{{ ___('Phone (optional)') }}" @keydown.enter="createCustomer()">
            <p class="pos-mini-err" x-show="newErr" x-text="newErr"></p>
            <div style="display:flex;gap:8px;margin-top:8px">
                <button type="button" class="pos-mini-clear" style="flex:0" @click="closeNew()">{{ ___('Cancel') }}</button>
                <span style="flex:1"></span>
                <button type="button" class="pos-mini-apply" :disabled="newSaving" @click="createCustomer()"
                        x-text="newSaving ? '…' : '{{ ___('Save & attach') }}'"></button>
            </div>
        </div>

        <div class="scroll" x-show="!newOpen && !custDetail">
            <p class="muted" x-show="custLoading">{{ ___('Searching…') }}</p>
            <template x-for="c in custResults" :key="c.id">
                <button type="button" class="pos-cust-row" @click="attachCustomer(c)">
                    <span class="pos-cust-avatar" x-text="custInitial(c)"></span>
                    <span class="pos-cust-meta">
                        <span class="pos-cust-name">
                            <span x-text="c.name || '{{ ___('Guest') }}'"></span>
                            <span class="pos-member" x-show="isMember(c)">{{ ___('MEMBER') }}</span>
                            <span class="pos-tier" x-show="c.tier" x-text="c.tier"></span>
                        </span>
                        <span class="pos-cust-sub" x-text="c.phone || c.email || ''"></span>
                    </span>
                    <span class="pos-cust-side">
                        <span x-show="c.wallet > 0" x-text="'{{ ___('Wallet') }} ' + money(c.wallet)"></span>
                        <span x-show="c.loyalty_points > 0" x-text="c.loyalty_points + ' {{ ___('pts') }}'"></span>
                        <span class="pos-cust-editbtn" role="button" tabindex="0" title="{{ ___('Edit') }}"
                              @click.stop="openCustDetail(c)" @keydown.enter.stop="openCustDetail(c)">✎</span>
                    </span>
                </button>
            </template>
            <div class="pos-empty" x-show="!custLoading && custLoaded && !custResults.length">
                <span class="ico">@include('staff.pos.partials.icon', ['name' => 'user', 'size' => 40])</span>
                <div>{{ ___('No customers found.') }}</div>
            </div>
        </div>

        {{-- Customer detail: edit contact + recent orders --}}
        <div class="pos-cust-detail" x-show="custDetail" x-cloak>
            <template x-if="custDetail">
                <div style="padding:12px 16px">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                        <span class="pos-cust-avatar" x-text="custInitial(custDetail)"></span>
                        <strong x-text="custDetail.name"></strong>
                        <span class="pos-member" x-show="isMember(custDetail)">{{ ___('MEMBER') }}</span>
                        <span style="flex:1"></span>
                        <button type="button" class="pos-mini-clear" @click="closeCustDetail()">{{ ___('Back') }}</button>
                    </div>
                    <input class="pos-mini-input" style="width:100%;margin-bottom:6px" type="text"
                           x-model="custEdit.name" placeholder="{{ ___('Name') }}">
                    <input class="pos-mini-input" style="width:100%;margin-bottom:6px" type="tel" autocomplete="off"
                           x-model="custEdit.phone" placeholder="{{ ___('Phone') }}">
                    <input class="pos-mini-input" style="width:100%" type="email" autocomplete="off"
                           x-model="custEdit.email" placeholder="{{ ___('Email') }}">
                    <p class="pos-mini-err" x-show="editErr" x-text="editErr"></p>
                    <div style="display:flex;gap:8px;margin:8px 0 12px">
                        <span style="flex:1"></span>
                        <button type="button" class="pos-mini-apply" :disabled="editSaving" @click="saveCustomer()"
                                x-text="editSaving ? '…' : '{{ ___('Save') }}'"></button>
                    </div>

                    <h4 style="margin:0 0 6px;font-size:13px">{{ ___('Recent orders') }}</h4>
                    <p class="muted" x-show="custHistLoading">{{ ___('Loading…') }}</p>
                    <template x-for="o in custHistory" :key="o.id">
                        <div class="pos-cust-hist-row">
                            <span>#<span x-text="o.id"></span> · <span x-text="o.type"></span>
                                <span class="pos-age-chip" x-show="o.time" x-text="ageChip(o.time)"></span></span>
                            <span><span x-text="money(o.total)"></span>
                                <span class="pos-order-status" x-text="o.is_paid ? '{{ ___('Paid') }}' : o.status"></span></span>
                        </div>
                    </template>
                    <p class="muted" x-show="!custHistLoading && !custHistory.length">{{ ___('No orders yet.') }}</p>
                </div>
            </template>
        </div>
    </div>
</div>

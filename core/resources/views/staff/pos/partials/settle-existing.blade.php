{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Compact settle modal for an EXISTING server-side order (external/online order,
     or a held order's detail). POSTs straight to pos.orders.settle — no cart. --}}
<div class="pos-settle" x-show="extSettle.open" x-cloak @keydown.escape.window="closeExtSettle()">
    <div class="pos-settle-card pos-ext-settle">
        <div class="pos-settle-act">
            <button type="button" class="pos-settle-close" @click="closeExtSettle()">&times;</button>
            <div class="pos-settle-payable">
                <span class="lbl">{{ ___('Settle order') }} <span x-text="extSettle.label"></span></span>
                <span class="amt" x-text="money(extTotal())"></span>
            </div>

            {{-- settle-time coupon (applies to THIS existing order) --}}
            <div class="pos-mini-row" style="margin-bottom:10px">
                <input type="text" class="pos-mini-input" x-model="extSettle.coupon" autocomplete="off"
                       placeholder="{{ ___('Coupon code') }}" @keydown.enter.prevent="applyExtCoupon()">
                <button type="button" class="pos-mini-apply" :disabled="extSettle.couponBusy || !extSettle.coupon" @click="applyExtCoupon()"
                        x-text="extSettle.couponBusy ? '…' : '{{ ___('Apply') }}'"></button>
            </div>
            <p class="pos-mini-err" x-show="extSettle.couponErr" x-cloak x-text="extSettle.couponErr"></p>

            {{-- Coupons C6: the coupon covers everything — no tender to take. The server re-checks the amount due. --}}
            <div class="pos-settle-free" x-show="extSettle.couponApplied && extTotal() <= 0.001" x-cloak>
                <p class="pos-settle-hint">{{ ___('Free with coupon — no payment needed') }}</p>
                <button type="button" class="pos-settle-confirm" :disabled="extSettle.busy" @click="setExtMethod('coupon'); confirmExtSettle()">{{ ___('Confirm free order') }}</button>
            </div>

            {{-- payment method --}}
            <div class="pos-tabs">
                <button type="button" :class="{on: extSettle.method === 'cash'}" @click="setExtMethod('cash')">{{ ___('Cash') }}</button>
                <button type="button" :class="{on: extSettle.method === 'card'}" @click="setExtMethod('card')">{{ ___('Card') }}</button>
                <button type="button" :class="{on: extSettle.method === 'online'}" @click="setExtMethod('online')">{{ ___('Online') }}</button>
                <button type="button" :class="{on: extSettle.method === 'other'}" @click="setExtMethod('other')">{{ ___('Other') }}</button>
            </div>

            {{-- cash: keypad + change --}}
            <div class="pos-settle-body" x-show="extSettle.method === 'cash'">
                <div class="pos-tender-display">
                    <span class="lbl">{{ ___('Cash tendered') }}</span>
                    <span class="amt" x-text="money(extTendered())"></span>
                </div>
                <div class="pos-settle-change" :class="{'is-due': !extCovers()}">
                    <template x-if="extCovers()"><span>{{ ___('Change') }}: <strong x-text="money(extChange())"></strong></span></template>
                    <template x-if="!extCovers()"><span>{{ ___('Balance due') }}: <strong x-text="money(extBalance())"></strong></span></template>
                </div>
                <div class="pos-presets">
                    <button type="button" @click="extPreset('exact')">{{ ___('Exact') }}</button>
                    <button type="button" @click="extPreset(50)">50</button>
                    <button type="button" @click="extPreset(100)">100</button>
                    <button type="button" @click="extPreset(200)">200</button>
                </div>
                <div class="pos-keypad">
                    <template x-for="k in ['1','2','3','4','5','6','7','8','9','00','0','.']" :key="k">
                        <button type="button" @click="extKeypad(k)" x-text="k"></button>
                    </template>
                    <button type="button" class="wide" @click="extKeypad('del')" aria-label="{{ ___('Backspace') }}">@include('staff.pos.partials.icon', ['name' => 'backspace', 'size' => 22])</button>
                </div>
            </div>

            <button type="button" class="pos-settle-confirm" :disabled="!extCovers() || extSettle.busy" @click="confirmExtSettle()">
                <span x-show="extSettle.method === 'cash'">{{ ___('Confirm cash') }}</span>
                <span x-show="extSettle.method !== 'cash'" x-text="'{{ ___('Mark paid') }}'"></span>
            </button>
        </div>
    </div>
</div>

{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- T5-1: settle screen v2 — split sheet. LEFT read region (lines/customer/totals),
     RIGHT actives (payable + Cash | Other tabs). Keypad (T5-2), tip (T5-3), coupon
     (T5-4), wallet (T5-5), split (T5-6) fill the tab bodies later. --}}
<div class="pos-settle" x-show="settleOpen" x-cloak @keydown.escape.window="closeSettle()">
    <div class="pos-settle-card">
        {{-- LEFT: read region (inline-start → RTL mirrors for free) --}}
        <div class="pos-settle-read">
            <h3>{{ ___('Order') }}</h3>
            <div class="pos-settle-lines">
                <template x-for="(l, i) in cart" :key="i">
                    <div class="pos-settle-line">
                        <span x-text="l.qty + '× ' + l.name"></span>
                        <span x-text="money(l.unitPrice * l.qty)"></span>
                    </div>
                </template>
            </div>
            <div class="pos-settle-cust" x-show="customer">
                <span>@include('staff.pos.partials.icon', ['name' => 'user', 'size' => 16])</span> <span x-text="customer ? customer.name : ''"></span>
            </div>
            <dl class="pos-settle-totals">
                <div><dt>{{ ___('Subtotal') }}</dt><dd x-text="money(subtotal())"></dd></div>
                <div x-show="discountAmount() > 0"><dt>{{ ___('Discount') }}</dt><dd x-text="'−' + money(discountAmount())"></dd></div>
                <div x-show="couponValue() > 0"><dt>{{ ___('Coupon') }}</dt><dd x-text="'−' + money(couponValue())"></dd></div>
                <div x-show="taxPreview() > 0"><dt>{{ ___('Tax') }}</dt><dd x-text="money(taxPreview())"></dd></div>
                <div x-show="deliveryPreview() > 0"><dt>{{ ___('Delivery') }}</dt><dd x-text="money(deliveryPreview())"></dd></div>
                <div x-show="walletApplied() > 0"><dt>{{ ___('Wallet') }}</dt><dd x-text="'−' + money(walletApplied())"></dd></div>
            </dl>

            {{-- T5-5: store-credit redemption (only when a customer with a balance
                 is attached; server gates on customer_id + recomputes). --}}
            <div class="pos-settle-wallet" x-show="customer && walletAvailable() > 0">
                <label class="pos-wallet-toggle">
                    <input type="checkbox" :checked="useWallet" @change="toggleWallet()">
                    <span x-text="'{{ ___('Use wallet') }} (' + '{{ ___('available') }} ' + money(walletAvailable()) + ')'"></span>
                </label>
                <input type="number" step="0.01" min="0" class="pos-mini-input" x-show="useWallet" x-cloak
                       x-model.number="walletAmt" placeholder="{{ ___('Partial amount (optional)') }}">
            </div>

            {{-- T5-4: coupon — applies at CREATE, so it works for the current cart
                 (the order is placed on Confirm). Honest hint below. --}}
            <div class="pos-settle-coupon">
                <template x-if="!coupon.applied">
                    <div class="pos-mini-row">
                        <input type="text" class="pos-mini-input" x-model="coupon.code" autocomplete="off"
                               placeholder="{{ ___('Coupon code') }}" @keydown.enter="checkCoupon()">
                        <button type="button" class="pos-mini-apply" :disabled="coupon.checking" @click="checkCoupon()"
                                x-text="coupon.checking ? '…' : '{{ ___('Apply') }}'"></button>
                    </div>
                </template>
                <template x-if="coupon.applied">
                    <div class="pos-settle-coupon-on">
                        <span x-text="'{{ ___('Coupon') }} ' + coupon.applied.code + ' −' + money(couponValue())"></span>
                        <button type="button" class="pos-mini-clear" @click="clearCoupon()">{{ ___('Remove coupon') }}</button>
                    </div>
                </template>
                <p class="pos-mini-err" x-show="coupon.error" x-text="coupon.error"></p>
                <p class="pos-settle-hint">{{ ___('Coupons apply when the order is sent.') }}</p>
            </div>
        </div>

        {{-- RIGHT: actives --}}
        <div class="pos-settle-act">
            <button type="button" class="pos-settle-close" @click="closeSettle()">&times;</button>
            <div class="pos-settle-payable">
                <span class="lbl" x-text="tipAmount > 0 ? '{{ ___('To collect') }}' : '{{ ___('Payable') }}'"></span>
                <span class="amt" x-text="money(settleTotal())"></span>
            </div>

            {{-- T5-3: tip row (quick % chips or custom amount) --}}
            <div class="pos-tip">
                <span class="pos-tip-lbl">{{ ___('Tip') }}</span>
                <div class="pos-tip-chips">
                    <button type="button" :class="{on: tipPct === null && tipAmount === 0}" @click="clearTip()">{{ ___('None') }}</button>
                    <button type="button" :class="{on: tipPct === 10}" @click="setTipPct(10)">10%</button>
                    <button type="button" :class="{on: tipPct === 15}" @click="setTipPct(15)">15%</button>
                    <button type="button" :class="{on: tipPct === 20}" @click="setTipPct(20)">20%</button>
                    <input type="number" step="0.01" min="0" class="pos-tip-custom" placeholder="{{ ___('Custom') }}"
                           :value="tipPct === null && tipAmount > 0 ? tipAmount : ''" @input="setTipAmount($event.target.value)">
                </div>
                <span class="pos-tip-amt" x-show="tipAmount > 0" x-text="'+' + money(tipAmount)"></span>
            </div>

            <div class="pos-tabs">
                <button type="button" :class="{on: settleTab === 'cash'}" @click="setSettleTab('cash')">{{ ___('Cash') }}</button>
                <button type="button" :class="{on: settleTab === 'other'}" @click="setSettleTab('other')">{{ ___('Other modes') }}</button>
                <button type="button" :class="{on: settleTab === 'split'}" @click="setSettleTab('split')">{{ ___('Split') }}</button>
            </div>

            {{-- T5-2: cash tab — keypad + quick presets + change/balance --}}
            <div class="pos-settle-body" x-show="settleTab === 'cash'">
                <div class="pos-tender-display">
                    <span class="lbl">{{ ___('Cash tendered') }}</span>
                    <span class="amt" x-text="money(tendered)"></span>
                </div>
                <div class="pos-settle-change" :class="{'is-due': !cashCovers()}">
                    <template x-if="cashCovers()"><span>{{ ___('Change') }}: <strong x-text="money(changeDue())"></strong></span></template>
                    <template x-if="!cashCovers()"><span>{{ ___('Balance due') }}: <strong x-text="money(balanceDue())"></strong></span></template>
                </div>
                <div class="pos-presets">
                    <button type="button" @click="presetTender('exact')">{{ ___('Exact') }}</button>
                    <button type="button" @click="presetTender(50)">50</button>
                    <button type="button" @click="presetTender(100)">100</button>
                    <button type="button" @click="presetTender(200)">200</button>
                </div>
                <div class="pos-keypad">
                    <template x-for="k in ['1','2','3','4','5','6','7','8','9','00','0','.']" :key="k">
                        <button type="button" @click="keypad(k)" x-text="k"></button>
                    </template>
                    <button type="button" class="wide" @click="keypad('del')" aria-label="{{ ___('Backspace') }}">@include('staff.pos.partials.icon', ['name' => 'backspace', 'size' => 22])</button>
                </div>
                <button type="button" class="pos-settle-confirm" :disabled="!cashCovers() || sending" @click="confirmSettle('cash')">
                    {{ ___('Confirm cash') }}
                </button>
            </div>

            {{-- T5-2: Other modes tab — method + optional reference --}}
            <div class="pos-settle-body" x-show="settleTab === 'other'">
                <label class="pos-settle-field">
                    <span>{{ ___('Reference (optional)') }}</span>
                    <input type="text" x-model="settleRef" autocomplete="off" placeholder="{{ ___('Auth code / ref') }}">
                </label>
                <button type="button" class="pos-settle-mode" :disabled="sending" @click="confirmSettle('card')">{{ ___('Card') }}</button>
                <button type="button" class="pos-settle-mode" :disabled="sending" @click="confirmSettle('online')">{{ ___('Online') }}</button>
                <button type="button" class="pos-settle-mode" :disabled="sending" @click="confirmSettle('other')">{{ ___('Other') }}</button>
                {{-- Hotel: settle a room-service tab onto the guest's folio (collected
                     at check-out) instead of taking payment now. Shown only when the
                     tab is on a guest room with a checked-in stay. --}}
                <button type="button" class="pos-settle-mode pos-settle-room" x-show="canChargeToRoom()" x-cloak
                        :disabled="sending" @click="confirmSettle('charge_to_room')">
                    {{ ___('Charge to room') }}<template x-if="chargeToRoomGuest()"><span x-text="' · ' + chargeToRoomGuest()"></span></template>
                </button>
                {{-- J52b SF note 1: the room may come from an anonymous room-service order, so staff confirm the guest. --}}
                <p class="pos-settle-hint muted" x-show="canChargeToRoom()" x-cloak>{{ ___('Before charging, confirm the guest name and room with the guest. The order may come from an anonymous room service request.') }}</p>
            </div>

            {{-- T5-6: split payment (N-way even split, method per part) --}}
            <div class="pos-settle-body" x-show="settleTab === 'split'">
                <template x-if="!splitShares.length">
                    <div class="pos-split-start">
                        <label class="pos-settle-field">
                            <span>{{ ___('Split into') }}</span>
                            <select x-model.number="splitParts">
                                <template x-for="n in [2,3,4,5,6]" :key="n"><option :value="n" x-text="n + ' {{ ___('ways') }}'"></option></template>
                            </select>
                        </label>
                        <button type="button" class="pos-settle-confirm" :disabled="splitBusy" @click="startSplit()">{{ ___('Start split') }}</button>
                    </div>
                </template>
                <template x-if="splitShares.length">
                    <div>
                        <p class="muted" x-text="splitPaidCount() + ' / ' + splitShares.length + ' {{ ___('paid') }}'"></p>
                        <template x-for="s in splitShares" :key="s.id">
                            <div class="pos-split-share" :class="{'is-paid': s.status === 'paid'}">
                                <span class="no" x-text="'#' + s.share_no"></span>
                                <span class="amt" x-text="money(s.amount)"></span>
                                <template x-if="s.status !== 'paid'">
                                    <span class="pay">
                                        <select x-model="s.method">
                                            <option value="cash">{{ ___('Cash') }}</option>
                                            <option value="card">{{ ___('Card') }}</option>
                                            <option value="online">{{ ___('Online') }}</option>
                                        </select>
                                        <button type="button" :disabled="splitBusy" @click="paySplitShare(s)">{{ ___('Pay') }}</button>
                                    </span>
                                </template>
                                <span class="paid" x-show="s.status === 'paid'" x-text="'✓ ' + (s.method || '')"></span>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>

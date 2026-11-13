{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- POS-V2 cart column (P0-2 extract; T1 rebuilds lines/totals, T5 the actions).
     On phones the cart is an off-canvas drawer toggled by the FAB (mCart). --}}
<div class="cart" :class="{'m-open': mCart}">
    <h2>
        <span x-text="'{{ ___('Cart') }} (' + cart.length + ')'"></span>
        <button type="button" class="cart-mclose" @click="mCart = false" title="{{ ___('Close') }}">&times;</button>
    </h2>

    {{-- Add-products mode banner (occupied table → Add products) --}}
    <div class="pos-addmode-bar" x-show="addToOrderId" x-cloak>
        <span x-text="'{{ ___('Adding to order') }} #' + addToOrderId"></span>
        <button type="button" @click="cancelAddToOrder()">{{ ___('Cancel') }}</button>
    </div>
    {{-- Edit-order mode banner --}}
    <div class="pos-addmode-bar" x-show="editOrderId" x-cloak style="background:var(--pos-warn)">
        <span x-text="'{{ ___('Editing order') }} #' + editOrderId"></span>
        <button type="button" @click="cancelEditOrder()">{{ ___('Cancel') }}</button>
    </div>

    {{-- T3-3: assigned dine-in table chip (tap = change, ✕ = release) --}}
    <div class="pos-cust-bar" x-show="table" x-cloak>
        <span class="pos-cust-chip">
            <span>@include('staff.pos.partials.icon', ['name' => 'table', 'size' => 16])</span>
            <button type="button" class="pos-cust-chip-name" style="border:0;background:none;color:inherit;cursor:pointer"
                    @click="changeTable()" x-text="table ? table.name : ''"></button>
            <button type="button" class="pos-cust-detach" @click="releaseTable()" title="{{ ___('Release table') }}">&times;</button>
        </span>
    </div>

    {{-- Gap 2: guests / covers picker for the dine-in order --}}
    <div class="pos-covers" x-show="table" x-cloak>
        <span class="pos-covers-lbl">{{ ___('Guests') }}</span>
        <div class="pos-covers-chips">
            <template x-for="n in [1,2,3,4,5,6]" :key="n">
                <button type="button" :class="{on: covers === n}" @click="setCovers(n)" x-text="n"></button>
            </template>
            <input type="number" min="1" max="99" class="pos-covers-custom" placeholder="+"
                   :value="covers > 6 ? covers : ''" @input="covers = ($event.target.value ? Math.max(1, Math.min(99, +$event.target.value)) : null)">
        </div>
    </div>

    {{-- T2-2: attached-customer chip / add-customer trigger --}}
    <div class="pos-cust-bar">
        <template x-if="!customer">
            <button type="button" class="pos-cust-add" @click="openCustomers()">＋ {{ ___('Add customer') }}</button>
        </template>
        <template x-if="customer">
            <div>
                <span class="pos-cust-chip">
                    <span class="pos-cust-avatar sm" x-text="custInitial(customer)"></span>
                    <span class="pos-cust-chip-name" x-text="customer.name || '{{ ___('Guest') }}'"></span>
                    <span class="pos-member" x-show="isMember(customer)">{{ ___('MEMBER') }}</span>
                    <span class="pos-tier" x-show="customer.tier" x-text="customer.tier"></span>
                    <button type="button" class="pos-cust-detach" @click="detachCustomer()" title="{{ ___('Remove') }}">&times;</button>
                </span>
                {{-- T2-4: loyalty display — points balance + earn preview (read-only) --}}
                <div class="pos-loyalty" x-show="loyaltyActive() || customer.loyalty_points > 0">
                    <span class="pos-loyalty-bal">
                        <span x-text="customer.loyalty_points || 0"></span> {{ ___('pts') }}
                    </span>
                    <span class="pos-loyalty-earn" x-show="loyaltyActive() && loyaltyEarnPreview() > 0"
                          x-text="'{{ ___('earns') }} +' + loyaltyEarnPreview() + ' {{ ___('pts') }}'"></span>
                </div>
            </div>
        </template>
    </div>
    <div class="lines">
        {{-- T1-2: pos-line — tap the name area to reopen the customize sheet pre-filled
             (editLine); ✕ removes; stepper adjusts qty; sublines for variant/extras,
             amber note, unit price + line total. --}}
        <template x-for="(l, i) in cart" :key="i">
            <div class="pos-line">
                <button type="button" class="pos-line-main" @click="editLine(i)" :title="'{{ ___('Edit') }}'">
                    <span class="pos-line-name" x-text="l.name"></span>
                    <span class="pos-line-opts" x-show="l.variationLabel" x-text="l.variationLabel"></span>
                    {{-- T6-3: choice-group selections --}}
                    <template x-for="g in (l.options || [])" :key="g.group_id">
                        <span class="pos-line-opts" x-text="g.title + ': ' + g.selected.map(s => s.option).join(', ')"></span>
                    </template>
                    <span class="pos-line-opts" x-show="l.extras.length" x-text="l.extras.map(e => e.title).join(', ')"></span>
                    <span class="pos-line-note" x-show="l.note" x-text="'“' + l.note + '”'"></span>
                    <span class="pos-line-unit" x-text="money(l.unitPrice) + ' {{ ___('each') }}'"></span>
                </button>
                <div class="pos-line-side">
                    <button type="button" class="pos-line-x" @click="removeLine(i)" :title="'{{ ___('Remove') }}'">&times;</button>
                    <div class="pos-step">
                        <button type="button" @click="dec(i)">&minus;</button>
                        <span x-text="l.qty"></span>
                        <button type="button" @click="l.qty++">+</button>
                    </div>
                    <div class="pos-line-total" x-text="money(l.unitPrice * l.qty)"></div>
                </div>
            </div>
        </template>
        <div class="pos-empty" x-show="!cart.length">
            <span class="ico">@include('staff.pos.partials.icon', ['name' => 'cart', 'size' => 40])</span>
            <div>{{ ___('Tap items to add them.') }}</div>
        </div>
    </div>
    {{-- T1-3: per-order add-row — Discount + Coupon. Order note is per-line only
         (the POS store path has no order-level note field; see BUILD-LOG). --}}
    <div class="pos-addrow" x-show="cart.length">
        <button type="button" class="pos-addlink" @click="openAdd('discount')">
            <span x-show="discountAmount() <= 0">＋ {{ ___('Discount') }}</span>
            <span x-show="discountAmount() > 0" x-text="'{{ ___('Discount') }}: −' + money(discountAmount())"></span>
        </button>
        <button type="button" class="pos-addlink" @click="openAdd('coupon')">
            <span x-show="!coupon.applied">＋ {{ ___('Coupon') }}</span>
            <span x-show="coupon.applied" x-text="'{{ ___('Coupon') }}: ' + (coupon.applied ? coupon.applied.code : '')"></span>
        </button>
        {{-- Order-level note (seam closed: store() now accepts note → orders.message) --}}
        <button type="button" class="pos-addlink" @click="openAdd('note')">
            <span x-show="!orderNote">＋ {{ ___('Note') }}</span>
            <span x-show="orderNote" x-text="'{{ ___('Note') }}: ' + orderNote"></span>
        </button>
    </div>

    {{-- Order-note mini-sheet --}}
    <div class="pos-mini" x-show="addSheet === 'note'" x-cloak @keydown.escape.window="closeAdd()">
        <input type="text" class="pos-mini-input" style="width:100%" x-model="orderNote" maxlength="500" autocomplete="off"
               placeholder="{{ ___('Order note (kitchen sees it)') }}" @keydown.enter="closeAdd()">
        <div style="display:flex;gap:8px;margin-top:8px">
            <button type="button" class="pos-mini-clear" style="flex:0" x-show="orderNote" @click="orderNote = ''; closeAdd()">{{ ___('Clear') }}</button>
            <span style="flex:1"></span>
            <button type="button" class="pos-mini-apply" @click="closeAdd()">{{ ___('Done') }}</button>
        </div>
    </div>

    {{-- Discount mini-sheet --}}
    <div class="pos-mini" x-show="addSheet === 'discount'" x-cloak @keydown.escape.window="closeAdd()">
        <div class="pos-mini-row">
            <select x-model="discount.mode" class="pos-mini-select">
                <option value="amount">{{ ___('Amount') }}</option>
                <option value="percent">%</option>
            </select>
            <input type="number" min="0" step="0.01" x-model.number="discount.value" class="pos-mini-input"
                   placeholder="0" @keydown.enter="closeAdd()">
            <button type="button" class="pos-mini-apply" @click="closeAdd()">{{ ___('Apply') }}</button>
        </div>
        <button type="button" class="pos-mini-clear" x-show="discountAmount() > 0" @click="clearDiscount()">{{ ___('Clear discount') }}</button>
    </div>

    {{-- T4-2: Hold-cart mini-sheet (optional note) --}}
    <div class="pos-mini" x-show="addSheet === 'hold'" x-cloak @keydown.escape.window="closeAdd()">
        <input type="text" class="pos-mini-input" style="width:100%" x-model="holdNote" autocomplete="off"
               placeholder="{{ ___('Hold note (optional)') }}" @keydown.enter="confirmHold()">
        <div style="display:flex;gap:8px;margin-top:8px">
            <button type="button" class="pos-mini-clear" style="flex:0" @click="closeAdd()">{{ ___('Cancel') }}</button>
            <span style="flex:1"></span>
            <button type="button" class="pos-mini-apply" @click="confirmHold()">{{ ___('Hold cart') }}</button>
        </div>
    </div>

    {{-- Coupon mini-sheet --}}
    <div class="pos-mini" x-show="addSheet === 'coupon'" x-cloak @keydown.escape.window="closeAdd()">
        <div class="pos-mini-row">
            <input type="text" x-model="coupon.code" class="pos-mini-input" autocomplete="off"
                   placeholder="{{ ___('Coupon code') }}" @keydown.enter="checkCoupon()">
            <button type="button" class="pos-mini-apply" :disabled="coupon.checking" @click="checkCoupon()"
                    x-text="coupon.checking ? '…' : '{{ ___('Apply') }}'"></button>
        </div>
        <p class="pos-mini-err" x-show="coupon.error" x-text="coupon.error"></p>
        <button type="button" class="pos-mini-clear" x-show="coupon.applied" @click="clearCoupon()">{{ ___('Remove coupon') }}</button>
    </div>

    <div class="type">
        <button :class="{on: type==='takeaway'}" @click="type='takeaway'">{{ ___('Takeaway') }}</button>
        <button :class="{on: type==='on-table'}" @click="type='on-table'">{{ ___('Dine-in') }}</button>
        <button :class="{on: type==='delivery'}" @click="setOrderType('delivery')">{{ ___('Delivery') }}</button>
    </div>
    {{-- T1-4: totals v2 — subtotal, −discount, −coupon, −wallet (T5-5), tax + delivery
         preview (client mirror of Order::computeTax), Payable. Server authoritative. --}}
    <div class="totals">
        <div class="row"><span>{{ ___('Subtotal') }}</span><span x-text="money(subtotal())"></span></div>
        <div class="row is-cut" x-show="discountAmount() > 0">
            <span>{{ ___('Discount') }}</span><span x-text="'−' + money(discountAmount())"></span>
        </div>
        <div class="row is-cut" x-show="couponValue() > 0">
            <span x-text="'{{ ___('Coupon') }} ' + (coupon.applied ? coupon.applied.code : '')"></span>
            <span x-text="'−' + money(couponValue())"></span>
        </div>
        <div class="row is-cut" x-show="walletApplied() > 0">
            <span>{{ ___('Wallet') }}</span><span x-text="'−' + money(walletApplied())"></span>
        </div>
        <div class="row" x-show="taxPreview() > 0">
            <span>{{ ___('Tax') }}</span><span x-text="money(taxPreview())"></span>
        </div>
        <div class="row" x-show="deliveryPreview() > 0">
            <span>{{ ___('Delivery') }}</span><span x-text="money(deliveryPreview())"></span>
        </div>
        <div class="row grand"><span>{{ ___('Payable') }}</span><span x-text="money(payable())"></span></div>
        <p class="muted">{{ ___('Tax & charges are finalised on the receipt.') }}</p>
    </div>
    {{-- Add-to-existing-order mode: the cart's new lines append to an open order --}}
    <div class="acts" x-show="addToOrderId" x-cloak>
        <button class="send" style="grid-column:1/-1" @click="submitAddItems()" :disabled="!cart.length || sending"
                x-text="'{{ ___('Add to order') }} #' + addToOrderId"></button>
        <button class="hold" style="grid-column:1/-1" @click="cancelAddToOrder()">{{ ___('Cancel') }}</button>
    </div>
    {{-- Edit-order mode: the cart replaces the existing order's lines --}}
    <div class="acts" x-show="editOrderId" x-cloak>
        <button class="send" style="grid-column:1/-1" @click="submitEditItems()" :disabled="!cart.length || sending"
                x-text="'{{ ___('Save order') }} #' + editOrderId"></button>
        <button class="hold" style="grid-column:1/-1" @click="cancelEditOrder()">{{ ___('Cancel') }}</button>
    </div>
    <div class="acts" x-show="!addToOrderId && !editOrderId">
        <button class="send" @click="submit(false)" :disabled="!cart.length || sending">{{ ___('Send to kitchen') }}</button>
        <button class="cash" @click="openSettle('cash')" :disabled="!cart.length || sending">{{ ___('Cash') }}</button>
        <button class="card" @click="openSettle('card')" :disabled="!cart.length || sending">{{ ___('Card') }}</button>
        <button class="hold" @click="openHold()" :disabled="!cart.length || sending">{{ ___('Hold') }}</button>
    </div>

    {{-- Print the just-created order (shown after a Send, once the cart is cleared) --}}
    <div class="acts pos-print-row" x-show="lastOrderId && !cart.length && !addToOrderId" x-cloak>
        <button class="print" @click="printOrder()">🖨 {{ ___('Print receipt') }}</button>
        <button class="print" @click="printOrder('kitchen')">🖨 {{ ___('Print ticket') }}</button>
    </div>
</div>

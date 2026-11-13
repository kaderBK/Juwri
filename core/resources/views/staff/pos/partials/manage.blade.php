{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Manage panel: Shift (open/close + Z-report) and Reports (pos/sales).
     Rendered only for the owner / pos_shift_close staff (rail gate). --}}
<div class="pos-panel" x-show="activePanel === 'manage'" x-cloak>
    <div class="pos-panel-head">
        <h2>{{ ___('Manage') }}</h2>
        <div class="pos-tabs">
            <button type="button" :class="{on: manageTab === 'shift'}" @click="setManageTab('shift')">{{ ___('Shift') }}</button>
            <button type="button" :class="{on: manageTab === 'reports'}" @click="setManageTab('reports')">{{ ___('Reports') }}</button>
        </div>
        <button type="button" class="pos-panel-close" @click="activePanel = 'home'" title="{{ ___('Close') }}">&times;</button>
    </div>

    <div class="pos-panel-body" style="padding:16px;max-width:760px">
        {{-- ── Shift tab ── --}}
        <div x-show="manageTab === 'shift'">
            <p class="muted" x-show="shiftLoading">{{ ___('Loading…') }}</p>

            {{-- no open shift → open one --}}
            <div x-show="!shiftLoading && !shift" x-cloak>
                <h3 style="margin:0 0 8px">{{ ___('No open shift') }}</h3>
                <label class="pos-settle-field" style="max-width:260px">
                    <span>{{ ___('Opening float (drawer cash)') }}</span>
                    <input type="number" min="0" step="0.01" x-model="floatStr" placeholder="0.00">
                </label>
                <button type="button" class="pos-mini-apply" style="margin-top:8px" :disabled="shiftBusy" @click="openShiftNow()">
                    {{ ___('Open shift') }}
                </button>
            </div>

            {{-- open shift → status + close --}}
            <div x-show="!shiftLoading && shift" x-cloak>
                <h3 style="margin:0 0 4px">{{ ___('Shift open') }}
                    <span class="pos-age-chip" x-show="shift && shift.opened_at" x-text="shift ? ageChip(shift.opened_at) : ''"></span>
                </h3>
                <p class="muted" x-text="shift ? '{{ ___('Opening float') }}: ' + money(shift.opening_float) : ''"></p>
                <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
                    <label class="pos-settle-field" style="max-width:220px">
                        <span>{{ ___('Counted cash (end of shift)') }}</span>
                        <input type="number" min="0" step="0.01" x-model="countedStr" placeholder="0.00">
                    </label>
                    <button type="button" class="pos-mini-apply" :disabled="shiftBusy" @click="closeShiftNow()">{{ ___('Close shift') }}</button>
                    <button type="button" class="pos-mini-clear" @click="loadZReport()">{{ ___('X report (so far)') }}</button>
                </div>

                {{-- D2: drawer handoff — close this shift with the counted cash and open a
                     fresh one for the incoming cashier with that amount as the float. --}}
                <div x-show="canDrawer && (staffList || []).length" x-cloak style="margin-top:12px;padding-top:10px;border-top:1px solid var(--pos-line, #333)">
                    <h4 style="margin:0 0 6px">{{ ___('Hand over drawer') }}</h4>
                    <p class="muted" style="margin:0 0 6px">{{ ___('Uses the counted cash above as the next float.') }}</p>
                    <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
                        <label class="pos-settle-field" style="max-width:220px">
                            <span>{{ ___('Hand to') }}</span>
                            <select x-model.number="handoffTo">
                                <option value="0">{{ ___('Select cashier…') }}</option>
                                <template x-for="s in (staffList || [])" :key="s.id">
                                    <option :value="s.id" x-text="s.name"></option>
                                </template>
                            </select>
                        </label>
                        <button type="button" class="pos-mini-apply" :disabled="shiftBusy" @click="handoffDrawer()">{{ ___('Hand over') }}</button>
                    </div>
                </div>
            </div>

            {{-- Z-report card (after close, or X-report on demand) --}}
            <div class="pos-zreport" x-show="zreport" x-cloak>
                <template x-if="zreport">
                    <div>
                        <h3 style="margin:12px 0 8px">{{ ___('Report') }} — #<span x-text="zreport.shift_id"></span></h3>
                        <dl class="pos-detail-dl">
                            <div><dt>{{ ___('Orders') }}</dt><dd x-text="zreport.orders"></dd></div>
                            <div><dt>{{ ___('Opening float') }}</dt><dd x-text="money(zreport.opening_float)"></dd></div>
                            <template x-for="row in Object.entries(zreport.sales_by_method || {})" :key="row[0]">
                                <div><dt x-text="(zreport.method_labels || {})[row[0]] || row[0]"></dt><dd x-text="money(row[1])"></dd></div>
                            </template>
                            <div x-show="(zreport.net_sales || 0) > 0"><dt>{{ ___('Net sales') }}</dt><dd x-text="money(zreport.net_sales)"></dd></div>
                            <template x-for="(t, idx) in (zreport.tax_lines || [])" :key="idx">
                                <div x-show="(t.vat || 0) > 0">
                                    <dt x-text="{{ Js::from(___('VAT')) }} + ' ' + (t.rate === null ? '-' : t.rate + '%')"></dt>
                                    <dd x-text="money(t.vat)"></dd>
                                </div>
                            </template>
                            <div x-show="(zreport.tax_total || 0) > 0"><dt>{{ ___('Tax total') }}</dt><dd x-text="money(zreport.tax_total)"></dd></div>
                            <div x-show="(zreport.discount_total || 0) > 0"><dt>{{ ___('Discounts') }}</dt><dd x-text="money(zreport.discount_total)"></dd></div>
                            <div x-show="(zreport.service_total || 0) > 0"><dt>{{ ___('Service charge') }}</dt><dd x-text="money(zreport.service_total)"></dd></div>
                            <template x-for="row in Object.entries(zreport.refunds_by_method || {})" :key="row[0]">
                                <div x-show="row[1] > 0">
                                    <dt x-text="{{ Js::from(___('Refunds (:method)')) }}.replace(':method', row[0])"></dt>
                                    <dd x-text="money(row[1])"></dd>
                                </div>
                            </template>
                            <div><dt>{{ ___('Tips') }}</dt><dd x-text="money(zreport.tips)"></dd></div>
                            <div><dt>{{ ___('Refunds') }}</dt><dd x-text="money(zreport.refunds_total)"></dd></div>
                            <div x-show="(zreport.cash_reconciliation || 0) > 0"><dt>{{ ___('Cash reconciliation') }}</dt><dd x-text="money(zreport.cash_reconciliation)"></dd></div>
                            <div><dt>{{ ___('Voids') }}</dt><dd x-text="zreport.voids"></dd></div>
                            <div><dt>{{ ___('Expected cash') }}</dt><dd x-text="money(zreport.expected_cash)"></dd></div>
                            <div x-show="zreport.counted_cash !== null"><dt>{{ ___('Counted cash') }}</dt><dd x-text="money(zreport.counted_cash)"></dd></div>
                            <div x-show="zreport.over_short !== null"><dt>{{ ___('Over / short') }}</dt>
                                <dd :class="overShortClass()" x-text="money(zreport.over_short)"></dd></div>
                        </dl>
                        <button type="button" class="pos-mini-clear" @click="printZReport()">{{ ___('Print') }}</button>
                    </div>
                </template>
            </div>
        </div>

        {{-- ── Reports tab ── --}}
        <div x-show="manageTab === 'reports'" x-cloak>
            <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px">
                <label class="pos-settle-field"><span>{{ ___('From') }}</span><input type="date" x-model="salesFrom"></label>
                <label class="pos-settle-field"><span>{{ ___('To') }}</span><input type="date" x-model="salesTo"></label>
                {{-- D9: branch filter (defaults to this register's branch; "All" = whole store). --}}
                @if(isset($branches) && $branches->count() > 1)
                    <label class="pos-settle-field"><span>{{ ___('Branch') }}</span>
                        <select x-model="salesBranch">
                            <option value="0">{{ ___('All branches') }}</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <button type="button" class="pos-mini-apply" :disabled="salesLoading" @click="loadSales()">{{ ___('Load') }}</button>
            </div>
            <p class="muted" x-show="salesLoading">{{ ___('Loading…') }}</p>

            <template x-if="sales && !salesLoading">
                <div>
                    <div class="pos-report-cards">
                        <div class="pos-report-card"><span class="lbl">{{ ___('Sales') }}</span><span class="val" x-text="money(sales.total)"></span></div>
                        <div class="pos-report-card"><span class="lbl">{{ ___('Orders') }}</span><span class="val" x-text="sales.orders"></span></div>
                    </div>

                    <h4 style="margin:14px 0 6px">{{ ___('By payment method') }}</h4>
                    <div class="pos-cust-hist-row" x-show="!methodRows().length"><span class="muted">{{ ___('No sales in this range.') }}</span></div>
                    <template x-for="row in methodRows()" :key="row.m">
                        <div class="pos-cust-hist-row"><span x-text="row.m"></span><span x-text="money(row.v)"></span></div>
                    </template>

                    <h4 style="margin:14px 0 6px">{{ ___('By staff') }}</h4>
                    <template x-for="s in (sales.sales_by_staff || [])" :key="s.id">
                        <div class="pos-cust-hist-row">
                            <span x-text="s.name"></span>
                            <span><span x-text="money(s.total)"></span>
                                <span class="muted" x-show="s.tips > 0" x-text="' · {{ ___('tips') }} ' + money(s.tips)"></span></span>
                        </div>
                    </template>

                    <h4 style="margin:14px 0 6px">{{ ___('By hour') }}</h4>
                    <template x-for="b in hourBars()" :key="b.h">
                        <div class="pos-hourbar">
                            <span class="h" x-text="b.h + ':00'"></span>
                            <span class="bar"><i :style="'width:' + b.pct + '%'"></i></span>
                            <span class="v" x-text="money(b.v)"></span>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>
</div>

{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- T4-1: orders hub — On hold / History / Offline tabs. Row click opens a detail
     side-panel. (T4-2/3/4 add note-resume, held detail + print, offline UX.) --}}
<div class="pos-panel" x-show="activePanel === 'orders'" x-cloak>
    <div class="pos-panel-head">
        <h2>{{ ___('Orders') }}</h2>
        <div class="pos-tabs">
            <button type="button" :class="{on: ordersTab === 'held'}" @click="setOrdersTab('held')">{{ ___('On hold') }}</button>
            <button type="button" :class="{on: ordersTab === 'incoming'}" @click="setOrdersTab('incoming')">{{ ___('Online orders') }}</button>
            <button type="button" :class="{on: ordersTab === 'history'}" @click="setOrdersTab('history')">{{ ___('Order history') }}</button>
            <button type="button" :class="{on: ordersTab === 'offline'}" @click="setOrdersTab('offline')">
                {{ ___('Offline') }}<span class="pos-tab-badge" x-show="queued > 0" x-text="queued"></span>
            </button>
            {{-- PARITY-RUN T3: only exists while the store runs staff_approve
                 verification (approvalsMode comes from the endpoint itself, so the
                 tab disappears the moment the owner switches the mode off). --}}
            <button type="button" x-show="approvalsMode === 'staff_approve'" x-cloak
                    :class="{on: ordersTab === 'approvals'}"
                    @click="ordersTab = 'approvals'; selectedOrder = null; loadApprovals()">
                {{ ___('Approvals') }}<span class="pos-tab-badge" x-show="(approvals || []).length" x-text="(approvals || []).length"></span>
            </button>
        </div>
        <button type="button" class="pos-panel-close" @click="activePanel = 'home'" title="{{ ___('Close') }}">&times;</button>
    </div>

    <div class="pos-panel-body pos-orders-body">
        <div class="pos-orders-list">
            {{-- search across the active tab's rows (id / type / table / note / channel) --}}
            <div class="pos-hub-search" x-show="ordersTab !== 'offline'">
                <input type="search" class="pos-mini-input" style="width:100%" x-model="hubQuery" autocomplete="off"
                       placeholder="{{ ___('Search order # / table / note…') }}">
            </div>
            <p class="muted" x-show="ordersLoading" style="padding:12px">{{ ___('Loading…') }}</p>

            {{-- Held + history share the row shape; offline rows are queued POSTs,
                 and T3 approvals have their own two-button row shape. --}}
            <template x-if="ordersTab !== 'offline' && ordersTab !== 'approvals'">
                <div>
                    <template x-for="o in currentOrders()" :key="o.id">
                        <div class="pos-order-row" :class="{on: selectedOrder && selectedOrder.id === o.id}" @click="openOrderDetail(o)">
                            <span class="pos-order-main">
                                <span class="pos-order-id">
                                    #<span x-text="o.id"></span>
                                    <span class="pos-age-chip" x-show="o.time" x-text="ageChip(o.time)"></span>
                                    <span class="pos-src-chip" x-show="ordersTab === 'incoming' && o.source" :class="o.is_channel ? 'pos-src-chip--channel' : ''" x-text="o.source_label || o.source"></span>
                                </span>
                                <span class="pos-order-sub" x-text="(o.type || '') + (o.table_number ? ' · {{ ___('Table') }} ' + o.table_number : '') + ' · ' + (o.item_count || 0) + ' {{ ___('items') }}'"></span>
                                {{-- T4-2: hold note --}}
                                <span class="pos-order-note" x-show="o.note" x-text="'“' + (o.note || '') + '”'"></span>
                            </span>
                            <span class="pos-order-side">
                                <span class="pos-order-total" x-text="money(o.total)"></span>
                                <span class="pos-order-status" x-text="o.is_paid ? '{{ ___('Paid') }}' : o.status"></span>
                                {{-- T4-2: resume button on the held tab --}}
                                <button type="button" class="pos-resume-btn" x-show="ordersTab === 'held'" @click.stop="resumeOrder(o)">{{ ___('Resume') }}</button>
                            </span>
                        </div>
                    </template>
                    <div class="pos-empty" x-show="!ordersLoading && !currentOrders().length">
                        <span class="ico">@include('staff.pos.partials.icon', ['name' => 'receipt', 'size' => 40])</span>
                        <div x-text="ordersTab === 'held' ? '{{ ___('No held orders.') }}' : (ordersTab === 'incoming' ? '{{ ___('No online orders yet.') }}' : '{{ ___('No recent orders.') }}')"></div>
                    </div>
                </div>
            </template>

            {{-- PARITY-RUN T3 — dine-in orders parked for staff approval. Until one
                 of these buttons is pressed the order is invisible to the KDS, the
                 staff PWA and the floor (Order::scopeLive excludes it). --}}
            <template x-if="ordersTab === 'approvals'">
                <div>
                    <template x-for="o in (approvals || [])" :key="o.id">
                        <div class="pos-order-row">
                            <span class="pos-order-main">
                                <span class="pos-order-id">
                                    #<span x-text="o.id"></span>
                                    <span class="pos-age-chip" x-show="o.time" x-text="ageChip(o.time)"></span>
                                </span>
                                <span class="pos-order-sub" x-text="(o.customer || '') + (o.table_number ? ' · {{ ___('Table') }} ' + o.table_number : '') + ' · ' + (o.item_count || 0) + ' {{ ___('items') }}'"></span>
                            </span>
                            <span class="pos-order-side">
                                <span class="pos-order-total" x-text="money(o.total)"></span>
                                <button type="button" class="pos-resume-btn" @click.stop="approveOrder(o)">{{ ___('Approve') }}</button>
                                <button type="button" class="pos-discard-btn" @click.stop="rejectOrder(o)">{{ ___('Reject') }}</button>
                            </span>
                        </div>
                    </template>
                    <div class="pos-empty" x-show="!approvalsLoading && !(approvals || []).length">
                        <span class="ico">@include('staff.pos.partials.icon', ['name' => 'check', 'size' => 40])</span>
                        <div>{{ ___('Nothing waiting for approval.') }}</div>
                    </div>
                </div>
            </template>

            <template x-if="ordersTab === 'offline'">
                <div>
                    <div class="pos-offline-bar" x-show="offlineOrders.length">
                        <span x-text="offlineOrders.length + ' {{ ___('queued') }}'"></span>
                        <button type="button" class="pos-resume-btn" @click="retryQueue()">{{ ___('Sync now') }}</button>
                    </div>
                    <template x-for="o in offlineOrders" :key="o.id">
                        <div class="pos-order-row is-offline">
                            <span class="pos-order-main">
                                <span class="pos-order-id">
                                    <template x-if="o.kind === 'sale'"><span>{{ ___('Offline sale') }} <span x-text="o.total"></span></span></template>
                                    <template x-if="o.kind !== 'sale'"><span>{{ ___('Queued') }}</span></template>
                                    <span class="pos-badge" x-show="o.voided">{{ ___('Void') }}</span>
                                    <span class="pos-age-chip" x-show="o.time" x-text="ageChip(o.time)"></span>
                                </span>
                                <span class="pos-order-sub" x-text="(o.body.type || '') + ' · ' + (o.item_count || 0) + ' {{ ___('items') }}'"></span>
                            </span>
                            <span class="pos-order-side">
                                <button type="button" class="pos-resume-btn" x-show="o.kind === 'sale'" @click.stop="reprintOfflineSale(o)">{{ ___('Print receipt') }}</button>
                                <button type="button" class="pos-discard-btn" x-show="o.kind === 'sale' && !o.voided" @click.stop="voidOfflineSale(o)">{{ ___('Void') }}</button>
                                <button type="button" class="pos-resume-btn" x-show="o.kind !== 'sale'" @click.stop="retryQueue()">{{ ___('Retry') }}</button>
                                <button type="button" class="pos-discard-btn" x-show="o.kind !== 'sale'" @click.stop="discardQueued(o)">{{ ___('Discard') }}</button>
                            </span>
                        </div>
                    </template>
                    <div class="pos-empty" x-show="!ordersLoading && !offlineOrders.length">
                        <span class="ico">@include('staff.pos.partials.icon', ['name' => 'check', 'size' => 40])</span>
                        <div>{{ ___('Nothing queued — you are in sync.') }}</div>
                    </div>
                </div>
            </template>
        </div>

        {{-- detail side-panel --}}
        <div class="pos-order-detail" x-show="selectedOrder" x-cloak>
            <template x-if="selectedOrder">
                <div>
                    <div class="pos-panel-head">
                        <h3>{{ ___('Order') }} #<span x-text="selectedOrder.id"></span></h3>
                        <button type="button" class="pos-panel-close" @click="closeOrderDetail()">&times;</button>
                    </div>
                    {{-- T4-3: lines w/ variant / options / extras / note --}}
                    <div class="pos-detail-lines" x-show="selectedOrder.lines">
                        <template x-for="(l, i) in (selectedOrder.lines || [])" :key="i">
                            <div class="pos-detail-line">
                                <div class="pos-detail-line-h">
                                    <span x-text="l.qty + '× ' + l.name"></span>
                                </div>
                                <small class="sub" x-show="l.variant" x-text="'» ' + l.variant"></small>
                                <template x-for="o in (l.options || [])" :key="o.title">
                                    <small class="sub" x-text="'» ' + o.title + ': ' + o.selected"></small>
                                </template>
                                <small class="sub" x-show="l.extras && l.extras.length" x-text="'+ ' + (l.extras || []).join(', ')"></small>
                                <small class="sub note" x-show="l.note" x-text="'“' + l.note + '”'"></small>
                            </div>
                        </template>
                    </div>

                    <dl class="pos-detail-dl">
                        <div><dt>{{ ___('Type') }}</dt><dd x-text="selectedOrder.type"></dd></div>
                        <div x-show="selectedOrder.table_number"><dt>{{ ___('Table') }}</dt><dd x-text="selectedOrder.table_number"></dd></div>
                        <div x-show="selectedOrder.note"><dt>{{ ___('Note') }}</dt><dd x-text="selectedOrder.note"></dd></div>
                        <div><dt>{{ ___('Total') }}</dt><dd x-text="money(selectedOrder.total)"></dd></div>
                        <div><dt>{{ ___('Status') }}</dt><dd x-text="selectedOrder.is_paid ? '{{ ___('Paid') }}' : selectedOrder.status"></dd></div>
                    </dl>

                    {{-- FEATURES-5 Track C: customer voice note --}}
                    <div class="pos-detail-voice" x-show="selectedOrder.voice_note_url" style="margin:8px 0">
                        <audio controls preload="none" style="width:100%;height:38px" :src="selectedOrder.voice_note_url"></audio>
                        <small class="sub" x-show="selectedOrder.voice_note_duration" x-text="'{{ ___('Voice note') }} · ' + selectedOrder.voice_note_duration + 's'"></small>
                        {{-- ZERO-TOUCH Track B3: transcript text under the player. --}}
                        <small class="sub" style="display:block;margin-top:4px" x-show="selectedOrder.voice_note_transcript" x-cloak
                               x-text="'📝 ' + (selectedOrder.voice_note_transcript || '')"></small>
                    </div>

                    {{-- T4-3: actions --}}
                    <div class="pos-detail-actions">
                        <button type="button" class="pos-detail-btn resume" x-show="selectedOrder.held" @click="resumeOrder(selectedOrder)">{{ ___('Resume') }}</button>
                        <button type="button" class="pos-detail-btn" x-show="!selectedOrder.is_paid" @click="startAddToOrder(selectedOrder)">{{ ___('Add products') }}</button>
                        {{-- D5: prioritise this ticket on the kitchen board. --}}
                        <button type="button" class="pos-detail-btn" x-show="!selectedOrder.is_paid && selectedOrder.status !== 'cancelled'"
                                :class="selectedOrder.is_rush ? 'danger' : ''" @click="toggleRush(selectedOrder)"
                                x-text="selectedOrder.is_rush ? @js(___('Rush ✓')) : @js(___('Mark rush'))"></button>
                        <button type="button" class="pos-detail-btn" x-show="!selectedOrder.is_paid" @click="startEditOrder(selectedOrder)">{{ ___('Edit order') }}</button>
                        {{-- PARITY-RUN T4: move this order to another table. Not
                             feature-gated — transferring predates `table_merge`
                             and pulling it behind the kill-switch would remove a
                             capability stores already use. --}}
                        <button type="button" class="pos-detail-btn" x-show="!selectedOrder.is_paid" @click="openTransfer(selectedOrder)">{{ ___('Move order to table') }}</button>
                        <button type="button" class="pos-detail-btn settle" x-show="!selectedOrder.is_paid" @click="openExtSettle(selectedOrder)">{{ ___('Settle') }}</button>
                        <button type="button" class="pos-detail-btn print" @click="printInvoice(selectedOrder)">{{ ___('Print invoice') }}</button>
                        {{-- STF-013: manager-gated corrections. Void cancels an unpaid
                             order (server refuses paid); refund records money out on a
                             paid one, capped server-side at the remaining amount. --}}
                        <button type="button" class="pos-detail-btn danger" x-show="canRefund && !selectedOrder.is_paid && selectedOrder.status !== 'cancelled'"
                                @click="openAdjust('void')">{{ ___('Void order') }}</button>
                        <button type="button" class="pos-detail-btn danger" x-show="canRefund && selectedOrder.is_paid"
                                @click="openAdjust('refund')">{{ ___('Refund') }}</button>
                    </div>

                    {{-- STF-013: inline void/refund form --}}
                    <div class="pos-adjust-pane" x-show="adjPane" x-cloak style="margin-top:10px;padding:10px;border:1px solid var(--pos-line, #333);border-radius:8px">
                        <h4 style="margin:0 0 8px" x-text="adjPane === 'void' ? @js(___('Void order')) : @js(___('Refund'))"></h4>
                        {{-- D1: refund by amount OR by item --}}
                        <div x-show="adjPane === 'refund' && adjLines.length" style="display:flex;gap:6px;margin-bottom:8px">
                            <button type="button" class="pos-detail-btn" :class="adjMode === 'amount' ? 'settle' : ''"
                                    @click="adjMode = 'amount'">{{ ___('By amount') }}</button>
                            <button type="button" class="pos-detail-btn" :class="adjMode === 'items' ? 'settle' : ''"
                                    @click="adjMode = 'items'">{{ ___('By item') }}</button>
                        </div>
                        {{-- D1: per-item refund picker --}}
                        <div x-show="adjPane === 'refund' && adjMode === 'items'" x-cloak style="margin-bottom:8px">
                            <small class="sub">{{ ___('Pick items to refund') }}</small>
                            <template x-for="l in adjLines" :key="l.order_item_id">
                                <div style="display:flex;align-items:center;gap:8px;margin-top:6px" x-show="l.remaining > 0">
                                    <span style="flex:1" x-text="l.name + ' · ' + l.remaining + ' {{ ___('left') }}'"></span>
                                    <input type="number" min="0" :max="l.remaining" step="1" class="pos-mini-input" style="width:64px"
                                           x-model.number="l.qty">
                                    <span class="sub" x-text="money(adjLineAmount(l))"></span>
                                </div>
                            </template>
                        </div>
                        <div x-show="adjPane === 'refund' && adjMode === 'amount'" style="margin-bottom:8px">
                            <small class="sub" x-text="@js(___('Already refunded')) + ': ' + money(selectedOrder.refunded_total || 0)"></small>
                            <input type="number" min="0.01" step="0.01" class="pos-mini-input" style="width:100%" x-model.number="adjAmount"
                                   placeholder="{{ ___('Amount') }}">
                        </div>
                        <div x-show="adjPane === 'refund'" style="margin-bottom:8px">
                            <select class="pos-mini-input" style="width:100%" x-model="adjMethod">
                                <option value="cash">{{ ___('Cash') }}</option>
                                <option value="card">{{ ___('Card') }}</option>
                                <option value="online">{{ ___('Online') }}</option>
                                <option value="other">{{ ___('Other') }}</option>
                            </select>
                        </div>
                        <input type="text" class="pos-mini-input" style="width:100%" maxlength="190" x-model="adjReason"
                               placeholder="{{ ___('Reason (required)') }}">
                        <div style="display:flex;gap:8px;margin-top:8px">
                            <button type="button" class="pos-detail-btn danger" :disabled="adjSending" @click="submitAdjust()"
                                    x-text="adjPane === 'void' ? @js(___('Confirm void')) : @js(___('Confirm refund'))"></button>
                            <button type="button" class="pos-detail-btn" @click="adjPane = null">{{ ___('Cancel') }}</button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

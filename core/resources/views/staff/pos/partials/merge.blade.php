{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- PARITY-RUN T4 — merge confirmation + "move order to table" sheets.

     Both are server-authoritative: every figure shown is one the server sent
     back (the merged bill is the sum of each order's own Order::getTotalAttribute()),
     and every refusal message is the server's, so the dialog can never invent a
     rule the API does not enforce. --}}

{{-- Merge confirmation --}}
<div class="pos-sheet-back" x-show="mergeDialog" x-cloak @click.self="closeMergeDialog()">
    <div class="pos-sheet pos-merge-sheet" role="dialog" aria-modal="true">
        <div class="pos-panel-head">
            <h2>{{ ___('Merge tables') }}</h2>
            <button type="button" class="pos-panel-close" @click="closeMergeDialog()" title="{{ ___('Close') }}">&times;</button>
        </div>

        <div class="pos-sheet-body">
            <p class="muted">{{ ___('The first table keeps the bill. Open orders on the other tables move onto it.') }}</p>

            <ul class="pos-merge-list">
                <template x-for="(id, i) in mergeSelection" :key="id">
                    <li class="pos-merge-row" :class="{ 'is-primary': i === 0 }">
                        <span class="pos-merge-name" x-text="mergeTableName(id)"></span>
                        <span class="pos-merge-tag" x-show="i === 0">{{ ___('Keeps the bill') }}</span>
                        <button type="button" class="pos-merge-make" x-show="i > 0" @click="makeMergePrimary(id)">
                            {{ ___('Keep the bill here') }}
                        </button>
                    </li>
                </template>
            </ul>

            <p class="pos-merge-error" x-show="mergeError" x-cloak x-text="mergeError"></p>
        </div>

        <div class="pos-sheet-actions">
            <button type="button" class="pos-detail-btn" @click="closeMergeDialog()">{{ ___('Cancel') }}</button>
            <button type="button" class="pos-detail-btn settle" :disabled="mergeBusy" @click="doMerge()">
                <span x-show="!mergeBusy">{{ ___('Merge tables') }}</span>
                <span x-show="mergeBusy" x-cloak>{{ ___('Loading…') }}</span>
            </button>
        </div>
    </div>
</div>

{{-- Move an order to another table --}}
<div class="pos-sheet-back" x-show="transferOrder" x-cloak @click.self="closeTransfer()">
    <div class="pos-sheet pos-merge-sheet" role="dialog" aria-modal="true">
        <template x-if="transferOrder">
            <div>
                <div class="pos-panel-head">
                    <h2>{{ ___('Move order to table') }}</h2>
                    <button type="button" class="pos-panel-close" @click="closeTransfer()" title="{{ ___('Close') }}">&times;</button>
                </div>

                <div class="pos-sheet-body">
                    <p class="muted">
                        {{ ___('Order') }} #<span x-text="transferOrder.id"></span>
                        {{-- JS-encoded so a translation carrying an apostrophe
                             cannot close the JS string inside the attribute. --}}
                        <span x-show="transferOrder.table_number" x-cloak
                              x-text="@js(' · ' . ___('Table') . ' ') + transferOrder.table_number"></span>
                    </p>

                    <div class="pos-tile-grid pos-merge-grid">
                        <template x-for="t in transferTargets()" :key="t.id">
                            <button type="button" class="pos-table-tile" :class="'is-' + t.status"
                                    :disabled="transferBusy" @click="doTransfer(t)">
                                <span class="pos-tile-name" x-text="t.name"></span>
                                <span class="pos-tile-seats"><span x-text="t.seats"></span> {{ ___('seats') }}</span>
                                <span class="pos-tile-covers" x-show="t.merge_label" x-cloak x-text="t.merge_label"></span>
                            </button>
                        </template>
                    </div>

                    <div class="pos-empty" x-show="tablesLoaded && !transferTargets().length" x-cloak>
                        <div>{{ ___('No other table is available.') }}</div>
                    </div>
                    <p class="muted" x-show="!tablesLoaded" x-cloak>{{ ___('Loading…') }}</p>

                    <p class="pos-merge-error" x-show="transferError" x-cloak x-text="transferError"></p>
                </div>

                <div class="pos-sheet-actions">
                    <button type="button" class="pos-detail-btn" @click="closeTransfer()">{{ ___('Cancel') }}</button>
                </div>
            </div>
        </template>
    </div>
</div>

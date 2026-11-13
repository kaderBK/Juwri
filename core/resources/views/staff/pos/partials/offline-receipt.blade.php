{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- OP-9: the receipt of a sale made offline, built from local data only (snapshot + the queued records) and printed with the
     browser dialog. It says plainly that the sale has not reached the server yet. While printing, only this card shows. --}}
<div class="pos-offline-receipt-modal" x-show="offlineReceiptOpen" x-cloak @keydown.escape.window="closeOfflineReceipt()">
    <div class="pos-offline-receipt" role="dialog" aria-modal="true" aria-label="{{ ___('Offline receipt') }}">
        <template x-if="offlineReceipt">
            <div class="receipt-body">
                <div class="r-head">
                    <strong x-text="offlineReceipt.store"></strong>
                    <div>{{ ___('Offline receipt') }} <span x-text="'#' + offlineReceipt.no"></span></div>
                    <div class="r-muted" x-text="new Date(offlineReceipt.time).toLocaleString(document.documentElement.lang || undefined)"></div>
                    <div class="r-muted" x-show="offlineReceipt.cashier" x-text="offlineReceipt.cashier"></div>
                </div>
                <div class="r-void" x-show="offlineReceipt.voided">{{ ___('Void') }}</div>
                <table class="r-lines">
                    <template x-for="(l, i) in offlineReceipt.lines" :key="i">
                        <tr>
                            <td>
                                <span x-text="l.qty + ' × ' + l.name"></span>
                                <span class="r-muted" x-show="l.variant" x-text="l.variant"></span>
                                <span class="r-muted" x-show="l.extras.length" x-text="l.extras.join(', ')"></span>
                            </td>
                            <td class="r-num" x-text="l.total"></td>
                        </tr>
                    </template>
                </table>
                <table class="r-totals">
                    <tr><td>{{ ___('Subtotal') }}</td><td class="r-num" x-text="offlineReceipt.subtotal"></td></tr>
                    <tr x-show="offlineReceipt.discount"><td>{{ ___('Discount') }}</td><td class="r-num" x-text="'−' + offlineReceipt.discount"></td></tr>
                    <tr x-show="offlineReceipt.tax"><td>{{ ___('Tax') }}</td><td class="r-num" x-text="offlineReceipt.tax"></td></tr>
                    <tr x-show="offlineReceipt.tip"><td>{{ ___('Tip') }}</td><td class="r-num" x-text="offlineReceipt.tip"></td></tr>
                    <tr class="r-total"><td>{{ ___('Total') }}</td><td class="r-num" x-text="offlineReceipt.currency + ' ' + offlineReceipt.total"></td></tr>
                    <tr x-show="offlineReceipt.tendered"><td>{{ ___('Cash') }}</td><td class="r-num" x-text="offlineReceipt.tendered"></td></tr>
                    <tr x-show="offlineReceipt.tendered"><td>{{ ___('Change') }}</td><td class="r-num" x-text="offlineReceipt.change"></td></tr>
                </table>
                <div class="r-note">{{ ___('Not sent to the server yet') }}</div>
            </div>
        </template>
        <div class="r-actions">
            <button type="button" class="pos-mini-apply" @click="printOfflineReceipt()">{{ ___('Print receipt') }}</button>
            <button type="button" class="pos-mini-clear" @click="closeOfflineReceipt()">{{ ___('Close') }}</button>
        </div>
    </div>
</div>

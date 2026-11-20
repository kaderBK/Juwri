<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD
// Shared throttle prefixes on purpose (one counter across all routes using it): staff-auth (staff login, staff PIN, POS unlock, kiosk PIN: one limit of 10 a minute).

// P1-09: moved from routes/web.php (slot Pos/web-3); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

/* POS register — usable by STAFF (pos permission) OR the restaurant OWNER (web guard).
       Actor + restaurant + permissions are resolved in PosController::context(). */
// P6-122: enforced CSP on the register (plus the print-bridge origins, see PosController::index).
Route::middleware(['auth:staff,web', 'csp.app'])->group(function () {
    /* POS P1: register */
    Route::get('pos', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@index')->name('pos.index');
    Route::get('pos/menu', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@menu')->name('pos.menu');
    Route::get('pos/offline/snapshot', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@offlineSnapshot')->middleware('cache.headers:private;no_store')->name('pos.offline.snapshot'); // offline POS catalogue snapshot (OFFLINE-POS-SPEC §2)
    Route::post('pos/offline/replay', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@offlineReplay')->middleware('throttle:60,1,pos-offline-replay')->name('pos.offline.replay'); // offline POS replay (OFFLINE-POS-SPEC §3)
    Route::get('pos/offline/exceptions', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@offlineExceptions')->middleware('cache.headers:private;no_store')->name('pos.offline.exceptions'); // manager view of offline exceptions
    Route::get('pos/offline/exceptions/screen', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@offlineExceptionsScreen')->name('pos.offline.exceptions.screen'); // manager screen: offline exceptions (OP-10)
    Route::post('pos/offline/cards/{id}/resolve', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@offlineCardResolve')->whereNumber('id')->name('pos.offline.cards.resolve'); // manager: declined offline card collected another way
    Route::post('pos/card-reversals/{id}/resolve', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@cardReversalResolve')->whereNumber('id')->name('pos.card_reversals.resolve'); // P5-28: manager resolves an unbooked card refund/void
    Route::post('pos/offline/devices', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@offlineDeviceRegister')->middleware('throttle:10,1,pos-offline-device')->name('pos.offline.devices.register');
    Route::get('pos/offline/devices/status', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@offlineDeviceStatus')->middleware('cache.headers:private;no_store')->name('pos.offline.devices.status');
    Route::post('pos/offline/devices/wiped', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@offlineDeviceWiped')->name('pos.offline.devices.wiped');
    Route::get('pos/offline/devices/escrow', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@offlineDeviceEscrow')->middleware('throttle:30,1,pos-offline-escrow')->middleware('cache.headers:private;no_store')->name('pos.offline.devices.escrow');
    Route::post('pos/offline/zatca/onboard', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@offlineZatcaOnboard')->middleware('throttle:10,1,pos-offline-zatca')->name('pos.offline.zatca.onboard'); // owner on the till; OP-0 flag
    Route::post('pos/offline/zatca/compliance', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@offlineZatcaCompliance')->middleware('throttle:10,1,pos-offline-zatca')->name('pos.offline.zatca.compliance');
    Route::get('pos/offline/zatca/status', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@offlineZatcaStatus')->middleware('cache.headers:private;no_store')->name('pos.offline.zatca.status');
    Route::post('pos/offline/zatca/invoices', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@offlineZatcaInvoices')->middleware('throttle:60,1,pos-offline-zatca-invoices')->name('pos.offline.zatca.invoices');
    Route::get('pos/offline/devices', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@offlineDevices')->middleware('cache.headers:private;no_store')->name('pos.offline.devices'); // owner only
    Route::post('pos/offline/devices/{id}/revoke', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@offlineDeviceRevoke')->whereNumber('id')->name('pos.offline.devices.revoke'); // owner only
    Route::post('pos/offline/devices/{id}/wipe', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@offlineDeviceWipe')->whereNumber('id')->name('pos.offline.devices.wipe'); // owner only
    // P5-21: SoftPOS card payments (store's own Geidea / EdfaPay account; 404 while every provider is off)
    Route::post('pos/terminal/charges', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@terminalCharge')->middleware('throttle:60,1,pos-terminal')->name('pos.terminal.charge');
    Route::get('pos/terminal/charges/{uuid}', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@terminalStatus')->whereUuid('uuid')->middleware(['throttle:120,1,pos-terminal-status', 'cache.headers:private;no_store'])->name('pos.terminal.status');
    Route::post('pos/terminal/charges/{uuid}/refunds', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@terminalRefund')->whereUuid('uuid')->middleware('throttle:30,1,pos-terminal')->name('pos.terminal.refund');
    Route::post('pos/terminal/charges/{uuid}/void', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@terminalVoid')->whereUuid('uuid')->middleware('throttle:30,1,pos-terminal')->name('pos.terminal.void');
    Route::get('pos/terminal/accounts', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@terminalAccounts')->middleware('cache.headers:private;no_store')->name('pos.terminal.accounts'); // owner only
    Route::post('pos/terminal/accounts', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@terminalAccountSave')->middleware('throttle:10,1,pos-terminal-account')->name('pos.terminal.accounts.save'); // owner only
    Route::get('pos/combos', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@combos')->name('pos.combos'); // T6: combo tiles (server-priced)
    Route::post('pos/coupon-check', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@couponCheck')->middleware('coupon.check:pos')->name('pos.coupon.check'); // T1-3: read-only coupon validation
    Route::get('pos/customers', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@customers')->name('pos.customers'); // T2-1: customer search
    Route::get('pos/tables', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@tables')->name('pos.tables'); // T3-1: table floor state
    Route::post('pos/customers', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@storeCustomer')->name('pos.customers.store'); // T2-3: inline create
    Route::post('pos/customers/{id}', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@updateCustomer')->name('pos.customers.update'); // edit from register
    Route::get('pos/customers/{id}/orders', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@customerOrders')->name('pos.customers.orders'); // history card
    Route::get('pos/held', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@held')->name('pos.held');
    Route::get('pos/orders/recent', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@recentOrders')->name('pos.orders.recent'); // T4-1: history list
    Route::get('pos/orders/incoming', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@incomingOrders')->name('pos.orders.incoming'); // orders placed outside the register
    Route::get('pos/orders/{id}/detail', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@orderDetail')->name('pos.orders.detail'); // T4-3: full detail w/ lines
    Route::post('pos/orders', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@store')->name('pos.orders.store');
    Route::post('pos/orders/{id}/items', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@addItems')->name('pos.orders.addItems'); // add products to an open order
    Route::get('pos/orders/{id}/cart', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@orderCart')->name('pos.orders.cart'); // load an order into the cart (edit)
    Route::post('pos/orders/{id}/replace', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@replaceItems')->name('pos.orders.replace'); // replace all lines (edit)
    Route::post('pos/orders/{id}/coupon', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@applyCoupon')->middleware('coupon.check:pos')->name('pos.orders.coupon'); // settle-time coupon on an existing order
    Route::post('pos/orders/{id}/settle', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@settle')->name('pos.orders.settle');
    Route::post('pos/orders/{id}/hold', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@hold')->name('pos.orders.hold');
    Route::post('pos/orders/{id}/resume', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@resume')->name('pos.orders.resume');
    Route::post('pos/orders/{id}/split', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@splitOrder')->name('pos.orders.split'); // T5-6
    Route::post('pos/orders/{id}/split-pay', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@splitPay')->name('pos.orders.splitPay'); // T5-6
    /* POS P2: shifts + Z-report + voids/refunds (manager gates in-controller) */
    // D8: terminal registry (minimal MultiPOS)
    Route::get('pos/terminals', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@terminals')->name('pos.terminals');
    Route::post('pos/terminals', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@storeTerminal')->name('pos.terminals.store');
    Route::post('pos/terminals/{id}/heartbeat', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@terminalHeartbeat')->whereNumber('id')->name('pos.terminals.heartbeat');
    Route::post('pos/terminals/{id}', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@updateTerminal')->whereNumber('id')->name('pos.terminals.update');
    Route::post('pos/terminals/{id}/retire', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@retireTerminal')->whereNumber('id')->name('pos.terminals.retire');
    Route::get('pos/shifts/current', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@currentShift')->name('pos.shift.current');
    Route::post('pos/shifts', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@openShift')->name('pos.shift.open');
    Route::post('pos/shifts/handoff', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@handoffShift')->name('pos.shift.handoff'); // D2: drawer handoff
    Route::post('pos/shifts/{id}/close', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@closeShift')->name('pos.shift.close');
    Route::get('pos/shifts/{id}/zreport', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@zReport')->name('pos.shift.zreport');
    Route::post('pos/orders/{id}/void', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@void')->name('pos.orders.void');
    Route::post('pos/orders/{id}/refund', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@refund')->name('pos.orders.refund');
    Route::post('pos/orders/{id}/refund-items', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@refundItems')->name('pos.orders.refundItems'); // D1: itemised refund
    Route::post('pos/orders/{id}/rush', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@toggleRush')->name('pos.orders.rush'); // D5: rush/priority
    /* POS P3: printable receipt / kitchen ticket (?format=kitchen) */
    Route::get('pos/orders/{id}/receipt', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@receipt')->name('pos.orders.receipt');
    /* POS P4: floor map live mode */
    Route::get('pos/floor', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@floor')->name('pos.floor');
    Route::post('pos/orders/{id}/transfer', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@transfer')->name('pos.orders.transfer');
    /* PARITY-RUN T4: table merge from the register. Gated in-controller via
       TableMergeService::enabled() — the staff guard's user is not the buyer
       (never `feature:` middleware on a staff/guest-reachable route). */
    Route::get('pos/tables/merges', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@mergeState')->name('pos.tables.merges');
    Route::post('pos/tables/merge', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@mergeTables')->name('pos.tables.merge');
    Route::delete('pos/tables/merges/{group}', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@unmergeTables')->name('pos.tables.unmerge');
    /* POS P6: sales reporting (manager/analytics) */
    Route::get('pos/sales', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@sales')->name('pos.sales');
    /* POS P2: customer-facing display (2nd screen) + polling-fallback state */
    Route::get('pos/display', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@display')->name('pos.display');
    Route::get('pos/display/state', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@displayState')->name('pos.display.state');
    // SYSTEMS-RUN D-P1: loyalty check-in from the customer display.
    Route::post('pos/display/check-in', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@displayCheckIn')->name('pos.display.checkIn');
    /* POS P3: quick-switch lock unlock (PIN swaps staff actor / owner password) */
    Route::post('pos/unlock', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@unlock')->middleware('throttle:10,1,staff-auth')->name('pos.unlock');
    /* POS P1: print/station settings per branch (manager/owner) */
    Route::get('pos/settings', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@settingsShow')->name('pos.settings');
    Route::post('pos/settings', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@settingsSave')->name('pos.settings.save');
    /* PARITY-RUN T1: direct printing — the register owns the device handle, so it
       collects rendered jobs and reports the result. Feature-gated in-controller
       (the staff guard's user is not the buyer; the store owner is). */
    Route::get('pos/print/config', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@printConfig')->name('pos.print.config');
    Route::get('pos/print/jobs', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@printJobs')->name('pos.print.jobs');
    Route::post('pos/print/jobs/{id}/ack', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@printAck')->name('pos.print.ack');
    Route::post('pos/print/test/{profile}', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@printTest')->name('pos.print.test');
    Route::post('pos/print/orders/{id}', '\Juwri\Modules\Pos\Http\Controllers\Staff\PosController@printOrder')->name('pos.print.order');
});

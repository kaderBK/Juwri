<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Agents/web-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// T6 AI phone agent — owner config + call logs.
// FIX-2 F2-13: the gate moved from the index route to the GROUP. Only the
// landing GET was gated, so save/toggle/call — the writes that provision a
// live phone line — were reachable by any owner without the SKU.
Route::controller('\Juwri\Modules\Agents\Http\Controllers\User\PhoneAgentController')->name('stores.phoneagent.')->prefix('phone-agent')->middleware('feature:ai_phone_agent')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/', 'save')->name('save');
    Route::post('/{id}/update', 'update')->name('update');
    Route::post('/{id}/toggle', 'toggle')->name('toggle');
    Route::get('/calls', 'calls')->name('calls.index');
    Route::get('/calls/{id}', 'show')->name('calls.show');
    Route::get('/call/{id}', 'call')->name('call');
});

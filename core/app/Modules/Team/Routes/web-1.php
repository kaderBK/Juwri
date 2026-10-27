<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Team/web-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// SYSTEMS-RUN T: team chat + announcements (owner inbox). Superadmin-gated.
Route::controller('\Juwri\Modules\Team\Http\Controllers\User\TeamCommsController')->name('stores.team.')->prefix('team')->middleware('feature:team_comms')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('channels', 'createChannel')->name('channels.store');
    Route::get('channels/{id}', 'thread')->name('thread');
    Route::post('channels/{id}/post', 'post')->name('post');
    // T-P2 shift notes + checklists
    Route::get('checklists', 'checklists')->name('checklists');
    Route::post('checklists/templates', 'storeTemplate')->name('checklists.templates.store');
    Route::post('checklists/runs/{runId}/toggle', 'toggleChecklistItem')->name('checklists.toggle');
    Route::post('notes', 'storeNote')->name('notes.store');
});

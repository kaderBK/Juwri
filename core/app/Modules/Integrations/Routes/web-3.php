<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Integrations/web-3); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// T7 GloriaFood migration importer.
// FIX-2 F2-11: same shape — the POST importers (preview/import/customers/
// widget) carried no gate at all, so the whole one-time SKU was a no-op for
// anyone who posted straight at them.
Route::controller('\Juwri\Modules\Integrations\Http\Controllers\User\GloriaFoodImportController')->name('stores.import.')->prefix('import/gloriafood')->middleware('feature:gloriafood_import')->group(function () {
    Route::get('/', 'page')->name('gloriafood');
    Route::post('/preview', 'preview')->name('gloriafood.preview');
    Route::post('/', 'import')->name('gloriafood.import');
    Route::post('/customers', 'customers')->name('gloriafood.customers');
    Route::post('/widget', 'widget')->name('gloriafood.widget');
});

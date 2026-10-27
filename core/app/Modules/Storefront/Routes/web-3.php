<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Storefront/web-3); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// GAP-3: storefront token issuance (per-restaurant public API tokens)
Route::post('api/storefront-tokens', '\Juwri\Modules\Storefront\Http\Controllers\User\StorefrontTokenController@store')->name('storefront.token.create');
Route::delete('api/storefront-tokens/{id}', '\Juwri\Modules\Storefront\Http\Controllers\User\StorefrontTokenController@destroy')->name('storefront.token.delete');
// P3: theme customizer (schema-driven, data-only)
Route::get('theme-customizer', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeCustomizerController@page')->name('theme.customizer.page');
Route::get('theme-customizer/{storeId}', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeCustomizerController@schema')->name('theme.customizer.schema');
Route::post('theme-customizer/{storeId}/draft', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeCustomizerController@saveDraft')->name('theme.customizer.draft');
Route::post('theme-customizer/{storeId}/publish', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeCustomizerController@publish')->name('theme.customizer.publish');
Route::post('theme-customizer/{storeId}/discard', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeCustomizerController@discardDraft')->name('theme.customizer.discard');
Route::post('theme-customizer/{storeId}/tokens', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeCustomizerController@tokens')->name('theme.customizer.tokens');
Route::post('theme-customizer/{storeId}/contrast', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeCustomizerController@contrast')->name('theme.customizer.contrast');
Route::post('theme-customizer/{storeId}/preset', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeCustomizerController@applyPreset')->name('theme.customizer.preset');
// Phase 3: theme library — switch the store's template from inside the editor.
Route::post('theme-customizer/{storeId}/template', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeCustomizerController@setTemplate')->name('theme.customizer.setTemplate');
Route::post('theme-customizer/{storeId}/palette-from-logo', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeCustomizerController@paletteFromLogo')->name('theme.customizer.paletteFromLogo');
Route::get('theme-customizer/{storeId}/copy-targets', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeCustomizerController@copyTargets')->name('theme.customizer.copyTargets');
Route::post('theme-customizer/{storeId}/copy-to', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeCustomizerController@copyTo')->name('theme.customizer.copyTo');
Route::get('theme-customizer/{storeId}/export', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeCustomizerController@export')->name('theme.customizer.export');
Route::post('theme-customizer/{storeId}/import', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeCustomizerController@import')->name('theme.customizer.import');
Route::get('theme-customizer/{storeId}/history', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeCustomizerController@history')->name('theme.customizer.history');
Route::get('theme-customizer/{storeId}/version/{versionId}/diff', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeCustomizerController@versionDiff')->name('theme.customizer.versionDiff')->whereNumber('versionId');
Route::post('theme-customizer/{storeId}/version/{versionId}/revert', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeCustomizerController@revert')->name('theme.customizer.revert')->whereNumber('versionId');
// Page View (Mode B): upload/clear the display PDF + set the display source
Route::post('theme-customizer/{storeId}/pageview-source', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeCustomizerController@pageviewSource')->name('theme.customizer.pageviewSource');
// Folio: upload/replace/remove the flip-book PDF + set the content source
Route::post('theme-customizer/{storeId}/folio-pdf', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeCustomizerController@folioPdf')->middleware('ratelimit:upload')->name('theme.customizer.folioPdf');
// Folio: save the flip-book display options (SPEC §3 contract)
Route::post('theme-customizer/{storeId}/folio-options', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeCustomizerController@folioOptions')->name('theme.customizer.folioOptions');
// Folio Polish B3: time-based books + per-page book tags
Route::post('theme-customizer/{storeId}/folio-books', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeCustomizerController@folioBooks')->name('theme.customizer.folioBooks');
// Folio Polish B4: per-page tappable hotspots (whole-map save)
Route::post('theme-customizer/{storeId}/folio-hotspots', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeCustomizerController@folioHotspots')->name('theme.customizer.folioHotspots');
// Folio Polish B5: client-rastered PDF page images (pages[])
Route::post('theme-customizer/{storeId}/folio-pdf-pages', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeCustomizerController@folioPdfPages')->middleware('ratelimit:upload')->name('theme.customizer.folioPdfPages');
// Folio Polish B7: generate a branded cover page
Route::post('theme-customizer/{storeId}/folio-cover', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeCustomizerController@folioCover')->middleware('ratelimit:upload')->name('theme.customizer.folioCover');
// Theme Studio C1: the visual editor (rev/theme-studio/EDITOR-CONTRACT.md).
// Every endpoint is ?store=<store_id>-scoped (not the owner's → 404); writes
// go through the web group's CSRF. Ships DARK behind feature:theme_studio.
Route::prefix('dashboard/theme-studio')->name('theme.studio')->middleware('feature:theme_studio')->group(function () {
    Route::get('/', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeStudioController@page');
    Route::get('state', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeStudioController@state')->name('.state');
    Route::put('draft', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeStudioController@draft')->name('.draft');
    Route::get('section/{sid}', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeStudioController@section')->name('.section');
    Route::post('publish', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeStudioController@publish')->name('.publish');
    Route::post('discard', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeStudioController@discard')->name('.discard');
    Route::get('versions', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeStudioController@versions')->name('.versions');
    Route::post('revert/{id}', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeStudioController@revert')->name('.revert');
    Route::post('media', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeStudioController@media')->middleware('ratelimit:upload')->name('.media');
    Route::post('start', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeStudioController@start')->name('.start');
    // C4: fill the other menu languages from the default-language text (AI).
    Route::post('translate', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeStudioController@translate')->middleware('ratelimit:ai')->name('.translate');
    // Theme Studio phase D: the AI theme builder (rev/theme-studio/AI-BUILDER-CONTRACT.md).
    // Same middleware + feature:ai_theme_builder; ?store= scoped; never publishes.
    Route::prefix('ai')->name('.ai')->middleware('feature:ai_theme_builder')->group(function () {
        Route::post('generate', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeStudioAiController@generate')->middleware('ratelimit:ai')->name('.generate');
        Route::get('sets/{set}', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeStudioAiController@set')->name('.set');
        Route::get('preview/{set}/{proposal}', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeStudioAiController@preview')->name('.preview');
        Route::post('apply/{set}/{proposal}', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeStudioAiController@apply')->name('.apply');
        // E2: "Restyle this section" — a scoped CSS variant for one section (never markup).
        Route::post('restyle', '\Juwri\Modules\Storefront\Http\Controllers\User\ThemeStudioAiController@restyle')->middleware('ratelimit:ai')->name('.restyle');
    });
});
// Theme Studio phase A: owner-authored Builder.js custom blocks. Every route
// is ?store=<store_id>-scoped (not the owner's store / another store's block
// → 404). Ships DARK behind feature:custom_sections. Save is PUT (the shared
// editor posts _method=PUT).
Route::prefix('dashboard/custom-blocks')->name('custom-blocks.')->middleware('feature:custom_sections')->group(function () {
    Route::get('/', '\Juwri\Modules\Storefront\Http\Controllers\User\CustomBlockController@index')->name('index');
    Route::post('/', '\Juwri\Modules\Storefront\Http\Controllers\User\CustomBlockController@store')->name('store');
    Route::get('{id}/edit', '\Juwri\Modules\Storefront\Http\Controllers\User\CustomBlockController@edit')->whereNumber('id')->name('edit');
    Route::put('{id}', '\Juwri\Modules\Storefront\Http\Controllers\User\CustomBlockController@save')->whereNumber('id')->name('save');
    Route::post('{id}/settings', '\Juwri\Modules\Storefront\Http\Controllers\User\CustomBlockController@update')->whereNumber('id')->name('update');
    Route::post('{id}/publish', '\Juwri\Modules\Storefront\Http\Controllers\User\CustomBlockController@publish')->whereNumber('id')->name('publish');
    Route::post('{id}/unpublish', '\Juwri\Modules\Storefront\Http\Controllers\User\CustomBlockController@unpublish')->whereNumber('id')->name('unpublish');
    Route::delete('{id}', '\Juwri\Modules\Storefront\Http\Controllers\User\CustomBlockController@destroy')->whereNumber('id')->name('destroy');
    Route::get('{id}/preview', '\Juwri\Modules\Storefront\Http\Controllers\User\CustomBlockController@preview')->whereNumber('id')->name('preview');
    Route::post('{id}/template', '\Juwri\Modules\Storefront\Http\Controllers\User\CustomBlockController@template')->whereNumber('id')->name('template');
    Route::post('{id}/assets', '\Juwri\Modules\Storefront\Http\Controllers\User\CustomBlockController@assets')->whereNumber('id')->middleware('ratelimit:upload')->name('assets');
    Route::post('{id}/ai', '\Juwri\Modules\Storefront\Http\Controllers\User\CustomBlockController@ai')->whereNumber('id')->name('ai');
});

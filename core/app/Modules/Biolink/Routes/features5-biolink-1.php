<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/features5/biolink.php (slot Biolink/features5-biolink-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Biolink\Http\Controllers\User\BioInsightsController;

Route::middleware([
    'web', 'app.locale', 'auth', 'staff.dashboard', 'dashboard.sync', 'csp.dashboard',
    'reseller.suspension', 'abuse.hold', 'demo', 'feature:biolink',
])
    ->controller('\Juwri\Modules\Biolink\Http\Controllers\User\BioLinkController')
    ->name('biolink.')
    ->prefix('biolink')
    ->group(function () {
        // Settings
        Route::get('/', 'index')->name('index');
        Route::post('/settings', 'saveSettings')->name('settings.save');

        // Builder + block CRUD. csp.dashboard on the builder GET because it also
        // renders the shared Builder.js editor when the store is in Advanced HTML
        // mode (Builder.js needs unsafe-eval + nonce; report-only by default, so it
        // is behaviourally invisible on the block editor). See the html.* group below.
        Route::get('/builder', 'builder')->middleware('csp.dashboard')->name('builder');
        Route::post('/blocks', 'storeBlock')->name('blocks.store');
        Route::post('/blocks/reorder', 'reorderBlocks')->name('blocks.reorder');
        Route::post('/blocks/{id}', 'updateBlock')->whereNumber('id')->name('blocks.update');
        Route::post('/blocks/{id}/toggle', 'toggleBlock')->whereNumber('id')->name('blocks.toggle');
        Route::delete('/blocks/{id}', 'destroyBlock')->whereNumber('id')->name('blocks.destroy');

        // Advanced HTML (Builder.js) mode — an alternative authoring surface for the
        // bio page ALONGSIDE the block builder above. Same feature:biolink kill-switch
        // + staff.dashboard permission + demo write-block as the block CRUD it lives
        // next to, PLUS csp.dashboard so Builder.js's runtime eval is permitted
        // (unsafe-eval + nonce) — mirrors the reseller PageBuilder editor. Every action
        // is tenant-scoped in BioLinkController via currentRestaurant() (403 off-tenant).
        // The editor's Save is a POST with _method=PUT, so html.save is a PUT route.
        Route::middleware('csp.dashboard')->group(function () {
            Route::post('/html/enable', 'htmlEnable')->name('html.enable');
            Route::post('/html/disable', 'htmlDisable')->name('html.disable');
            Route::put('/html', 'htmlSave')->name('html.save');
            Route::post('/html/publish', 'htmlPublish')->name('html.publish');
            Route::get('/html/preview', 'htmlPreview')->name('html.preview');
            Route::post('/html/template', 'htmlSetTemplate')->name('html.template');
            Route::post('/html/assets', 'htmlUploadAsset')->name('html.assets');
            Route::post('/html/ai', 'htmlAi')->name('html.ai');
        });

        // Insights
        Route::get('/insights', 'insights')->name('insights');

        // bio-t15 lane-d START — D4 CSV export. Same route-level gate as `insights`
        // above (this group's feature:biolink + tenant scoping); BioInsightsController
        // additionally checks the bio_analytics_pro PRO entitlement server-side. A
        // plain array action (not the group's ->controller(BioLinkController::class))
        // so Lane D never edits BioLinkController.
        Route::get('/insights/export', [BioInsightsController::class, 'export'])->name('insights.export');
        // bio-t15 lane-d END

        // biolink-tpl lane-a — starter templates (T1-2). Same group (feature:biolink
        // kill-switch + staff.dashboard permission + demo write-block) as the block
        // CRUD it seeds, so no gate is looser than the builder it lands in.
        // F0-merge: this is the single real `template.apply` (calls BioLinkService::
        // applyStarter). Lane C's isolated duplicate was dropped; its copy-to route is
        // folded in below.
        Route::post('/template/apply', 'applyTemplate')->name('template.apply');
        Route::post('/preset', 'setPreset')->name('preset.set'); // live design switcher (skin only)
        Route::post('/copy-to', 'copyTo')->name('copy-to'); // biolink-tpl lane-c (T2-5)
        // end biolink-tpl lane-a/lane-c
    });

// biolink-tpl lane-b: AI generator (T1-4) + block translate (T2-2). Same
// auth/tenant/demo stack as the dashboard group above, PLUS the
// `feature:ai_biolink` kill-switch that gates BOTH AI surfaces. Endpoints live
// on BioTemplateController (a separate controller so this run never edits Lane
// C's BioLinkController). Staff ride the same `biolink` permission (the
// `biolink.*` prefix rule in EnsureStaffDashboardPermission). One contiguous
// block per the run's shared-file rule.
Route::middleware([
    'web', 'app.locale', 'auth', 'staff.dashboard', 'dashboard.sync', 'csp.dashboard',
    'reseller.suspension', 'abuse.hold', 'demo', 'feature:biolink', 'feature:ai_biolink',
])
    ->controller('\Juwri\Modules\Biolink\Http\Controllers\User\BioTemplateController')
    ->name('biolink.')
    ->prefix('biolink')
    ->group(function () {
        // T1-4 · AI page generator (propose → review → apply)
        Route::post('/template/generate', 'generate')->name('template.generate');
        Route::post('/template/generate/apply', 'applyGenerated')->name('template.generate.apply');

        // T2-2 · AI block translate (dispatch heavy job → poll progress)
        Route::post('/translate', 'translate')->name('translate');
        Route::get('/translate/progress', 'translateProgress')->name('translate.progress');
    });

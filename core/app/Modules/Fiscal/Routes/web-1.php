<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Fiscal/web-1); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// TAX-RUN: fiscalization console (document log + ZATCA onboarding +
            // driver credentials). Behind the fiscalization master kill-switch;
            // per-country switches enforced in the controller.
            Route::controller('\Juwri\Modules\Fiscal\Http\Controllers\User\FiscalController')->name('fiscal.')->prefix('fiscal')->middleware('feature:fiscalization')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('documents/{id}/resubmit', 'resubmit')->name('documents.resubmit');
                Route::post('zatca/onboard', 'zatcaOnboard')->name('zatca.onboard');
                Route::post('zatca/renew', 'zatcaRenew')->name('zatca.renew');
                Route::post('credentials', 'saveCredentials')->name('credentials');
                Route::post('eta/device', 'registerEtaDevice')->name('eta.device');
            });

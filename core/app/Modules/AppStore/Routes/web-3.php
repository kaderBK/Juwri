<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot AppStore/web-3); included in place by ModuleRoutes::slot().
// P1-13 13h: the partner dashboard routes around this one moved to ee (slots AppStore/ee-web-3, AppStore/ee-web-4);
// routes/web.php keeps their owner-only staff.dashboard group. Re-consent to an installed app's new scopes is Community.
use Illuminate\Support\Facades\Route;

Route::post('user/store/apps/{app}/reconsent', '\Juwri\Modules\AppStore\Http\Controllers\User\AppsController@reconsent')->middleware('auth')->name('user.store.apps.reconsent')->whereNumber('app'); // P5-2 feature-platform

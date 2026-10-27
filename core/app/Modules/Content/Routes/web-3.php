<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Content/web-3); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// Locked templates: superadmin-authored page templates that users edit only via typed slots.
        Route::get('/locked-templates', '\Juwri\Modules\Content\Http\Controllers\Admin\LockedTemplateController@index')->name('locked-templates.index');
        Route::get('/locked-templates/{id}/preview', '\Juwri\Modules\Content\Http\Controllers\Admin\LockedTemplateController@preview')->whereNumber('id')->name('locked-templates.preview');
        Route::post('/locked-templates/{id}/status', '\Juwri\Modules\Content\Http\Controllers\Admin\LockedTemplateController@setStatus')->whereNumber('id')->name('locked-templates.status');
        Route::post('/locked-templates/{id}/current', '\Juwri\Modules\Content\Http\Controllers\Admin\LockedTemplateController@setCurrent')->whereNumber('id')->name('locked-templates.current');
        Route::get('/locked-templates/{id}/versions/new', '\Juwri\Modules\Content\Http\Controllers\Admin\LockedTemplateController@newVersion')->whereNumber('id')->name('locked-templates.versions.create');
        Route::post('/locked-templates/{id}/versions', '\Juwri\Modules\Content\Http\Controllers\Admin\LockedTemplateController@storeVersion')->whereNumber('id')->name('locked-templates.versions.store');
        Route::post('/locked-templates/permissions/defaults', '\Juwri\Modules\Content\Http\Controllers\Admin\LockedTemplateController@saveDefaults')->name('locked-templates.defaults');
        Route::post('/locked-templates/permissions/user', '\Juwri\Modules\Content\Http\Controllers\Admin\LockedTemplateController@saveUser')->name('locked-templates.permissions.user');
        Route::post('/locked-templates/permissions/find-user', '\Juwri\Modules\Content\Http\Controllers\Admin\LockedTemplateController@findUser')->name('locked-templates.permissions.find');

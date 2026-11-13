<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Content/web-1); included in place by ModuleRoutes::slot().
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Content\Http\Controllers\BuilderFormController;

// DOCS-PLATFORM D1-1: public Help Center over resources/docs/users (slug
// pattern keeps README and any non-kebab file unreachable). Registered before
// BOTH the `{store}/gift-cards` wildcard below (which would capture
// 'help-center' as a restaurant) and the `{slug}` catch-all. NOTE: /help
// belongs to the support desk (POST help + GET help/{id} ticket threads),
// so the knowledge base lives at /help-center.
Route::get('help-center', '\Juwri\Modules\Content\Http\Controllers\HelpCenterController@index')->name('help.index');
// D1-5/D6-1: search (logs query + zero-results) — before the {slug} route.
Route::get('help-center/search', '\Juwri\Modules\Content\Http\Controllers\HelpCenterController@search')->name('help.search');
// D6-1: was-this-helpful signal (public, CSRF-exempt — see PreventRequestForgery).
Route::post('docs/feedback', '\Juwri\Modules\Content\Http\Controllers\HelpCenterController@feedback')->name('help.feedback')->middleware('throttle:20,1,docs-feedback');
// D6-3: docs-grounded AI assistant (dark by default; 404 while off). Throttled.
Route::post('help-center/ask', '\Juwri\Modules\Content\Http\Controllers\HelpCenterController@ask')->middleware(['throttle:20,1,content-help-center-ask', 'storecap:help_ai'])->name('help.ask');
// Builder.js page forms — public curated contact/lead submit. CSRF-exempt via
// ->withoutMiddleware (never touching the shared PreventRequestForgery::$except), so a
// page with a form stays full-page-cacheable; abuse is stopped in-controller
// (honeypot + min-fill-time + per-IP rate limit). Before the {slug} catch-all.
Route::post('builder/form', [BuilderFormController::class, 'submit'])
    ->middleware('throttle:20,1,content-builder-form')
    ->withoutMiddleware(PreventRequestForgery::class)
    ->name('builder.form.submit');
// D6-4: changelog surface (HTML + JSON + RSS, product-filterable, brand-aware).
Route::get('changelog', '\Juwri\Modules\Content\Http\Controllers\ChangelogController@index')->name('changelog');
Route::get('changelog.json', '\Juwri\Modules\Content\Http\Controllers\ChangelogController@json')->name('changelog.json');
Route::get('changelog.rss', '\Juwri\Modules\Content\Http\Controllers\ChangelogController@rss')->name('changelog.rss');
// D2-1: raw .md (AI agents) registered before the HTML {slug} route.
Route::get('help-center/{slug}.md', '\Juwri\Modules\Content\Http\Controllers\HelpCenterController@raw')->where('slug', '[a-z0-9-]+')->name('help.raw');
Route::get('help-center/{slug}', '\Juwri\Modules\Content\Http\Controllers\HelpCenterController@show')->where('slug', '[a-z0-9-]+')->name('help.show');

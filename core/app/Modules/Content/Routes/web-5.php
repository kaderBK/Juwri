<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Content/web-5); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;

// Menu Studio — PUBLIC, no signup. MUST be registered before the {slug} catch-all
// (a single-segment '/studio' would otherwise resolve as a restaurant slug).
// XML sitemap for the public marketing pages (robots.txt points here).
// Single-segment path — MUST also be registered before the {slug} catch-all.
// P6-248: dynamic robots.txt (noindex off production, this host's sitemap).
Route::get('robots.txt', '\Juwri\Modules\Content\Http\Controllers\RobotsController')->name('robots');
Route::get('sitemap.xml', '\Juwri\Modules\Content\Http\Controllers\SitemapController@index')->name('sitemap');
Route::get('sitemap-marketing.xml', '\Juwri\Modules\Content\Http\Controllers\SitemapController@marketing')->name('sitemap.marketing');
Route::get('sitemap-blog.xml', '\Juwri\Modules\Content\Http\Controllers\SitemapController@blog')->name('sitemap.blog');
Route::get('sitemap-fusion.xml', '\Juwri\Modules\Content\Http\Controllers\SitemapController@fusion')->name('sitemap.fusion');
Route::get('sitemap-restaurants.xml', '\Juwri\Modules\Content\Http\Controllers\SitemapController@stores')->name('sitemap.restaurants');
Route::get('sitemap-restaurants-{page}.xml', '\Juwri\Modules\Content\Http\Controllers\SitemapController@stores')->whereNumber('page')->name('sitemap.restaurants.page'); // P6-306
// DIRECTORY L1 T1-4: explore city/cuisine pages + unclaimed biz pages (when
// indexable). Always registered — the section is simply empty while the
// module is off (SitemapService::directoryEntries() checks the gate itself).
Route::get('sitemap-directory.xml', '\Juwri\Modules\Content\Http\Controllers\SitemapController@directory')->name('sitemap.directory');

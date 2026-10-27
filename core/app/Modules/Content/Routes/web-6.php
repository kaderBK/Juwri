<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-09: moved from routes/web.php (slot Content/web-6); included in place by ModuleRoutes::slot().
use Illuminate\Support\Facades\Route;
use Juwri\Core\Http\Middleware\AddCspHeaders;
use Juwri\Core\Support\Csp\StudioSharePolicy;

// AI-heavy guest endpoints are rate-limited (abuse / cost control).
Route::post('studio/upload', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@upload')->middleware(['throttle:20,1,content-studio-upload', 'ratelimit:upload'])->name('studio.upload');
Route::post('studio/command', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@command')->middleware(['throttle:30,1,content-studio-command', 'ratelimit:ai'])->name('studio.command');
// Publishing an online-ordering menu requires an account (Google login gate).
Route::post('studio/publish', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@publish')->middleware(['auth', 'staff.dashboard'])->name('studio.publish'); // owner-only: staff session = owner web guard
// PDF-styled generation (Nano Banana) + clean download — credit-gated, guest OK.
Route::post('studio/generate-design', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@generateDesign')->middleware(['throttle:10,1,content-studio-generate-design', 'ratelimit:ai'])->name('studio.design');
Route::get('studio/design/download', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@downloadDesign')->middleware('throttle:60,1,content-studio-design-download')->name('studio.design.download');
// Inline preview of the generated design (cookie-scoped to the owning session).
Route::get('studio/design/preview', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@previewDesign')->middleware('throttle:60,1,content-studio-design-preview')->name('studio.design.preview');
// studio-templates P1-5: HTML template gallery + free draft-bound preview (text
// stays data — rev/studio-templates spec §0). Preview renders localized strings.
Route::get('studio/templates', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@templates')->middleware('throttle:60,1,content-studio-templates')->name('studio.templates');
Route::get('studio/template/preview', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@templatePreview')->middleware(['app.locale', 'throttle:60,1,content-studio-template-preview'])->name('studio.template.preview');
Route::get('studio/template/thumb/{slug}', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@templateThumb')->middleware('throttle:60,1,content-studio-template-thumb')->name('studio.template.thumb');
Route::post('studio/template/options', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@setTemplateOptions')->middleware('throttle:60,1,content-studio-template-options')->name('studio.template.options');
Route::post('studio/template/export', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@exportTemplate')->middleware(['app.locale', 'throttle:studio-export', 'ratelimit:ai'])->name('studio.template.export');
Route::post('studio/template/fit', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@fitTemplate')->middleware(['app.locale', 'throttle:studio-fit', 'ratelimit:ai'])->name('studio.template.fit');
Route::post('studio/template/background', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@templateBackground')->middleware(['app.locale', 'throttle:studio-export', 'ratelimit:ai'])->name('studio.template.bg');
// Guest privacy: purge my session + files now + export my data (GDPR).
Route::post('studio/forget', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@forget')->middleware('throttle:30,1,content-studio-forget')->name('studio.forget');
Route::get('studio/export', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@export')->middleware(['throttle:30,1,content-studio-export', 'ratelimit:export'])->name('studio.export');
// SI7 #60/#61: transparent pricing (balance, costs, bundles).
Route::get('studio/pricing', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@pricing')->name('studio.pricing');
// SI9 #62: referral credits.
Route::post('studio/referral', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@applyReferral')->middleware('throttle:10,1,content-studio-referral')->name('studio.referral');
// Stripe top-up checkout + webhook (the exact callback path is CSRF-exempt).
Route::post('studio/checkout', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@checkout')->middleware('throttle:20,1,content-studio-checkout')->name('studio.checkout');
Route::post('studio/stripe-webhook', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@stripeWebhook')->middleware('throttle:120,1,content-studio-stripe-webhook')->name('studio.stripe.webhook');
// SI1 #2: public read-only watermarked share preview (public share_token, never
// the guest credential) + minting the share link / referral code.
Route::get('studio/s/{token}', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@share')->middleware(['throttle:60,1,content-studio-s', AddCspHeaders::class.':'.StudioSharePolicy::class])->name('studio.share'); // P6-122b: scriptless policy that lets the srcdoc design keep its inline styles
Route::post('studio/share-link', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@shareLink')->middleware('throttle:30,1,content-studio-share-link')->name('studio.sharelink');
// SI4 #28: autosave the reviewed draft (guest). SI10 #70: rollback one step.
Route::post('studio/save-draft', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@saveDraft')->middleware('throttle:60,1,content-studio-save-draft')->name('studio.savedraft');
Route::post('studio/rollback', '\Juwri\Modules\Content\Http\Controllers\Studio\StudioController@rollbackDraft')->middleware('throttle:60,1,content-studio-rollback')->name('studio.rollback');

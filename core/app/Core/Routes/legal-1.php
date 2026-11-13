<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-23: slot Core/legal-1 — inside the localized frontend group (so /ar/terms works in locale-prefix mode and the
// session locale picks the Arabic text otherwise), before the {slug} storefront catch-all (paths are reserved slugs).
// Registered always; the controller 404s unless the instance publishes the legal pages (LEGAL_PAGES) — except the
// cookie list (/cookies), which every install shows (it lists what this software sets, not JUWRI LTD's terms).
use Illuminate\Support\Facades\Route;
use Juwri\Core\Http\Controllers\LegalController;
use Juwri\Core\Legal\LegalDocuments;

foreach (LegalDocuments::all() as $legalKey => $legalDoc) {
    Route::get($legalDoc['path'], [LegalController::class, 'show'])
        ->defaults('document', $legalKey)
        ->name(LegalDocuments::routeName($legalKey));
}

Route::get('cookies', [LegalController::class, 'cookies'])->name('legal.cookies');

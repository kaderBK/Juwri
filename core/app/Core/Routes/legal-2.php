<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-23: slot Core/legal-2 — inside the owner dashboard group (auth): accept the changed legal documents.
use Illuminate\Support\Facades\Route;
use Juwri\Core\Http\Controllers\LegalController;

Route::post('legal/accept', [LegalController::class, 'accept'])->middleware('throttle:20,1,core-legal-accept')->name('legal.accept');

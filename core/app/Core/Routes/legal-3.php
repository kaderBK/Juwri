<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// P1-23: slot Core/legal-3 — RFC 9116 security.txt (+ the PGP key it points at), outside the localized group.
use Illuminate\Support\Facades\Route;
use Juwri\Core\Http\Controllers\LegalController;

Route::get('.well-known/security.txt', [LegalController::class, 'securityTxt'])->name('legal.security-txt');
Route::get('.well-known/pgp-key.txt', [LegalController::class, 'pgpKey'])->name('legal.pgp-key');

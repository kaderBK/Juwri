<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD
/*
 * ZERO-TOUCH Track B — storefront speech-to-text for the agent chat widget.
 * Owned exclusively by Track B. Auto-required by the features5 glob in web.php.
 * FQCN array actions avoid namespace prefixing.
 *
 * Seam contract (KICKOFF.md): Track A renders its MediaRecorder fallback mic
 * only when Route::has('agent.stt') — this file lighting up enables it.
 * Multipart `audio` blob (webm/mp4/m4a/ogg/wav, <=60s, <=2MB) -> {"text"} or
 * 422 {"error"}. Throttled per browser session; spend-guarded via AiService.
 */

use Illuminate\Support\Facades\Route;
use Juwri\Modules\Agents\Http\Controllers\AgentSttController;

Route::post('{store}/agent/stt', [AgentSttController::class, 'transcribe'])
    ->middleware(['throttle:agent-stt', 'ratelimit:ai'])
    ->name('agent.stt');

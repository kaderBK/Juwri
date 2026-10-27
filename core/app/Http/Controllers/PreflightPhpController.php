<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers;

use App\Console\Commands\JuwriPreflightCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * P6-123: answers `juwri:preflight` with the web SAPI's PHP version. One-shot: the token is issued by the
 * command (5 min), sent in a header, and consumed on first use. Without a valid token it is a plain 404.
 */
class PreflightPhpController
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) $request->header(JuwriPreflightCommand::TOKEN_HEADER, '');
        $expected = Cache::get(JuwriPreflightCommand::TOKEN_CACHE_KEY);
        if ($token === '' || ! is_string($expected) || ! hash_equals($expected, hash('sha256', $token))) {
            abort(404);
        }
        Cache::forget(JuwriPreflightCommand::TOKEN_CACHE_KEY);

        return response()->json(['php' => PHP_VERSION, 'sapi' => PHP_SAPI])->header('Cache-Control', 'no-store');
    }
}

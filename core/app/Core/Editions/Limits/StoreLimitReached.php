<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions\Limits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A store or branch past the edition's limit was refused ({@see StoreLimitGuard}). Nothing is written (the model
 * event aborts the insert; callers' transactions roll back). Rendered as the limit message: 422 JSON for API / XHR
 * callers, otherwise back to the form with the message (toast + `store_limit` flash for the page's notice).
 */
final class StoreLimitReached extends RuntimeException
{
    public function __construct(public readonly LimitDecision $decision)
    {
        parent::__construct($decision->message());
    }

    /** An expected refusal, not an error: nothing to log. */
    public function report(): void {}

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        $message = $this->decision->message();
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'error' => $message,
                'message' => $message,
                'code' => 'store_limit',
                'limit' => $this->decision->toArray(),
            ], 422);
        }
        app_alert_error($message);

        return back()->withInput()->with('store_limit', $this->decision->toArray() + ['message' => $message]);
    }
}

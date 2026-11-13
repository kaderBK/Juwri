<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Models\EmailChangeUndo;
use Juwri\Modules\Auth\Services\EmailChangeUndoService;

/**
 * BL-036 A1a: the guest page behind "This wasn't me — undo". Routes carry
 * `signed:relative` + throttle. GET only renders a confirm page (no state change, so a
 * mail scanner pre-fetching the link does nothing); POST (CSRF) performs the undo.
 */
class EmailChangeUndoController extends Controller
{
    public function __construct(private EmailChangeUndoService $undos) {}

    public function show(Request $request, int $undo): Response
    {
        $row = $this->undos->resolve($undo, (string) $request->query('token', ''));
        if (! $row) {
            return $this->page($request, 'invalid', null, 404);
        }
        $state = $this->undos->state($row);
        if ($state === 'ok' && $this->undos->oldEmailTaken($row)) {
            $state = 'taken';
        }

        return $this->page($request, $state === 'ok' ? 'confirm' : $state, $row, $state === 'ok' ? 200 : 410);
    }

    public function perform(Request $request, int $undo): Response
    {
        $row = $this->undos->resolve($undo, (string) $request->query('token', ''));
        if (! $row) {
            return $this->page($request, 'invalid', null, 404);
        }
        $result = $this->undos->perform($row, $request->ip());

        return $this->page($request, $result, $row->fresh() ?? $row, match ($result) {
            'done' => 200,
            'taken' => 409,
            default => 410,
        });
    }

    private function page(Request $request, string $state, ?EmailChangeUndo $row, int $status): Response
    {
        $this->useAccountLocale($row ? User::find($row->user_id) : null);

        return response()->view('account.email-undo', [
            'state' => $state,
            'undo' => $row,
            'brand' => EmailChangeUndoService::brand($row),
            'contact' => $row ? EmailChangeUndoService::supportContact($row) : '',
            // POST back to the exact signed path + query that was opened (relative, same host).
            'action' => $state === 'confirm' ? $request->getRequestUri() : null,
        ], $status)
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Cache-Control', 'no-store');
    }

    private function useAccountLocale(?User $user): void
    {
        $code = $user?->preferred_locale;
        if ($code && Language::where('code', $code)->where('active', 1)->exists()) {
            app()->setLocale((string) $code);
        }
    }
}

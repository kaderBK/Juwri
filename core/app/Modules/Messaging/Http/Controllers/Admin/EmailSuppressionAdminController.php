<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Messaging\Models\EmailSuppression;

/** P6-249: platform admin list of suppressed addresses (admin route group), with manual removal. */
class EmailSuppressionAdminController extends Controller
{
    public function index(Request $request): View
    {
        $term = trim((string) $request->query('q', ''));
        $reason = (string) $request->query('reason', '');

        $query = EmailSuppression::query()->orderByDesc('last_event_at')->orderByDesc('id');
        if ($term !== '') {
            $query->where('email', 'like', '%'.addcslashes(EmailSuppression::normalize($term), '%_\\').'%');
        }
        if (in_array($reason, [EmailSuppression::BOUNCE, EmailSuppression::COMPLAINT], true)) {
            $query->where('reason', $reason);
        } else {
            $reason = '';
        }

        return view('admin.email-suppressions.index', [
            'rows' => $query->paginate(50)->withQueryString(),
            'term' => $term,
            'reason' => $reason,
        ]);
    }

    public function destroy(int $suppression): RedirectResponse
    {
        $row = EmailSuppression::query()->findOrFail($suppression);
        log_activity('email_suppression.removed', null, ['email' => $row->email, 'reason' => $row->reason]);
        $row->delete();

        return back()->with('success', ___('Address removed from the suppression list.'));
    }
}

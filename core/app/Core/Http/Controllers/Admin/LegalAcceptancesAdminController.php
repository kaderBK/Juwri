<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\LegalAcceptance;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Tenancy\TenantContext;

/**
 * P1-23: read-only list of the legal document versions customers accepted (user, document, version, source, time, IP as
 * stored), filterable by user. Superadmin only (the admin route group). Nothing here writes or removes a record.
 */
class LegalAcceptancesAdminController extends Controller
{
    public function index(Request $request): View
    {
        $term = trim((string) $request->query('user', ''));

        $rows = TenantContext::bypass(function () use ($term) {
            $query = LegalAcceptance::query()->orderByDesc('accepted_at')->orderByDesc('id');

            if ($term !== '') {
                $ids = User::query()
                    ->where(fn ($q) => \App\Support\SearchTerm::applyOrNone($q, ['name', 'email', 'username'], $term))
                    ->limit(500)->pluck('id')->all();
                if (ctype_digit($term)) {
                    $ids[] = (int) $term;
                }
                $query->whereIn('user_id', $ids === [] ? [0] : $ids);
            }

            $page = $query->paginate(50)->withQueryString();
            $users = User::query()->whereIn('id', $page->pluck('user_id')->unique()->all())->get(['id', 'name', 'email'])->keyBy('id');

            return [$page, $users];
        });

        return view('admin.legal-acceptances.index', [
            'acceptances' => $rows[0],
            'users' => $rows[1],
            'term' => $term,
        ]);
    }
}

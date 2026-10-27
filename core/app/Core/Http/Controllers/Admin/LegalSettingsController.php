<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Legal\LegalSettings;
use Juwri\Core\Policies\Admin\PanelAbilities;

/**
 * J51b: Admin > Settings > Legal & compliance. Superadmin only, exactly like the other settings pages: the route sits in
 * the `admin` middleware group (guests and non-admins are sent to sign-in) and this constructor adds the same
 * `admin.settings` gate as Admin\SettingsController (403 for anyone the gate refuses). No auth logic of its own.
 *
 * The form edits the owner facts ({{FILL:key}}) and product promises ({{VERIFY:key}}) behind the website legal pages,
 * persisted through {@see LegalSettings} into the platform settings store (env LEGAL_* stays the fallback).
 */
class LegalSettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            Gate::authorize(PanelAbilities::SETTINGS);

            return $next($request);
        });
    }

    public function edit(): View
    {
        return view('admin.legal-settings.edit', [
            'groups' => LegalSettings::groups(),
            'effective' => LegalSettings::effective(),
            'status' => LegalSettings::status(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [
            'fill' => ['sometimes', 'array'],
            'verify' => ['sometimes', 'array'],
            'unused' => ['sometimes', 'array'],
        ];
        foreach (array_keys((array) config('legal.fill', [])) as $key) {
            $rules['fill.'.$key] = ['nullable', 'string', 'max:500'];
        }
        foreach (array_keys((array) config('legal.verify', [])) as $key) {
            $rules['verify.'.$key] = ['nullable', 'in:0,1'];
        }
        foreach (LegalSettings::SUBPROCESSORS as $name) {
            $rules['unused.'.$name] = ['nullable', 'in:0,1'];
        }

        $data = $request->validate($rules);

        $changes = LegalSettings::save(
            (array) ($data['fill'] ?? []),
            (array) ($data['verify'] ?? []),
            (array) ($data['unused'] ?? []),
        );

        log_activity('legal.settings_update', null, ['changes' => $changes, 'count' => count($changes)]);

        app_alert_success(___('Legal & compliance settings saved'));

        return redirect()->route('admin.settings.legal');
    }
}

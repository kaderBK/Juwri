<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers\Admin;

use App\Http\Requests\Admin\SiteTemplateRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Policies\Admin\PanelAbilities;

/**
 * Admin › Templates: pick which installed site template the public pages use.
 * The choice is written to THEME_NAME in the environment file.
 */
class TemplateController extends Controller
{
    public function index(): View
    {
        $this->authorize(PanelAbilities::SITE_TEMPLATE);

        $templates = array_map(fn (string $name) => [
            'name' => $name,
            'image' => asset('assets/templates/'.$name.'/preview.jpg'),
        ], SiteTemplateRequest::installed());

        return view('admin.templates.index', [
            'templates' => $templates,
            'extra_templates' => null,
        ]);
    }

    public function templatesActive(SiteTemplateRequest $request): RedirectResponse
    {
        set_env('THEME_NAME', $request->validated('name'));
        app_alert_success(___('Updated successfully'));

        return back();
    }
}

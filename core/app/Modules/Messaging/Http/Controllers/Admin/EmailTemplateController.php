<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Http\Controllers\Admin;

use Juwri\Core\Http\Controllers\Admin\Concerns\AnswersAdminPanel;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Messaging\Http\Requests\Admin\MailTemplatesRequest;
use Juwri\Core\Policies\Admin\PanelAbilities;
use Juwri\Modules\Messaging\Support\MailTemplateCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Juwri\Core\Settings\PlatformSettings;

/** Admin › E-mail templates: subject and body of each transactional e-mail. */
class EmailTemplateController extends Controller
{
    use AnswersAdminPanel;

    public function index(): View
    {
        $this->authorize(PanelAbilities::MAIL_TEMPLATES);

        return view('admin.mailtemplates.index', [
            'default_shortcodes' => MailTemplateCatalog::commonShortcodes(),
            'email_template' => MailTemplateCatalog::templates(),
        ]);
    }

    public function update(MailTemplatesRequest $request): JsonResponse
    {
        $this->authorize(PanelAbilities::MAIL_TEMPLATES);

        foreach ($request->settings() as $name => $value) {
            PlatformSettings::set($name, $value);
        }

        return $this->panelDone(___('Updated Successfully'));
    }
}

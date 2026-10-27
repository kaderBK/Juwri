<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Requests\Admin;

use Juwri\Core\Policies\Admin\PanelAbilities;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Switch the public site template; only an installed template folder is accepted. */
class SiteTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(PanelAbilities::SITE_TEMPLATE);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', Rule::in(self::installed())],
        ];
    }

    /** @return list<string> template folder names under resources/views/templates */
    public static function installed(): array
    {
        $dirs = glob(resource_path('views/templates/*'), GLOB_ONLYDIR) ?: [];

        return array_map('basename', $dirs);
    }
}

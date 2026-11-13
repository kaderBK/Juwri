<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Requests\Admin;

use Juwri\Core\Models\Language;
use Illuminate\Validation\Rule;

/**
 * Add / edit a site language from the side panel. The code names the
 * translation folder (`lang/<code>`), so it is limited to a locale-like
 * token and fixed once the language exists.
 */
class LanguageRequest extends PanelFormRequest
{
    /** Locale-like folder name: `en`, `pt-BR`, `zh_Hant`. */
    public const CODE_PATTERN = '/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})?$/';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'direction' => ['required', Rule::in(['ltr', 'rtl'])],
        ];

        if ($this->route('language') instanceof Language) {
            $rules['active'] = ['required', 'boolean'];
        } else {
            $rules['code'] = ['required', 'string', 'min:2', 'max:10', 'regex:'.self::CODE_PATTERN, Rule::unique('languages', 'code')];
        }

        return $rules;
    }

    public function wantsDefault(): bool
    {
        return $this->boolean('is_default');
    }
}

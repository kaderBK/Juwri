<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Http\Requests\Admin;

use Juwri\Core\Http\Requests\Admin\PanelFormRequest;

/** Create / edit a catalogue allergen (admin side panel). */
class AllergyRequest extends PanelFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg'],
            'active' => ['required', 'boolean'],
            'translations' => ['nullable', 'array'],
        ];
    }
}

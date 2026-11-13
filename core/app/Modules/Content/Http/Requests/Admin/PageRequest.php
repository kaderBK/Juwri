<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Http\Requests\Admin;

use Juwri\Modules\Content\Models\Page;
use Illuminate\Validation\Rule;
use Juwri\Core\Http\Requests\Admin\PanelFormRequest;

/**
 * Create / edit a CMS page from the side panel. A page built in the visual
 * builder keeps its HTML there, so its panel form edits the metadata only
 * and `page_content` is neither required nor saved.
 */
class PageRequest extends PanelFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $current = $this->route('page');
        $current = $current instanceof Page ? $current : null;

        $rules = [
            'page_title' => ['required', 'string', 'min:2', 'max:255'],
            'slug' => ['nullable', 'alpha_dash', Rule::unique('pages', 'slug')->ignore($current?->getKey())],
            'type' => ['required', Rule::in(['0', '1'])],
            'translation_lang' => ['nullable', 'string', Rule::exists('languages', 'code')],
            'active' => ['required', 'boolean'],
        ];

        if (! $this->keepsBuilderContent()) {
            $rules['page_content'] = ['required', 'string', 'min:5'];
        }

        return $rules;
    }

    public function keepsBuilderContent(): bool
    {
        $current = $this->route('page');

        return $current instanceof Page && $current->isBuilderMode();
    }
}

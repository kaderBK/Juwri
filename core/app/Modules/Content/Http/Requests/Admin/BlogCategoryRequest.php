<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Http\Requests\Admin;

use Juwri\Modules\Content\Models\BlogCategory;
use Illuminate\Validation\Rule;
use Juwri\Core\Http\Requests\Admin\PanelFormRequest;

/** Create / edit a blog category; an empty slug is made from the title. */
class BlogCategoryRequest extends PanelFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $current = $this->route('category');

        return [
            'title' => ['required', 'string', 'min:2', 'max:255'],
            'slug' => [
                'nullable', 'alpha_dash',
                Rule::unique('blog_categories', 'slug')->ignore($current instanceof BlogCategory ? $current->getKey() : null),
            ],
            'active' => ['nullable', 'boolean'],
        ];
    }
}

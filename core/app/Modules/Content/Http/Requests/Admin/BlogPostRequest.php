<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Http\Requests\Admin;

use Juwri\Modules\Content\Models\Blog;
use Illuminate\Validation\Rule;
use Juwri\Core\Http\Requests\Admin\FullPageFormRequest;

/** Write / edit a marketing-site blog post. A new post needs a cover image. */
class BlogPostRequest extends FullPageFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $current = $this->route('blog');
        $editing = $current instanceof Blog;

        return [
            'title' => ['required', 'string', 'min:2', 'max:255'],
            'slug' => ['nullable', 'alpha_dash', Rule::unique('blog_posts', 'slug')->ignore($editing ? $current->getKey() : null)],
            'description' => ['required', 'string'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'locale' => ['nullable', 'string', 'max:8'],
            'translation_group' => ['nullable', 'string', 'max:40'],
            'image' => [$editing ? 'nullable' : 'required', 'file', 'mimes:png,jpg,jpeg', 'max:2048'],
            'status' => ['required', Rule::in(['publish', 'pending'])],
            'category' => ['required', 'array'],
            'category.*' => ['integer', Rule::exists('blog_categories', 'id')],
            'tags' => ['nullable', 'string'],
        ];
    }

    /** @return list<int> */
    public function categoryIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->validated('category'))));
    }
}

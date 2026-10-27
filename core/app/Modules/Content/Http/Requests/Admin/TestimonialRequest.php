<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Http\Requests\Admin;

use Juwri\Core\Http\Requests\Admin\PanelFormRequest;

/** Create / edit a marketing-site testimonial (admin side panel). */
class TestimonialRequest extends PanelFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'designation' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg'],
            'translations' => ['nullable', 'array'],
        ];
    }
}

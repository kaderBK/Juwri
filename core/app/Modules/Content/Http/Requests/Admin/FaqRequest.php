<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Http\Requests\Admin;

use Juwri\Core\Http\Requests\Admin\PanelFormRequest;

/** Create / edit an FAQ entry; an empty language means "shown for every language". */
class FaqRequest extends PanelFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'faq_title' => ['required', 'string', 'max:255'],
            'faq_content' => ['required', 'string'],
            'translation_lang' => ['nullable', 'string', 'exists:languages,code'],
            'active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function faqAttributes(): array
    {
        return [
            'faq_title' => $this->validated('faq_title'),
            'faq_content' => $this->validated('faq_content'),
            'translation_lang' => $this->validated('translation_lang') ?: null,
            'active' => $this->boolean('active'),
        ];
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Requests\Admin;


/**
 * The translate screen: `translates[<file>][<key>]` (one level of nested
 * keys allowed) for the PHP files of one language folder.
 */
class TranslationsRequest extends FullPageFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'translates' => ['required', 'array'],
            'translates.*' => ['array'],
        ];
    }

    /** @return array<string, array<mixed>> file name => strings */
    public function translationFiles(): array
    {
        return (array) $this->validated('translates');
    }
}

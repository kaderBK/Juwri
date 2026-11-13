<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Requests\Admin;


/** Drag-and-drop reorder: `position[]` holds the row ids in their new order. */
class ReorderRequest extends PanelFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'position' => ['sometimes', 'array'],
            'position.*' => ['integer'],
        ];
    }

    /** @return list<int> ids in display order */
    public function order(): array
    {
        return array_values(array_map('intval', (array) ($this->validated('position') ?? [])));
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Requests\Admin;

use Juwri\Core\Http\Requests\Admin\PanelFormRequest;

/**
 * Edit an ad slot. `code` is the raw ad snippet the site prints as-is, so it
 * is stored untouched (only superadmins reach this form).
 */
class AdSlotRequest extends PanelFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'provider_name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'boolean'],
            'code' => ['nullable', 'string'],
        ];
    }
}

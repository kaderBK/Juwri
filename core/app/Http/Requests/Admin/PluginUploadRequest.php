<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Requests\Admin;

use Juwri\Core\Http\Requests\Admin\PanelFormRequest;

/** Plugin install: a single .zip package. */
class PluginUploadRequest extends PanelFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['plugin_zip' => ['required', 'file', 'mimes:zip']];
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Base for forms posted from the admin side panel / list actions.
 *
 * The admin screens (assets/owner-ui/admin.js) read every answer as
 * `{success, message}` with HTTP 200 and show `message` as a toast, so a
 * failed validation is answered in that shape (all messages, one per line)
 * instead of Laravel's 422 error bag.
 *
 * Who may act is decided by the model policies (App\Policies\Admin\*), which
 * the controllers apply before the request is validated; the admin route
 * group already limits these URLs to superadmins.
 */
abstract class PanelFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => implode('<br>', $validator->errors()->all()),
        ]));
    }
}

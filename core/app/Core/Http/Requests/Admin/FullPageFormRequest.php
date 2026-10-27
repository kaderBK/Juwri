<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Base for admin forms that post as a normal page (not the side panel):
 * a failed validation goes back to the form with the input kept and all
 * messages shown in one toast (`app_alert_*`, one message per line).
 */
abstract class FullPageFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function failedValidation(Validator $validator): void
    {
        app_alert_error(implode('<br>', $validator->errors()->all()));

        throw new HttpResponseException(redirect()->back()->withInput($this->except($this->dontFlash)));
    }
}

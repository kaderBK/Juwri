<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * R1d: typed validation for the public contact + feedback forms (HomeController::
 * contactSend / feedbackSend), replacing the duplicated inline $request->validate().
 * Behaviour is identical — a FormRequest throws the same ValidationException the
 * inline call did, so a web submit still redirects back with errors. The reCAPTCHA
 * rules ride along via validate_recaptcha() exactly as before.
 */
class ContactFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public form
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
        ] + validate_recaptcha();
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Marketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * R1d: typed validation for the public newsletter signup (HomeController::
 * newsletter), replacing the inline $request->validate(). Identical rule set.
 */
class NewsletterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public form
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', 'unique:newsletter_subscribers'],
        ];
    }
}

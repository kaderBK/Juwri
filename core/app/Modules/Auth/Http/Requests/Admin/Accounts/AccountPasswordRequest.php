<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Http\Requests\Admin\Accounts;

use Juwri\Core\Http\Requests\Admin\FullPageFormRequest;

/** A superadmin setting a new password for an account. */
class AccountPasswordRequest extends FullPageFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', \Illuminate\Validation\Rules\Password::defaults(), 'confirmed'],
            'password_confirmation' => ['required'],
        ];
    }
}

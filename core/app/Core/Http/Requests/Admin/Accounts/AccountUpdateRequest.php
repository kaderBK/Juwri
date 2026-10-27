<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Requests\Admin\Accounts;

use Juwri\Core\Http\Requests\Admin\FullPageFormRequest;
use Juwri\Core\Models\User;
use Illuminate\Validation\Rule;

/** The account details form on the admin edit page. */
class AccountUpdateRequest extends FullPageFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $account = $this->route('user');
        $id = $account instanceof User ? $account->getKey() : null;

        return [
            'user_type' => ['required', Rule::in(['user', 'admin'])],
            'name' => ['required', 'string', 'max:50'],
            'username' => ['required', 'string', 'max:50', Rule::unique('users', 'username')->ignore($id)],
            'email' => ['required', 'string', 'email', 'max:100', Rule::unique('users', 'email')->ignore($id)],
            'country' => ['required', 'integer', 'exists:countries,id'],
            'status' => ['nullable', 'boolean'],
            'email_status' => ['nullable', 'boolean'],
            'avatar' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
        ];
    }
}

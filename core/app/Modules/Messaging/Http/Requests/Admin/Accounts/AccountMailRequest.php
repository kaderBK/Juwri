<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Http\Requests\Admin\Accounts;

use Juwri\Core\Http\Requests\Admin\FullPageFormRequest;

/** A one-off e-mail to the account holder. */
class AccountMailRequest extends FullPageFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string'],
            'message' => ['required', 'string'],
            'reply_to' => ['required', 'email'],
        ];
    }
}

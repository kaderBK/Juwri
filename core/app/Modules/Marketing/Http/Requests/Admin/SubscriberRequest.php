<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Marketing\Http\Requests\Admin;

use Juwri\Modules\Marketing\Models\Subscriber;
use Illuminate\Validation\Rule;
use Juwri\Core\Http\Requests\Admin\PanelFormRequest;

/** Add / change a newsletter subscriber address; addresses are unique. */
class SubscriberRequest extends PanelFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $current = $this->route('subscriber');

        return [
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('newsletter_subscribers', 'email')->ignore($current instanceof Subscriber ? $current->getKey() : null),
            ],
        ];
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Requests\Admin;

use Juwri\Core\Http\Requests\Admin\PanelFormRequest;

/**
 * A payment gateway's side-panel form: its title, whether it is offered, and
 * the gateway's own settings (see Juwri\Modules\Payments\Support\GatewaySettings).
 */
class PaymentGatewayRequest extends PanelFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
            'active' => ['required', 'boolean'],
        ];
    }
}

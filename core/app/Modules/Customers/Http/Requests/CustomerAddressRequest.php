<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * P6-294 Customer address book — shared field validation for the
 * customer-app storeAddress endpoint, used by both the web
 * storefront form (User\CustomerAuthController@storeAddress) and
 * the standalone customer-app API (Api\V1\CustomerAppController@storeAddress).
 *
 * Field-level rules (lat/lng range + both-or-neither; line must contain a
 * non-whitespace character). The "max 20 addresses per customer" cap is
 * checked in the controllers because each surface resolves the customer
 * differently and reports validation errors in its own style.
 */
class CustomerAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Trim the `line` field before validation so a whitespace-only value
     * is treated as empty (and the `min:1` rule below fires).
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('line')) {
            $line = $this->input('line');
            if (is_string($line)) {
                $this->merge(['line' => trim($line)]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:80'],
            'line' => ['required', 'string', 'min:1', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'zip' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', 'string', 'max:120'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}

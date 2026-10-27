<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Analytics\Http\Controllers\User\Concerns;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\JsonResponse;

/**
 * One place for how the owner/storefront controllers report a failed
 * validator: every message, in rule order, joined by `<br>` — as a flash
 * alert for form posts, or as `{success:false, message}` for AJAX calls.
 */
trait ReportsValidationFailures
{
    protected function validationSummary(ValidatorContract $validator): string
    {
        return implode('<br>', $validator->errors()->all());
    }

    protected function flashValidationFailure(ValidatorContract $validator): void
    {
        app_alert_error($this->validationSummary($validator));
    }

    protected function validationFailureJson(ValidatorContract $validator): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $this->validationSummary($validator)]);
    }
}

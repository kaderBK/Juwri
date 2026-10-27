<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Exceptions;

use Exception;

/**
 * F1-2 (feature-platform gap fix): a plan limit (menu items, categories, …) was
 * reached on a write path that is NOT the dashboard.
 *
 * The dashboard enforces limits inline and answers with a flash message; the
 * REST API, the staff app API and the MCP tools all go through
 * {@see \Juwri\Modules\Menu\Services\MenuAdminService}, so the check lives there and the
 * refusal travels as this exception. Rendering itself (422) keeps the global
 * exception Handler untouched.
 */
class PlanLimitReachedException extends Exception
{
    public function __construct(public readonly string $limitType, ?string $message = null)
    {
        parent::__construct($message ?: "Plan {$limitType} limit reached. Please upgrade your membership.");
    }

    public function render($request)
    {
        return response()->json([
            'error' => $this->getMessage(),
            'limit' => $this->limitType,
        ], 422);
    }
}

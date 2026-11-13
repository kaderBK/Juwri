<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Laundry\Contracts;

use Juwri\Core\Models\Store;
use Symfony\Component\HttpFoundation\Response;

/**
 * P3-11: the storefront theme's say in the laundry booking pages. The laundry flow (LaundryThemeController) works out what
 * each step shows; when the store's theme is a kit theme that draws them (`book`), this renders the step from the view
 * model. Null / false = not this store's theme: the plain booking page stays the only one. Implemented by the Storefront module.
 */
interface LaundryPageRenderer
{
    /** Does this store's theme draw the laundry booking pages? */
    public function renders(Store $store): bool;

    /**
     * @param  'schedule'|'details'  $page
     * @param  array<string, mixed>  $data  what the step computed (see LaundryThemeController)
     */
    public function render(Store $store, string $page, array $data): ?Response;
}

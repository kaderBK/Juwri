<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Contracts;

use Juwri\Core\Models\Store;
use Symfony\Component\HttpFoundation\Response;

/**
 * P3-11: the storefront theme's say in the booking pages. The booking flow (Bookings' storefront controller) works out
 * what each step shows; when the store's theme is a kit theme that draws booking pages (`book`), this renders the step
 * from the view model instead of the plain page. Null = not this store's theme: the plain page renders.
 * Implemented by the Storefront module.
 */
interface BookingPageRenderer
{
    /** Does this store's theme draw the booking pages? */
    public function renders(Store $store): bool;

    /**
     * @param  'times'|'reschedule'|'details'|'manage'|'pay'  $page
     * @param  array<string, mixed>  $data  what the step computed (models and plain values; see BookingStorefrontController)
     */
    public function render(Store $store, string $page, array $data): ?Response;
}

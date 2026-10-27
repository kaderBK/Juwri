<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Storefront;

use App\Services\StorefrontThemeService;
use Illuminate\Support\Facades\View;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Bookings\Contracts\BookingPageRenderer;
use Juwri\Modules\Bookings\Contracts\StorefrontBookings;
use Symfony\Component\HttpFoundation\Response;

/**
 * P3-11: the `book` kit theme's pages of the booking flow (Bookings' storefront controller asks, through
 * {@see BookingPageRenderer}): choose a time, confirm, pay the deposit, the booking page — each rendered from the view
 * model of that step. A store whose theme is not `book` (or that does not sell through the booking engine) gets null:
 * the plain pages render, as they always did.
 */
final class BookStorefront implements BookingPageRenderer
{
    public const THEME = 'book';

    private const VIEWS = ['times' => 'time', 'reschedule' => 'reschedule', 'details' => 'details', 'pay' => 'pay', 'manage' => 'manage'];

    public function __construct(private readonly KitStorefront $kit, private readonly StorefrontBookings $bookings) {}

    public function renders(Store $store): bool
    {
        $template = app(StorefrontThemeService::class)->resolve(StoreSettings::string((int) $store->id, 'store_template'), $store->store_type ?? null);

        return $template === self::THEME && $this->bookings->inUse($store);
    }

    public function render(Store $store, string $page, array $data): ?Response
    {
        if (! $this->renders($store)) {
            return null;
        }
        $view = self::VIEWS[$page];
        $vmPage = $view === 'time' ? 'time' : $view;
        $http = request();
        $html = View::make('store_templates.book.'.$view, $this->kit->bookingPage($store, $http, self::THEME, $vmPage, $data))->render();

        return response($html)->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex');
    }
}

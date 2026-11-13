<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Storefront;

use App\Services\StorefrontThemeService;
use Illuminate\Support\Facades\View;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Laundry\Contracts\LaundryPageRenderer;
use Symfony\Component\HttpFoundation\Response;

/**
 * P3-11: the `book` kit theme's pages of the laundry booking (choose the pickup and delivery times, confirm), drawn from the view
 * model of that step. A store whose theme is not `book`, or that sells through the booking engine, gets null: the plain page.
 */
final class LaundryStorefront implements LaundryPageRenderer
{
    private const VIEWS = ['schedule' => 'laundry-time', 'details' => 'laundry-details'];

    public function __construct(private readonly KitStorefront $kit) {}

    public function renders(Store $store): bool
    {
        $template = app(StorefrontThemeService::class)->resolve(StoreSettings::string((int) $store->id, 'store_template'), $store->store_type ?? null);

        return $template === BookStorefront::THEME && $this->kit->laundryMode($store, BookStorefront::THEME);
    }

    public function render(Store $store, string $page, array $data): ?Response
    {
        if (! $this->renders($store)) {
            return null;
        }
        $html = View::make('store_templates.book.'.self::VIEWS[$page], $this->kit->laundryPage($store, request(), BookStorefront::THEME, $page, $data))->render();

        return response($html)->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex');
    }
}

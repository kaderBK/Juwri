<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Http\Controllers;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Services\FeatureAccess;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Enums\OrderStatus;
use Juwri\Modules\Pos\Models\SignageScreen;

/**
 * GAPS-RUN-2 B6-P1: PUBLIC digital signage boards. No auth cookie — the token is
 * the only credential and resolves to the screen → tenant. Invalid/inactive token
 * → 404. Two kinds: a rotating menu board and a pickup/order-status board.
 */
class SignageController extends Controller
{
    public function board(string $token)
    {
        $screen = $this->resolveScreen($token);

        // I5-7: render the in-store board in the store's own default menu language
        // so "Preparing"/"Ready"/etc. are localized for the diner-facing screen.
        $lang = store_options((int) $screen->store_id, 'default_language')
            ?: (config('settings.default_lang') ?: config('app.locale'));
        app()->setLocale($lang);

        return view('signage.'.($screen->kind === 'pickup' ? 'pickup' : 'menu'), [
            'screen' => $screen,
            'data' => $this->data($screen),
        ]);
    }

    public function state(string $token)
    {
        return response()->json($this->data($this->resolveScreen($token)));
    }

    /**
     * F6-4: both endpoints checked the kill-switch only, so a lapsed
     * `digital_signage` entitlement left every board live indefinitely — and the
     * `state` endpoint is polled on a timer by unattended screens, hence the
     * cached (60s TTL, event-busted) decision. The subject is the screen's store
     * OWNER; a signage screen authenticates with a token, never a user. 404 is
     * kept for every refusal so the token surface stays uniform.
     */
    private function resolveScreen(string $token): SignageScreen
    {
        $screen = SignageScreen::resolve($token);
        abort_unless($screen, 404);
        abort_unless(
            FeatureAccess::storeAllowsCached((int) $screen->store_id, 'digital_signage'),
            404
        );

        return $screen;
    }

    private function data(SignageScreen $screen): array
    {
        return $screen->kind === 'pickup'
            ? $this->pickupData($screen)
            : $this->menuData($screen);
    }

    /** Categories with their in-stock items — big-type menu board. */
    private function menuData(SignageScreen $screen): array
    {
        $rid = (int) $screen->store_id;
        $categories = MenuCategory::where('store_id', $rid)
            ->where('active', true)
            ->orderBy('position')
            ->get(['id', 'name']);

        $items = Menu::where('store_id', $rid)
            ->where('active', true)
            ->where('is_sold_out', false) // sold-out hidden
            ->orderBy('position')
            ->get(['id', 'category_id', 'name', 'price_minor', 'currency_code'])
            ->groupBy('category_id');

        return [
            'kind' => 'menu',
            'store' => $screen->store?->title,
            'categories' => $categories->map(fn ($c) => [
                'name' => $c->name,
                'items' => ($items->get($c->id) ?? collect())->map(fn ($m) => [
                    'name' => $m->name,
                    'price' => round((float) $m->price, 2),
                ])->values(),
            ])->filter(fn ($c) => count($c['items']) > 0)->values(),
        ];
    }

    /** Live orders being prepared / ready — pickup board. */
    private function pickupData(SignageScreen $screen): array
    {
        $rid = (int) $screen->store_id;
        $query = Order::whereIn('status', [OrderStatus::Preparing->value, OrderStatus::Ready->value])
            ->where('store_id', $rid)
            ->live() // run-1: unreleased scheduled orders stay hidden
            ->orderBy('updated_at', 'desc');

        if ($screen->branch_id) {
            $query->where('branch_id', $screen->branch_id);
        }

        $orders = $query->limit(60)->get(['id', 'status', 'customer_name', 'ready_at']);

        $format = fn ($o) => [
            'number' => $o->id,
            'name' => $this->firstName($o->customer_name),
        ];

        // SYSTEMS-RUN O-P1: screen config — voice announce, auto-clear, branding.
        $config = (array) ($screen->config ?? []);
        $autoClearMin = (int) ($config['auto_clear_minutes'] ?? 0);

        $ready = $orders->where('status', 'ready');
        if ($autoClearMin > 0) {
            $cutoff = now()->subMinutes($autoClearMin);
            $ready = $ready->filter(fn ($o) => ! $o->ready_at || $o->ready_at->gt($cutoff));
        }

        return [
            'kind' => 'pickup',
            'preparing' => $orders->where('status', 'preparing')->map($format)->values(),
            'ready' => $ready->map($format)->values(),
            'config' => [
                'announce' => (bool) ($config['announce'] ?? false),
                'announce_lang' => (string) ($config['announce_lang'] ?? app()->getLocale()),
                'auto_clear_minutes' => $autoClearMin,
                'brand_color' => (string) ($config['brand_color'] ?? optional($screen->store)->color ?? '#111'),
                'brand_logo' => (string) ($config['brand_logo'] ?? optional($screen->store)->main_image ?? ''),
                'promo_panel' => (bool) ($config['promo_panel'] ?? false),
            ],
        ];
    }

    private function firstName(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }

        return explode(' ', $name)[0];
    }
}

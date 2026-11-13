<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use App\Models\Order;
use App\Services\OrderApiService;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelBuilder;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelJson;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelRequest;

/**
 * P3-02 test helper: build view models for a seeded store, pick a deterministic demo cart from what the view model
 * itself offers (public ids only), and place the same cart as a real order (OrderApiService, the path every
 * storefront order takes) so quote and order can be compared.
 */
final class ViewModelScenario
{
    public function __construct(public readonly Store $store, public readonly string $theme) {}

    /** @param  array<string, mixed>  $args  named ViewModelRequest arguments */
    public function build(string $locale, string $page = 'home', array $args = []): array
    {
        return app(ViewModelBuilder::class)->build(new ViewModelRequest(...['store' => $this->store, 'locale' => $locale, 'page' => $page, 'theme' => $this->theme] + $args));
    }

    /** @return list<string> validator errors of a built view model, as JSON */
    public static function errors(array $vm): array
    {
        return ViewModelValidator::errorsForJson(ViewModelJson::encode($vm));
    }

    /**
     * A demo cart: the first orderable item with choices (its required choices at their first option, plus the
     * first option of one optional group) x2, and the first orderable item without choices x1. Built from an item
     * page view model, so it only uses what a shopper can pick.
     *
     * @return list<array{item: string, qty: int, options: list<string>}>
     */
    public function cart(): array
    {
        $home = $this->build('en');
        $withChoices = null;
        $simple = null;
        foreach ($home['catalog']['items'] as $card) {
            if ($card['sold_out']) {
                continue;
            }
            $detail = $this->build('en', 'item', ['item' => $card['id']])['page']['item'] ?? null;
            if ($detail === null) {
                continue;
            }
            if ($withChoices === null && $detail['option_groups'] !== [] && ! isset($detail['variants'])) {
                $withChoices = $detail;
            } elseif ($simple === null && $detail['option_groups'] === [] && ! isset($detail['variants'])) {
                $simple = $detail;
            }
            if ($withChoices !== null && $simple !== null) {
                break;
            }
        }

        $cart = [];
        if ($withChoices !== null) {
            $options = [];
            $optionalTaken = false;
            foreach ($withChoices['option_groups'] as $group) {
                $available = array_values(array_filter($group['options'], fn (array $o) => ($o['available'] ?? true)));
                if ($group['required']) {
                    foreach (array_slice($available, 0, max(1, $group['min'])) as $o) {
                        $options[] = $o['id'];
                    }
                } elseif (! $optionalTaken && $available !== []) {
                    $options[] = $available[0]['id'];
                    $optionalTaken = true;
                }
            }
            $cart[] = ['item' => $withChoices['id'], 'qty' => 2, 'options' => $options];
        }
        if ($simple !== null) {
            $cart[] = ['item' => $simple['id'], 'qty' => 1, 'options' => []];
        }

        return $cart;
    }

    /**
     * Place a view-model cart as an order through OrderApiService (internal ids resolved here, like the storefront
     * order endpoint does).
     *
     * @param  list<array{item: string, qty: int, options?: list<string>, variant?: string|null}>  $cart
     * @param  array<string, mixed>  $extra  order data (type, scheduled_at, tip_amount, branch_id …)
     */
    public function place(array $cart, array $extra = []): Order
    {
        $lines = [];
        foreach ($cart as $line) {
            $item = Menu::query()->where('store_id', $this->store->id)->where('public_id', $line['item'])->firstOrFail();
            $row = ['item_id' => $item->id, 'quantity' => $line['qty'], 'extras' => [], 'options' => []];
            if (! empty($line['variant'])) {
                $row['variation'] = MenuVariant::query()->where('menu_id', $item->id)->where('public_id', $line['variant'])->value('id');
            }
            $posted = [];
            foreach ($line['options'] ?? [] as $publicId) {
                $found = false;
                foreach ($item->resolved_variant_options as $group) {
                    $index = $group->modifiers->values()->search(fn ($m) => (string) $m->public_id === $publicId);
                    if ($index !== false) {
                        $posted[$group->id][] = array_values((array) json_decode((string) json_encode($group->options), true))[$index];
                        $found = true;
                    }
                }
                if (! $found) {
                    $row['extras'][] = (int) $item->resolved_extras->first(fn ($e) => (string) $e->public_id === $publicId)->id;
                }
            }
            foreach ($posted as $groupId => $selected) {
                $row['options'][] = ['group_id' => $groupId, 'selected' => $selected];
            }
            $lines[] = $row;
        }

        return OrderApiService::create((int) $this->store->user_id, (int) $this->store->id, $extra + [
            'items' => $lines, 'customer_name' => 'Golden Test', 'phone' => '+966500000000', 'source' => 'qr',
        ]);
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Http\Controllers\User;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Modules\Ordering\Services\Grocery\GroceryPicking;
use Juwri\Modules\Ordering\Services\Grocery\GroceryPickingException;

/**
 * P3-09 (basket §8–§10, API-25 / API-30 / API-31): the owner / staff picking screen of grocery orders. The list shows
 * the store's orders that have something to pick (a slot, a weighed line or an out-of-stock choice), soonest slot
 * first; one order's screen walks its lines with the action the shopper chose for each (weigh, picked, propose a
 * replacement, call the customer, remove) and finishes picking, which settles the money (capture ≤ authorised, or
 * the up-front refund).
 *
 * Tenant scoping: the list reads the dashboard actor's current store; an order resolves through that actor's stores
 * (a foreign order id is a 404) and a line through its order (a foreign line id is refused).
 */
class GroceryPickingController extends Controller
{
    use ResolvesCurrentStore;

    private const OPEN = ['pending', 'new', 'accepted', 'preparing', 'ready'];

    public function index(Request $request): View
    {
        $rid = $this->currentRestaurantId($request);
        $orders = Order::query()
            ->where('store_id', $rid)
            ->whereIn('status', self::OPEN)
            ->where(fn (Builder $q) => self::grocery($q))
            ->orderByRaw('case when slot_starts_at is null then 1 else 0 end')
            ->orderBy('slot_starts_at')
            ->orderBy('id')
            ->limit(100)
            ->get();

        return view('owner.orders.picking-list', [
            'rid' => $rid,
            'orders' => $orders,
            'stores' => Store::query()->whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function show(Order $order): View
    {
        $order = $this->authorizeOrder($order);
        $currency = strtoupper((string) ($order->currency_code ?: store_currency((int) $order->store_id)));
        $order->unsetRelation('items');

        return view('owner.orders.picking', [
            'order' => $order,
            'lines' => OrderItem::query()->where('order_id', $order->id)->orderBy('id')->with('menu')->get(),
            'currency' => $currency,
            'money' => $order->computeMoney(),
        ]);
    }

    public function step(Request $request, Order $order, GroceryPicking $picking): RedirectResponse
    {
        $order = $this->authorizeOrder($order);
        $data = $request->validate([
            'step' => ['required', 'in:start,weigh,pick,remove,propose,call,finish,remove_after'],
            'item' => ['required_unless:step,start,finish', 'integer', 'min:1'],
            'grams' => ['required_if:step,weigh', 'integer', 'min:1', 'max:1000000'],
            'name' => ['required_if:step,propose', 'nullable', 'string', 'max:190'],
            'price' => ['required_if:step,propose', 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'outcome' => ['required_if:step,call', 'in:replace,remove,no_answer'],
        ]);
        $currency = strtoupper((string) ($order->currency_code ?: store_currency((int) $order->store_id)));
        $item = (int) ($data['item'] ?? 0);
        $name = isset($data['name']) ? (string) $data['name'] : null;
        $price = isset($data['price']) ? Money::minorFromMajor((float) $data['price'], $currency) : null;

        try {
            switch ($data['step']) {
                case 'start':
                    $picking->start($order);
                    $message = ___('Picking started.');
                    break;
                case 'weigh':
                    $picking->weigh($order, $item, (int) $data['grams']);
                    $message = ___('Weight saved.');
                    break;
                case 'pick':
                    $picking->pickAsOrdered($order, $item);
                    $message = ___('Item picked.');
                    break;
                case 'remove':
                    $picking->remove($order, $item);
                    $message = ___('Item removed.');
                    break;
                case 'propose':
                    $picking->propose($order, $item, (string) $name, (int) $price);
                    $message = ___('Replacement sent to the customer.');
                    break;
                case 'call':
                    $picking->callOutcome($order, $item, (string) $data['outcome'], $name, $price);
                    $message = ___('Call outcome saved.');
                    break;
                case 'finish':
                    $message = $this->finished($picking->finish($order), $currency);
                    break;
                default: // remove_after
                    $picking->removeAfterFinish($order, $item);
                    $message = ___('Item removed and refunded.');
            }
        } catch (GroceryPickingException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            report($e);

            return back()->with('error', ___('The payment provider refused: :message', ['message' => $e->getMessage()]));
        }

        return back()->with('success', $message);
    }

    /** Orders with something to pick: a slot, a weighed line or an out-of-stock choice. */
    /** @param  Builder<Order>  $q */
    private static function grocery(Builder $q): void
    {
        $q->whereNotNull('slot_hold_id')
            ->orWhereNotNull('substitution_option_id')
            ->orWhereNotNull('picking_status')
            ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('order_items')->whereColumn('order_items.order_id', 'orders.id')->where('order_items.weight_g', '>', 0));
    }

    /** @param  array{outcome: string, final_minor: int, released_minor: int, refunded_minor: int}|null  $result */
    private function finished(?array $result, string $currency): string
    {
        $fmt = fn (int $minor) => $currency.' '.number_format(Money::majorFromMinor($minor, $currency), Currencies::exponent($currency));

        return match ($result['outcome'] ?? null) {
            'captured' => ___('Picking finished. Charged :amount; :released of the card hold released.', ['amount' => $fmt($result['final_minor']), 'released' => $fmt($result['released_minor'])]),
            'voided' => ___('Picking finished. Nothing left to charge: the card hold was released.'),
            'refunded' => ___('Picking finished. Refunded :amount to the card.', ['amount' => $fmt($result['refunded_minor'])]),
            default => ___('Picking finished.'),
        };
    }

    private function authorizeOrder(Order $order): Order
    {
        if (! $order->exists) {
            $order = Order::query()->findOrFail((int) request()->route('order'));
        }
        abort_unless(
            function_exists('dashboard_can_access_store') && dashboard_can_access_store((int) $order->store_id),
            404
        );

        return $order;
    }
}

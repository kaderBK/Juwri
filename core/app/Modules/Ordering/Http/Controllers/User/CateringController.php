<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Http\Controllers\User;

use App\Models\CateringRequest;
use App\Services\Catering\CateringService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;

/**
 * GAPS-RUN-2 B8-P2: owner catering dashboard — requests list, quote, accept,
 * decline. Tenant scoped; behind the catering kill-switch.
 */
class CateringController extends Controller
{
    use ResolvesCurrentStore;

    public function __construct(private CateringService $catering) {}

    private function ownedRequest(int $id, array $ids): CateringRequest
    {
        return CateringRequest::whereIn('store_id', $ids)->findOrFail($id);
    }

    public function index(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $status = $request->query('status');

        $requests = CateringRequest::where('store_id', $rid)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('event_at')
            ->limit(100)
            ->get();

        return view('owner.catering.index', [
            'rid' => $rid,
            'status' => $status,
            'requests' => $requests,
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function quote(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $req = $this->ownedRequest($id, $this->storeIds());
        $data = $request->validate([
            'quote_total' => ['required', 'numeric', 'min:0'],
            'quote_note' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->catering->quote($req, (float) $data['quote_total'], $data['quote_note'] ?? null);

        return back()->with('success', ___('Quote sent.'));
    }

    public function accept(Request $request, int $id)
    {
        $rid = $this->currentRestaurantId($request);
        $req = $this->ownedRequest($id, $this->storeIds());

        // Line items: the request's pre-selection, else a single quote line.
        $lineItems = [];
        foreach ((array) $req->items as $line) {
            if (! empty($line['item_id'])) {
                $lineItems[] = ['item_id' => (int) $line['item_id'], 'quantity' => max(1, (int) ($line['quantity'] ?? 1))];
            }
        }
        if (empty($lineItems)) {
            // Fall back to any catering-only or first active item as the quote line.
            $menu = Menu::where('store_id', $rid)->where('active', '1')->orderByDesc('catering_only')->first(); // enum('0','1'): string, not index
            abort_unless($menu, 422, ___('Add a menu item before accepting catering.'));
            $lineItems[] = ['item_id' => $menu->id, 'quantity' => 1];
        }

        try {
            $this->catering->accept($req, $lineItems);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        // If a deposit was armed, hand the owner the customer-facing pay link to send on.
        $req->refresh();
        if ($req->deposit_status === 'pending' && $req->token) {
            $slug = Store::where('id', $req->store_id)->value('slug');

            return back()->with([
                'success' => ___('Catering accepted — order created. Send the customer this deposit link:'),
                'catering_deposit_link' => route('publicView.catering.deposit', ['slug' => $slug, 'token' => $req->token]),
            ]);
        }

        return back()->with('success', ___('Catering accepted — order created.'));
    }

    public function decline(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $this->catering->decline($this->ownedRequest($id, $this->storeIds()));

        return back()->with('success', ___('Request declined.'));
    }

    /** SYSTEMS-RUN G-P1: month calendar + pipeline board. */
    public function calendar(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $month = $request->query('month') ?: now()->format('Y-m');
        $start = Carbon::parse($month.'-01');

        $events = CateringRequest::where('store_id', $rid)
            ->whereBetween('event_at', [$start->copy()->startOfMonth(), $start->copy()->endOfMonth()])
            ->orderBy('event_at')->get();

        $stages = ['requested', 'quoted', 'accepted', 'fulfilled', 'closed', 'declined'];
        $pipeline = [];
        foreach ($stages as $s) {
            $pipeline[$s] = CateringRequest::where('store_id', $rid)->where('status', $s)->orderByDesc('event_at')->limit(50)->get();
        }

        return view('owner.catering.calendar', [
            'rid' => $rid,
            'month' => $month,
            'events' => $events,
            'pipeline' => $pipeline,
            'stages' => $stages,
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function fulfill(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $req = $this->ownedRequest($id, $this->storeIds());
        abort_unless($req->status === 'accepted', 422, ___('Only accepted events can be fulfilled.'));
        $req->update(['status' => 'fulfilled']);

        return back()->with('success', ___('Marked fulfilled.'));
    }

    public function closeEvent(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $req = $this->ownedRequest($id, $this->storeIds());
        $req->update(['status' => 'closed']);

        return back()->with('success', ___('Event closed.'));
    }
}

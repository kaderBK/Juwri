<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Http\Controllers\User;

use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Customers\Models\CustomerFeedback;
use Juwri\Modules\Customers\Services\FeedbackService;

/**
 * SYSTEMS-RUN S-P1: owner feedback inbox — list, respond, close. Tenant scoped;
 * behind support_desk.
 */
class FeedbackController extends Controller
{
    use ResolvesCurrentStore;

    public function __construct(private FeedbackService $feedback) {}

    private function owned(int $id): CustomerFeedback
    {
        return CustomerFeedback::whereIn('store_id', $this->storeIds())->findOrFail($id);
    }

    public function index(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $rating = $request->query('rating');

        $items = CustomerFeedback::where('store_id', $rid)
            ->whereNotNull('rating')
            ->when($rating, fn ($q) => $q->where('rating', (int) $rating))
            ->latest('submitted_at')->limit(100)->get();

        return view('owner.customers.feedback', [
            'rid' => $rid,
            'rating' => $rating,
            'items' => $items,
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function respond(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $data = $request->validate(['reply' => ['required', 'string', 'max:2000']]);
        $this->feedback->respond($this->owned($id), $data['reply']);

        return back()->with('success', ___('Reply saved.'));
    }

    public function close(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $this->feedback->close($this->owned($id));

        return back()->with('success', ___('Feedback closed.'));
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Http\Controllers\User;

use App\Models\Audience;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Services\Crm\AttributionService;
use App\Services\Crm\AudienceService;
use App\Services\Crm\AutoTagService;
use App\Services\Crm\RfmService;
use App\Services\FeatureSpotlight;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Customers\Services\Crm\GuestTimeline;

/**
 * SYSTEMS-RUN C-P1: unified guest profile (all-channel timeline) + RFM
 * dashboard. Tenant scoped; behind crm_suite.
 */
class CrmController extends Controller
{
    use ResolvesCurrentStore;

    private function ownedCustomer(int $id): Customer
    {
        return Customer::whereIn('store_id', $this->storeIds())->findOrFail($id);
    }

    public function profile(Request $request, int $id)
    {
        $customer = $this->ownedCustomer($id);

        return view('owner.customers.profile', [
            'customer' => $customer,
            'timeline' => new GuestTimeline()->for($customer),
            'loyalty' => LoyaltyAccount::where('customer_id', $customer->id)->first(),
            'autoTags' => (array) ($customer->system_tags ?? []),
        ]);
    }

    public function updatePreferences(Request $request, int $id)
    {
        $customer = $this->ownedCustomer($id);
        $data = $request->validate([
            'preferences' => ['nullable', 'string', 'max:500'],
            'allergies' => ['nullable', 'string', 'max:500'],
            'tags' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $customer->update($data);

        return back()->with('success', ___('Guest updated.'));
    }

    public function rfm(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $rfm = new RfmService()->compute($rid);

        /* WI-A3 (upsell-visibility): this route is no longer behind the
           `feature:` middleware, so an unentitled owner can see their OWN data
           as a blurred preview rather than a locked door.

           Blur is presentation only — anything sent is readable in the page
           source — so the preview payload carries NO row-level data at all.
           The segment counts are already aggregates; the per-guest score table
           is dropped entirely, because every row of it is a guest's name or
           phone number plus a link to their profile. */
        $preview = FeatureSpotlight::isPreview('crm_suite', $request->user());

        /* WI-B1: this route left the `feature:` middleware in A3 so the preview
           could render, and that middleware is what tells the layout which paid
           feature a page belongs to. An entitled owner would therefore never be
           told they own this one. Say it here instead. */
        if (! $preview) {
            view()->share('pageFeatureKey', 'crm_suite');
        }

        return view('owner.customers.rfm', [
            'rid' => $rid,
            'segments' => $rfm['segments'],
            'scores' => $preview ? [] : $rfm['scores'],
            'preview' => $preview,
            'previewKey' => 'crm_suite',
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function retag(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        new AutoTagService()->recompute($rid);

        return back()->with('success', ___('Tags recomputed.'));
    }

    public function audiences(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $svc = new AudienceService;
        $audiences = Audience::where('store_id', $rid)->latest()->get()
            ->map(fn ($a) => tap($a, fn ($x) => $x->live_count = $svc->count($rid, $x->rules)));

        return view('owner.customers.audiences', [
            'rid' => $rid,
            'audiences' => $audiences,
            'attribution' => new AttributionService()->report($rid),
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function storeAudience(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'match' => ['nullable', 'in:all,any'],
            'conditions' => ['nullable', 'array'],
            'conditions.*.field' => ['required_with:conditions', 'string'],
            'conditions.*.op' => ['nullable', 'string'],
            'conditions.*.value' => ['nullable'],
        ]);
        Audience::create([
            'store_id' => $rid,
            'name' => $data['name'],
            'rules' => ['match' => $data['match'] ?? 'all', 'conditions' => array_values($data['conditions'] ?? [])],
        ]);

        return back()->with('success', ___('Audience saved.'));
    }

    public function previewAudience(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $rules = ['match' => $request->input('match', 'all'), 'conditions' => $request->input('conditions', [])];

        return response()->json(['count' => new AudienceService()->count($rid, $rules)]);
    }

    public function destroyAudience(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        Audience::whereIn('store_id', $this->storeIds())->findOrFail($id)->delete();

        return back()->with('success', ___('Audience deleted.'));
    }
}

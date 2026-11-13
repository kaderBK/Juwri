<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Marketing\Http\Controllers\User;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use App\Support\BioLinkOutbound;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Marketing\Jobs\SendCampaignJob;
use Juwri\Modules\Marketing\Support\CampaignHtml;

class CampaignController extends Controller
{
    public function __construct()
    {
        $this->activeTheme = active_theme();
    }

    public function index(Request $request)
    {
        $storeIds = dashboard_store_ids();

        $campaigns = Campaign::whereIn('store_id', $storeIds)
            ->latest()
            ->paginate(10);

        $totalSubscribers = Customer::whereIn('store_id', $storeIds)
            ->where('marketing_consent', true)
            ->count();

        return view('owner.marketing.campaigns.index', compact('campaigns', 'totalSubscribers'));
    }

    public function create(Request $request)
    {
        $stores = Store::whereIn('id', dashboard_store_ids())->get();
        if ($stores->isEmpty()) {
            return redirect()->route('dashboard')->with('error', ___('Please create a :noun first.', ['noun' => store_noun(null, 'indef')]));
        }

        // biolink-tpl lane-d (T2-3): per-store bio URL for the "Insert bio link"
        // composer control — only stores whose bio page is actually live appear,
        // so the button is hidden for a store with a dark/unpublished bio.
        $bioUrls = $stores
            ->mapWithKeys(fn ($r) => [$r->id => BioLinkOutbound::liveUrl($r, 'share')])
            ->filter()
            ->all();

        return view('owner.marketing.campaigns.create', compact('stores', 'bioUrls'));
    }

    public function store(Request $request)
    {
        $storeIds = dashboard_store_ids();

        $validator = Validator::make($request->all(), [
            'store_id' => 'required|in:'.implode(',', $storeIds),
            'channel' => 'required|in:email,sms',
            'subject' => 'required_if:channel,email|nullable|string|max:255',
            // P6-287: SMS bodies are capped at 612 chars (mb_strlen via the max rule);
            // email keeps a generous ceiling but no tight character limit.
            'body' => [
                'required',
                'string',
                Rule::when($request->input('channel') === 'sms', 'max:612', 'max:20000'),
            ],
            'segment' => ['required', 'regex:/^(all|recent|lapsed|audience:\d+)$/'],
            'scheduled_at' => 'nullable|date|after:now',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        // P6-286: a body that is nothing but stripped markup would mail an empty message.
        if ($request->input('channel') === 'email'
            && trim(strip_tags(CampaignHtml::clean((string) $request->input('body')), '<img>')) === '') {
            return back()->withErrors(['body' => ___('The message has no content left after unsafe HTML was removed.')])->withInput();
        }

        $scheduledAt = $request->input('scheduled_at') ? Carbon::parse($request->input('scheduled_at')) : null;

        $campaign = Campaign::create([
            'store_id' => $request->input('store_id'),
            'channel' => $request->input('channel'),
            'subject' => $request->input('subject'),
            // P6-286: e-mail HTML goes through the campaign allowlist sanitiser before it is stored.
            'body' => $request->input('channel') === 'email'
                ? CampaignHtml::clean((string) $request->input('body'))
                : $request->input('body'),
            'segment' => $request->input('segment'),
            'status' => 'scheduled',
            'scheduled_at' => $scheduledAt ?: now(),
        ]);

        if ($scheduledAt) {
            // Dispatch delayed job
            SendCampaignJob::dispatch($campaign->id)->delay($scheduledAt);
        } else {
            // Dispatch immediate job
            SendCampaignJob::dispatch($campaign->id);
        }

        return redirect()->route('campaigns.index')->with('success', ___('Campaign created and queued successfully.'));
    }

    public function show(Request $request, $id)
    {
        $storeIds = dashboard_store_ids();
        $campaign = Campaign::whereIn('store_id', $storeIds)->findOrFail($id);

        $recipients = CampaignRecipient::where('campaign_id', $campaign->id)
            ->with('customer')
            ->paginate(20);

        return view('owner.marketing.campaigns.show', compact('campaign', 'recipients'));
    }

    /**
     * P6-222: the link in the mail. Mail scanners and link previews open it, so GET
     * only shows a confirm page; the POST below withdraws consent. The signature is
     * checked without its expiry: consent withdrawal must work for as long as the
     * mail exists (links sent before P6-222 carried a 30-day expiry).
     */
    public function unsubscribeConfirm(Request $request, int|string $customer): View
    {
        $customerModel = $this->signedCustomer($request, $customer);
        $store = Store::find($customerModel->store_id);

        return view('store_templates.unsubscribe', [
            'restaurantName' => $store ? $store->title : ___('Restaurant'),
            'confirmUrl' => $request->fullUrl(),
        ]);
    }

    /** P6-222: POST from the confirm page, or the mail provider's one-click (List-Unsubscribe-Post). */
    public function unsubscribe(Request $request, int|string $customer): View
    {
        $customerModel = $this->signedCustomer($request, $customer);
        $customerModel->update([
            'marketing_consent' => false,
            // P4-10: withdraw SMS as well, like the public unsubscribe form. Leaving it set meant a later checkout that
            // ticked only the marketing box silently re-enabled texts the customer had unsubscribed from.
            'sms_consent' => false,
            'consent_at' => null,
        ]);

        $store = Store::find($customerModel->store_id);

        return view('store_templates.unsubscribe', [
            'restaurantName' => $store ? $store->title : ___('Restaurant'),
        ]);
    }

    private function signedCustomer(Request $request, int|string $customer): Customer
    {
        // hasCorrectSignature, not hasValidSignature: an expired (pre-P6-222) link still unsubscribes.
        if (! URL::hasCorrectSignature($request)) {
            abort(401, ___('This link has expired or is invalid.'));
        }

        return Customer::findOrFail($customer);
    }
}

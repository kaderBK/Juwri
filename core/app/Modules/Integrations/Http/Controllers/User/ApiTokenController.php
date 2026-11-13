<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Http\Controllers\User;

use App\Models\StorefrontToken;
use App\Models\WebhookEndpoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\UserOption;
use Juwri\Core\Services\Security\OneTimeSecrets;
use Juwri\Core\Services\SsrfGuard;
use Juwri\Modules\Integrations\Jobs\DeliverWebhookJob;
use Juwri\Modules\Integrations\Listeners\RevokeTokensOnCredentialChange;
use Juwri\Modules\Integrations\Models\ApiRequestLog;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Integrations\Models\WebhookDelivery;
use Juwri\Modules\Messaging\Contracts\MobilePush;

class ApiTokenController extends Controller
{
    public function index(Request $request, OneTimeSecrets $secrets)
    {
        // P4: a just-created token is shown once from the server-side store (never a session flash).
        $newToken = $secrets->pull('api_token', (int) auth()->id(), $request->query('new_token'));
        $newStorefrontToken = $secrets->pull('storefront_token', (int) auth()->id(), $request->query('new_storefront_token'));
        $tokens = ApiToken::where('user_id', auth()->id())->orderByDesc('created_at')->get();
        $webhooks = WebhookEndpoint::where('user_id', auth()->id())->orderByDesc('created_at')->get();
        $deliveries = WebhookDelivery::whereIn('endpoint_id', $webhooks->pluck('id'))
            ->orderByDesc('created_at')->limit(50)->get();

        $stores = Store::where('user_id', auth()->id())->get(['id', 'title']);
        $activeId = (int) (dashboard_current_store()->id ?? $stores->first()->id ?? 0) ?: null;
        $storefrontTokens = StorefrontToken::whereIn('store_id', $stores->pluck('id'))
            ->with('store:id,title')->orderByDesc('created_at')->get();

        // P6-75: after a password reset/change or 2FA disable, integration tokens are listed for review.
        $reviewTokens = UserOption::getUserOption(auth()->id(), RevokeTokensOnCredentialChange::REVIEW_OPTION)
            ? RevokeTokensOnCredentialChange::personalTokens((int) auth()->id())->orderByDesc('created_at')->get()
            : collect();

        return view('owner.developer.api', compact('tokens', 'webhooks', 'deliveries', 'stores', 'storefrontTokens', 'activeId', 'newToken', 'newStorefrontToken', 'reviewTokens'));
    }

    /** P6-75: one-click revoke of every integration token flagged after a credential change. */
    public function revokeReviewedTokens(): RedirectResponse
    {
        RevokeTokensOnCredentialChange::personalTokens((int) auth()->id())->delete();
        UserOption::updateUserOption(auth()->id(), RevokeTokensOnCredentialChange::REVIEW_OPTION, '');

        return back()->with('success', ___('API tokens revoked.'));
    }

    /** P6-75: the owner reviewed the integration tokens and keeps them. */
    public function dismissTokenReview(): RedirectResponse
    {
        UserOption::updateUserOption(auth()->id(), RevokeTokensOnCredentialChange::REVIEW_OPTION, '');

        return back();
    }

    /** HPMS E11: paginated API request-log viewer, filterable by token + status. */
    public function requestLogs(Request $request): View
    {
        $tokens = ApiToken::where('user_id', auth()->id())->orderByDesc('created_at')->get(['id', 'name']);
        $tokenIds = $tokens->pluck('id');

        $query = ApiRequestLog::whereIn('token_id', $tokenIds);
        if ($request->filled('token_id') && $tokenIds->contains((int) $request->query('token_id'))) {
            $query->where('token_id', (int) $request->query('token_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', (int) $request->query('status'));
        }

        $logs = $query->orderByDesc('id')->paginate(50)->withQueryString();

        return view('owner.developer.api-request-logs', compact('logs', 'tokens'));
    }

    /**
     * MCP-expansion P4-1: "Connect AI" — one place that shows this owner's MCP
     * endpoints (admin, storefront, customer, public catalog) with copy-paste
     * config for Claude Code / Claude Desktop / Cursor, and links to the token UI.
     */
    public function connectAi()
    {
        $stores = Store::where('user_id', auth()->id())->get(['id', 'title', 'slug']);
        $activeId = (int) (dashboard_current_store()->id ?? $stores->first()->id ?? 0) ?: null;
        $storefrontTokens = StorefrontToken::whereIn('store_id', $stores->pluck('id'))
            ->with('store:id,title')->orderByDesc('created_at')->get();

        $endpoints = [
            'admin' => url('/mcp'),
            'storefront' => url('/mcp/storefront'),
            'customer' => url('/mcp/customer'),
            'catalog' => url('/mcp/catalog'),
        ];

        return view('owner.developer.connect-ai', compact('stores', 'storefrontTokens', 'endpoints', 'activeId'));
    }

    public function createToken(Request $request, OneTimeSecrets $secrets)
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'abilities' => 'nullable|array',
            'abilities.*' => Rule::in(ApiToken::selectableAbilities()),
            'expires_days' => 'nullable|integer|min:1|max:3650',
        ]);

        $raw = Str::random(40);

        $token = ApiToken::create([
            'user_id' => auth()->id(),
            'name' => $data['name'],
            'token' => hash('sha256', $raw),
            'abilities' => $data['abilities'] ?? ['read'],
            'expires_at' => isset($data['expires_days']) ? now()->addDays($data['expires_days']) : null,
        ]);

        $secrets->put('api_token', (int) auth()->id(), (int) $token->id, $raw);

        return redirect()->route('api.index', ['new_token' => $token->id])
            ->with('success', ___('API token created. Copy it now — it will not be shown again.'));
    }

    public function deleteToken(int $id)
    {
        $token = ApiToken::where('user_id', auth()->id())->findOrFail($id);
        // P6-269: revoking an app sign-in also stops that device's pushes.
        app(MobilePush::class)->revokeDevices(['api_token_id' => (int) $token->id]);
        $token->delete();

        return back()->with('success', ___('API token deleted.'));
    }

    public function createWebhook(Request $request)
    {
        $data = $request->validate([
            'url' => 'required|url|max:255',
            'events' => 'required|array|min:1',
            // A4: validate against the single source of truth so every FIRED topic is
            // subscribable — the subscription.* family + app.uninstalled + `*` were all
            // dispatched by the app but rejected here, so no merchant could receive them.
            'events.*' => ['required', Rule::in(WebhookEndpoint::SELECTABLE_EVENTS)],
        ]);

        // GAP-4: reject non-https / private / loopback / metadata URLs up front
        // (delivery re-checks too, since DNS can change between create and delivery).
        if (! SsrfGuard::isSafePublicUrl($data['url'])) {
            return back()->withErrors([
                'url' => ___('Webhook URL must be a public HTTPS endpoint (no private, local or metadata addresses).'),
            ]);
        }

        $data['user_id'] = auth()->id();
        $data['secret'] = Str::random(32);

        WebhookEndpoint::create($data);

        return back()->with('success', ___('Webhook endpoint added.'));
    }

    public function deleteWebhook(int $id)
    {
        WebhookEndpoint::where('user_id', auth()->id())->findOrFail($id)->delete();

        return back()->with('success', ___('Webhook removed.'));
    }

    /** Pause / resume an endpoint without deleting it (and losing its secret). */
    public function toggleWebhook(int $id)
    {
        $webhook = WebhookEndpoint::where('user_id', auth()->id())->findOrFail($id);
        $resuming = ! $webhook->active;
        // P4-03: resuming starts a clean slate. Keeping the old streak (15) and the "auto-disabled" reason made the
        // very next failed delivery disable the endpoint again and left the red banner on the screen.
        $webhook->update($resuming
            ? ['active' => true, 'consecutive_failures' => 0, 'disabled_reason' => null]
            : ['active' => false]);

        return back()->with('success', $webhook->active
            ? ___('Webhook resumed.')
            : ___('Webhook paused.'));
    }

    /** Send a signed `ping` event to the endpoint so the owner can verify wiring. */
    public function testWebhook(int $id)
    {
        $webhook = WebhookEndpoint::where('user_id', auth()->id())->findOrFail($id);

        dispatch_job_reliably(new DeliverWebhookJob(
            $webhook->id,
            'ping',
            ['message' => 'This is a test event from your dashboard.'],
            'msg_'.Str::random(24),
            now()->getTimestamp(),
        ));

        return back()->with('success', ___('Test event sent. Check the delivery log below.'));
    }

    /**
     * A4: re-queue a single past delivery (e.g. one that failed while the endpoint URL
     * was down) with a FRESH message id, owner-scoped via the endpoint's user_id.
     */
    public function redeliverDelivery(int $id)
    {
        $delivery = WebhookDelivery::findOrFail($id);
        $endpoint = WebhookEndpoint::where('user_id', auth()->id())->findOrFail($delivery->endpoint_id);

        dispatch_job_reliably(new DeliverWebhookJob(
            $endpoint->id,
            (string) $delivery->event,
            (array) $delivery->payload,
            'msg_'.Str::random(24),
            now()->getTimestamp(),
        ));

        return back()->with('success', ___('Delivery re-queued.'));
    }
}

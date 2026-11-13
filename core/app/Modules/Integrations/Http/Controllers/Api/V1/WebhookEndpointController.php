<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Http\Controllers\Api\V1;

use Juwri\Core\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use Juwri\Core\Services\SsrfGuard;
use App\Support\ApiError;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * A5-6: programmatic webhook-endpoint management over the owner token API, so a
 * Zapier / Make REST-Hook (or any integrator) can subscribe and unsubscribe
 * without the session-authed dashboard. Reuses WebhookEndpoint + A4's
 * SELECTABLE_EVENTS + SsrfGuard — the same guarantees the dashboard form has.
 *
 * Owner scope: every read/write is keyed on the token owner (the `_api_user_id`
 * request attribute ApiTokenAuth binds), never client input.
 */
class WebhookEndpointController extends Controller
{
    private function ownerId(Request $request): int
    {
        return (int) $request->attributes->get('_api_user_id');
    }

    /** GET /api/v1/webhook-endpoints — the token owner's endpoints. */
    public function index(Request $request)
    {
        $rows = WebhookEndpoint::where('user_id', $this->ownerId($request))
            ->orderByDesc('id')->get()
            ->map(fn (WebhookEndpoint $e) => $this->present($e))
            ->all();

        return response()->json(['data' => $rows]);
    }

    /** POST /api/v1/webhook-endpoints — subscribe. Returns the signing secret ONCE. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'url'      => 'required|url|max:255',
            'events'   => 'required|array|min:1',
            'events.*' => ['required', Rule::in(WebhookEndpoint::SELECTABLE_EVENTS)],
        ]);

        // Re-checked at delivery too (DNS can change), but reject obvious SSRF up front.
        if (! SsrfGuard::isSafePublicUrl($data['url'])) {
            return ApiError::status(422, 'Webhook URL must be a public HTTPS endpoint (no private, local or metadata addresses).');
        }

        $endpoint = WebhookEndpoint::create([
            'user_id' => $this->ownerId($request),
            'url'     => $data['url'],
            'events'  => $data['events'],
            'secret'  => Str::random(32),
            'active'  => true,
        ]);

        // The secret is $hidden on the model and never returned again — surface it
        // once here so the integrator can verify the X-Webhook-Signature.
        return response()->json($this->present($endpoint, true), 201);
    }

    /** DELETE /api/v1/webhook-endpoints/{id} — unsubscribe (owner-scoped). */
    public function destroy(Request $request, int $id)
    {
        // B1-a: idempotent unsubscribe. A REST-Hook (Zapier/Make/n8n) re-sends the
        // delete when a Zap is turned off or replayed; a second delete of an
        // owner-scoped endpoint that is already gone is success, not a 404. (A row
        // owned by a DIFFERENT user is still invisible here — find() is owner-scoped
        // — so this never leaks another tenant's endpoints.)
        $endpoint = WebhookEndpoint::where('user_id', $this->ownerId($request))->find($id);
        if ($endpoint) {
            $endpoint->delete();
        }

        return response()->json(null, 204);
    }

    private function present(WebhookEndpoint $e, bool $withSecret = false): array
    {
        $out = [
            'id'         => $e->id,
            'url'        => $e->url,
            'events'     => $e->events,
            'active'     => (bool) $e->active,
            'created_at' => optional($e->created_at)->toIso8601String(),
        ];
        if ($withSecret) {
            $out['secret'] = $e->secret;
        }

        return $out;
    }
}

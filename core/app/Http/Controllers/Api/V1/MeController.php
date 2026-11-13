<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers\Api\V1;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Http\Request;

/**
 * F0-3 (integrations pack): the owner-token identity endpoint.
 *
 * Zapier / Make / n8n each validate a connection by calling a cheap "who am I"
 * request and labelling the connection from the response. There was no such
 * endpoint in the owner token group (the customer / staff / driver `me` routes
 * live in other guards), so integrators had nothing to point their auth test at.
 *
 * Reachable by ANY valid token (`ApiTokenAuth:any`) — a connection minted with
 * only `webhooks:write` must still be able to identify itself.
 */
class MeController extends Controller
{
    /** GET /api/v1/me — store + owner name, token abilities, and plan. */
    public function show(Request $request): \Illuminate\Http\JsonResponse
    {
        $token = $request->attributes->get('_api_token');
        $token = $token instanceof ApiToken ? $token : null;

        $user = $token?->user;
        if (! $user) {
            $user = User::find((int) $request->attributes->get('_api_user_id'));
        }
        $abilities = (array) ($token?->abilities ?: ['read']);
        $plan = $user?->plan();

        return response()->json([
            // The owner's first restaurant, used as the human label for the
            // connection. Null for an owner who has not created one yet.
            'store_name'      => $user ? Store::where('user_id', $user->id)->orderBy('id')->value('title') : null,
            'owner_name'      => $user?->name,
            'token_abilities' => array_values($abilities),
            // plan() returns a Plan model OR the configured trial/free plan object,
            // so read the name defensively.
            'plan'            => $plan ? (data_get($plan, 'name') ?: null) : null,
        ]);
    }
}

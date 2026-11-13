<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Middleware\Concerns;

use Illuminate\Http\Request;

/**
 * Removes any caller-supplied identity keys from the request BEFORE a token
 * middleware writes the real, token-derived identity into request attributes.
 *
 * Trusted identity must never live in the client-input namespace (query/body):
 * a route that reads `$request->input('_api_user_id')` without its middleware
 * would otherwise let an attacker pick their own identity. We strip all five
 * keys in every token middleware (defence in depth — not just the one it sets),
 * then the middleware calls `$request->attributes->set(...)`, which is the
 * server-controlled namespace and is not writable from client input.
 */
trait StripsClientIdentity
{
    /**
     * Keys that only the token middlewares may set — never the client.
     *
     * @var list<string>
     */
    private array $identityKeys = [
        '_api_user_id', '_api_token', '_customer_id', '_staff_id', '_driver_id',
    ];

    protected function stripClientIdentity(Request $request): void
    {
        foreach ($this->identityKeys as $key) {
            $request->query->remove($key);
            $request->request->remove($key);
            $request->attributes->remove($key);
            $request->json()->remove($key);
        }
    }
}

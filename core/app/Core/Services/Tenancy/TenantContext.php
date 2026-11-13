<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

use App\Models\StaffMember;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Log\Context\Repository;
use Illuminate\Support\Facades\Context;
use Juwri\Core\Http\Middleware\SetTenantContext;
use Juwri\Core\Models\User;

/**
 * P1-04 slice 5: the tenant of the current owner-facing request (J29-2 app-scoping layer).
 *
 * Opened by {@see SetTenantContext} on authenticated owner/staff/API routes only
 * (never on public storefront, marketplace, reseller or platform-admin routes) and closed when the request
 * ends. The subject is resolved lazily on the first tenant query — by then the route's auth middleware has
 * run — from, in order: a staff API token, an owner API token, a staff dashboard session, the logged-in user.
 * A staff subject is also limited to its store. Platform admins are never scoped.
 *
 * {@see self::bypass()} is the explicit opt-out for platform code paths that must read across accounts
 * (e.g. a transfer offer addressed to another account). Bound as a scoped singleton: one per request/app.
 */
final class TenantContext
{
    private ?Request $request = null;

    private bool $resolved = false;

    private bool $resolving = false;

    private int $bypass = 0;

    private bool $scoped = false;

    private ?int $accountId = null;

    /** The users.id of a resolved owner subject (null for staff / pinned subjects). */
    private ?int $userId = null;

    /** @var list<int>|null */
    private ?array $storeIds = null;

    public static function current(): self
    {
        return app(self::class);
    }

    public function begin(Request $request): void
    {
        $this->request = $request;
        $this->resolved = false;
    }

    public function end(): void
    {
        $this->request = null;
        $this->resolved = false;
        $this->scoped = false;
        $this->accountId = null;
        $this->userId = null;
        $this->storeIds = null;
        $this->publishLogContext();
    }

    /** Pin "no tenant" (a platform job, or a job dispatched outside any tenant context). */
    public function clear(): void
    {
        $this->end();
        $this->resolved = true;
    }

    /**
     * The subject a queued job must carry (slice 7), or null when nothing is scoped right now.
     *
     * @return array{account_id: int|null, store_ids: list<int>|null}|null
     */
    public function subject(): ?array
    {
        return $this->active() ? ['account_id' => $this->accountId, 'store_ids' => $this->storeIds] : null;
    }

    /** @return array<string, mixed> the whole state (a sync job saves it and gives it back afterwards) */
    public function exportState(): array
    {
        return [
            'request' => $this->request, 'resolved' => $this->resolved, 'scoped' => $this->scoped,
            'accountId' => $this->accountId, 'userId' => $this->userId, 'storeIds' => $this->storeIds,
        ];
    }

    /** @param  array<string, mixed>  $state */
    public function importState(array $state): void
    {
        $this->request = $state['request'] ?? null;
        $this->resolved = (bool) ($state['resolved'] ?? false);
        $this->scoped = (bool) ($state['scoped'] ?? false);
        $this->accountId = $state['accountId'] ?? null;
        $this->userId = $state['userId'] ?? null;
        $this->storeIds = $state['storeIds'] ?? null;
        $this->publishLogContext();
    }

    /**
     * Session separation (slice 7, J29-4): the session remembers which account it was last used for; when the
     * subject's account differs (ownership transfer, impersonation hand-over, another owner signing in on the same
     * browser session) the per-tenant keys (selected store/branch, OAuth states — config tenancy.session.tenant_keys)
     * are dropped before they can point the new subject at the previous account's store.
     */
    private function separateSession(): void
    {
        if (! $this->scoped || $this->request === null || ! $this->request->hasSession()) {
            return;
        }
        $session = $this->request->session();
        if (! $session->isStarted()) {
            return;
        }
        $now = $this->accountId ?? 0;
        $prev = $session->get('juwri.tenant_account');
        if ($prev !== null && (int) $prev !== $now) {
            $session->forget((array) config('tenancy.session.tenant_keys', []));
        }
        if ($prev === null || (int) $prev !== $now) {
            $session->put('juwri.tenant_account', $now);
        }
    }

    /**
     * account_id / store_id on every log line once the tenant is known (J29-4): Laravel adds Context data to each
     * log record (and carries it into queued jobs). Removed again when the context ends.
     */
    private function publishLogContext(): void
    {
        if (! app()->bound(Repository::class)) {
            return;
        }
        if ($this->resolved && $this->scoped) {
            Context::add('account_id', $this->accountId);
            $this->storeIds !== null && count($this->storeIds) === 1
                ? Context::add('store_id', $this->storeIds[0])
                : Context::forget('store_id');
        } else {
            Context::forget(['account_id', 'store_id']);
        }
    }

    /**
     * Pin a subject explicitly (queue jobs, console, tests).
     *
     * @param  list<int>|null  $storeIds
     */
    public function actAs(?int $accountId, ?array $storeIds = null): void
    {
        $this->request = null;
        $this->resolved = true;
        $this->scoped = true;
        $this->accountId = $accountId;
        $this->userId = null;
        $this->storeIds = $storeIds === null ? null : array_map('intval', $storeIds);
        $this->publishLogContext();
    }

    /**
     * An account was just created for $userId (their first store, {@see AccountProvisioner}). When that user is the
     * resolved subject of this context, the context follows at once — otherwise the rest of the request (the store
     * insert itself, its default branch …) would still run as "owner without account": invisible to the app scope
     * and refused by the RLS WITH CHECK (slice 6).
     */
    public function accountProvisioned(int $userId, int $accountId): void
    {
        if ($this->resolved && $this->scoped && $this->userId === $userId && $this->storeIds === null) {
            $this->accountId = $accountId;
            $this->publishLogContext();
        }
    }

    /**
     * Run $callback without tenant scoping (explicit platform opt-out).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function bypass(Closure $callback): mixed
    {
        $ctx = self::current();
        $ctx->bypass++;
        try {
            return $callback();
        } finally {
            $ctx->bypass--;
        }
    }

    /** True when queries must be filtered right now. */
    public function active(): bool
    {
        if ($this->bypass > 0 || $this->resolving) {
            return false;
        }
        if (! $this->resolved) {
            if ($this->request === null) {
                return false;
            }
            $this->resolve();
        }

        return $this->scoped;
    }

    /**
     * Like {@see self::active()}, but never the call that triggers authentication: used by the database hook
     * (slice 6) that runs before EVERY query, including the session / token / user lookups of the auth middleware
     * itself. Until a subject is settled (token resolved, staff session, a user already loaded by a guard) the
     * request counts as not-yet-scoped — the same as the app scope, which also resolves no subject before auth.
     */
    public function activeWhenSettled(): bool
    {
        if ($this->bypass > 0 || $this->resolving) {
            return false;
        }
        if (! $this->resolved) {
            if ($this->request === null || ! $this->subjectSettled()) {
                return false;
            }
            $this->resolve();
        }

        return $this->scoped;
    }

    private function subjectSettled(): bool
    {
        if (app()->bound('api.staff') || $this->request?->attributes->get('_api_user_id')) {
            return true;
        }
        if ($this->request?->hasSession()) {
            $session = $this->request->session();
            if ($session->isStarted() && $session->has('staff_session_id')) {
                return true;
            }
        }
        try {
            return auth()->guard()->hasUser();
        } catch (\Throwable) {
            return false;
        }
    }

    public function accountId(): ?int
    {
        return $this->active() ? $this->accountId : null;
    }

    /** @return list<int>|null null = every store of the account */
    public function storeIds(): ?array
    {
        return $this->active() ? $this->storeIds : null;
    }

    private function resolve(): void
    {
        $this->resolving = true;
        try {
            $this->scoped = false;
            $this->accountId = null;
            $this->userId = null;
            $this->storeIds = null;
            $staff = null;
            $user = null;

            if (app()->bound('api.staff')) {
                $staff = app('api.staff');
            } elseif ($this->request?->attributes->get('_api_user_id')) {
                $user = User::find((int) $this->request->attributes->get('_api_user_id'));
            } elseif (function_exists('staff_session') && ($s = staff_session())) {
                $staff = $s;
            } else {
                $subject = $this->request?->user();
                if ($subject instanceof StaffMember) {
                    $staff = $subject; // auth:staff routes
                } elseif ($subject instanceof User) {
                    $user = $subject;
                }
            }

            if ($staff !== null) {
                $storeId = (int) ($staff->store_id ?? 0);
                $this->scoped = true;
                $this->accountId = $staff->account_id ? (int) $staff->account_id
                    : ($storeId ? TenantFill::accountForStore(app('db')->connection(), $storeId) : null);
                $this->storeIds = $storeId ? [$storeId] : [];
            } elseif ($user instanceof User && $user->user_type !== 'admin') {
                $this->scoped = true;
                // Read from the database, not the in-memory user: the account may have been created (with
                // the first store) after this user object was loaded.
                $account = app('db')->connection($user->getConnectionName())->table('users')->where('id', $user->getKey())->value('account_id');
                $this->accountId = $account !== null ? (int) $account : null;
                $this->userId = (int) $user->getKey();
            }
            // No subject yet (a query before the session/token was read): try again on the next query.
            $this->resolved = $staff !== null || $user !== null;
            if ($this->resolved) {
                $this->publishLogContext();
                $this->separateSession();
            }
        } finally {
            $this->resolving = false;
        }
    }
}

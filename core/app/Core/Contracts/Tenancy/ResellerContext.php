<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Juwri\Core\Editions\Community\NoResellers;
use Juwri\Core\Models\User;

/**
 * P1-13 extension point: resellers (white-label partners; the Reseller module, ee). Community has none
 * ({@see NoResellers}). Rows come back as plain models so Community code never names
 * an ee class.
 */
interface ResellerContext
{
    /** Session flag: this session passed the reseller portal's two-factor challenge (sign-in flows set/clear it). */
    public const TWO_FACTOR_PASSED = 'reseller.2fa_passed';

    /** The active reseller whose own domain serves this request (host match), or null. */
    public function forRequest(): ?Model;

    /** The reseller an owner signed up under (users.reseller_id), or null. */
    public function forUser(User $user): ?Model;

    public function find(int $resellerId): ?Model;

    /** @return list<int> every reseller id (per-tenant caches) */
    public function ids(): array;

    /** Does this user own a reseller ($activeOnly: an active one)? */
    public function ownsReseller(int $userId, bool $activeOnly = false): bool;

    /**
     * Active resellers' custom domains (trusted hosts).
     *
     * @return list<string>
     */
    public function activeCustomDomains(): array;

    /** Is this host (or its www-less form) an active reseller's custom domain? */
    public function isActiveCustomDomain(string $host): bool;

    /**
     * BL-036 R2: does any reseller hold this host (its www / bare twin included) — as its live custom domain, or as a
     * requested domain that has not gone stale? A store may not claim such a host.
     */
    public function holdsDomain(string $host): bool;

    /**
     * The announcements the owner's reseller shows them now (dismissed ones excluded).
     *
     * @return Collection<int, covariant Model>
     */
    public function announcementsFor(User $user): Collection;

    /** Account closure: cancel the customer's subscriptions to their reseller's plans. */
    public function cancelCustomerSubscriptions(User $user): void;

    /**
     * Account closure: tell the customer's reseller (webhook/notification `customer.deleted`).
     *
     * @param  array<string, mixed>  $payload
     */
    public function notifyCustomerDeleted(User $user, array $payload): void;

    /**
     * The users who own a reseller (admin security overview, P1-13 13f).
     *
     * @return list<int>
     */
    public function ownerUserIds(): array;

    /**
     * Write a row to the reseller's audit log (platform restore of a customer's store, impersonation steps).
     *
     * @param  array<string, mixed>  $meta
     */
    public function audit(int $resellerId, string $action, array $meta = [], ?int $actorUserId = null): void;

    /** A reseller tier entitlement (e.g. `remove_powered_by`). */
    public function entitled(Model $reseller, string $key): bool;
}

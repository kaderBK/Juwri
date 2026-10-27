<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Data;

/**
 * Where an SMS one-time code belongs. A code, its attempt counter and its request rate limit are keyed by scope AND
 * phone, so a code sent for one store (which may use its own SMS provider and can read its own SMS log) never proves
 * the phone at another store, at the marketplace or in the driver app, and one store's traffic never throttles another.
 *
 * Callers build the scope from server-side context only (the store resolved from the route, the marketplace tenant
 * resolved from the host, ...), never from request input.
 */
final class OtpScope
{
    private function __construct(
        public readonly string $key,
        /** The store whose SMS account sends (and pays for) the code; null = the platform account. */
        public readonly ?int $billingStoreId,
    ) {}

    /** A storefront flow of one store (login, passkey fresh proof, table verify, checkout gate). */
    public static function store(int $storeId): self
    {
        if ($storeId <= 0) {
            throw new \InvalidArgumentException('A store OTP scope needs a store id.');
        }

        return new self('store:'.$storeId, $storeId);
    }

    /**
     * Loyalty recognition at one store's in-store kiosk (item 26): its own scope, so a code sent to a phone at the kiosk
     * never signs that phone in on the storefront and the storefront's codes never recognise anyone at the kiosk.
     */
    public static function kiosk(int $storeId): self
    {
        if ($storeId <= 0) {
            throw new \InvalidArgumentException('A kiosk OTP scope needs a store id.');
        }

        return new self('kiosk:'.$storeId, $storeId);
    }

    /**
     * P6-209: a store owner's guest privacy request (export / erase by phone). Its own scope, so a code sent to prove
     * the phone for a data request never signs that phone in on the storefront, and a storefront code never unlocks
     * a guest's order history for the owner.
     */
    public static function privacy(int $storeId): self
    {
        if ($storeId <= 0) {
            throw new \InvalidArgumentException('A privacy OTP scope needs a store id.');
        }

        return new self('privacy:'.$storeId, $storeId);
    }

    /** Marketplace shopper login of one marketplace tenant (0 = the platform marketplace). Platform SMS account. */
    public static function marketplace(int $resellerId): self
    {
        return new self('marketplace:'.max(0, $resellerId), null);
    }

    /** Driver app login (fleet + store drivers). Platform SMS account. */
    public static function driver(): self
    {
        return new self('driver', null);
    }

    /** A platform-level flow with no store (e.g. the checkout gate sent without a store). Platform SMS account. */
    public static function platform(string $flow): self
    {
        $flow = (string) preg_replace('/[^a-z0-9_-]/', '', strtolower($flow));
        if ($flow === '') {
            throw new \InvalidArgumentException('A platform OTP scope needs a flow name.');
        }

        return new self('platform:'.$flow, null);
    }

    public function equals(self $other): bool
    {
        return $this->key === $other->key;
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Contracts;

/**
 * P5-17: the Google Play side of in-app billing — the Play Developer API (purchases.subscriptionsv2) and the check of a
 * Pub/Sub push's OIDC token (RTDN). Implemented over HTTP with the service-account key from config; tests bind a fake.
 */
interface GooglePlayBilling
{
    /**
     * The SubscriptionPurchaseV2 resource of a purchase token (subscriptionState, latestOrderId, linkedPurchaseToken,
     * lineItems[{productId, expiryTime}], externalAccountIdentifiers, acknowledgementState, testPurchase).
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when Google cannot be asked (retry later)
     */
    public function subscription(string $packageName, string $purchaseToken): array;

    /** Acknowledge a new purchase (Google refunds one not acknowledged within three days). */
    public function acknowledge(string $packageName, string $productId, string $purchaseToken): void;

    /** Is this `Authorization: Bearer` token a Google-signed OIDC token for our push audience and push service account? */
    public function verifyPushToken(string $bearer): bool;
}

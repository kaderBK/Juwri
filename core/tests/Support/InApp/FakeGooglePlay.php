<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support\InApp;

use Juwri\Modules\Payments\Contracts\GooglePlayBilling;
use Juwri\Modules\Payments\Services\InApp\InAppRejected;

/** P5-17 tests: the Play Developer API and the Pub/Sub OIDC check, scripted per purchase token. */
final class FakeGooglePlay implements GooglePlayBilling
{
    /** @var array<string, array<string, mixed>> */
    public array $subs = [];

    /** @var list<string> */
    public array $acknowledged = [];

    public string $goodBearer = 'good-oidc-token';

    public function subscription(string $packageName, string $purchaseToken): array
    {
        return $this->subs[$purchaseToken] ?? throw new InAppRejected('unknown_purchase');
    }

    public function acknowledge(string $packageName, string $productId, string $purchaseToken): void
    {
        $this->acknowledged[] = $purchaseToken;
        $this->subs[$purchaseToken]['acknowledgementState'] = 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED';
    }

    public function verifyPushToken(string $bearer): bool
    {
        return $bearer !== '' && hash_equals($this->goodBearer, $bearer);
    }
}

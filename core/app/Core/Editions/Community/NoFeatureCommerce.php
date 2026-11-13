<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions\Community;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Contracts\Billing\FeatureCommerce;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;

/** P1-13 Community default: nothing is sold, so there is no upsell, activation checklist or dormancy stamp. */
final class NoFeatureCommerce implements FeatureCommerce
{
    public function sellable(string $key): bool
    {
        return false;
    }

    public function sellableKeys(): array
    {
        return [];
    }

    public function addonSkuFor(string $key): ?string
    {
        return null;
    }

    public function bundlesApp(int $appId): bool
    {
        return false;
    }

    public function touch(?User $owner, string $key): void {}

    public function activationProgress(string $key, ?Store $store, ?User $owner = null): array
    {
        return [];
    }

    public function activationOutstanding(?User $owner, ?Store $store): array
    {
        return [];
    }

    public function upsell(string $key): ?array
    {
        return null;
    }

    public function limitOffer(string $type, ?User $owner): ?array
    {
        return null;
    }

    public function spotlightDismissed(int $userId, string $key, string $surface): bool
    {
        return false;
    }

    public function spotlightSeen(int $userId, string $key, string $surface): bool
    {
        return false;
    }

    public function recordSpotlight(array $attributes): ?Model
    {
        return null;
    }
}

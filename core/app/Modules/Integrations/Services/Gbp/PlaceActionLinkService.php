<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Gbp;

use App\Services\FeatureAccess;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Credential;

/**
 * GAPS-RUN-2 B4-P1: set a restaurant's "Order online" link on Google Search/Maps as
 * the PREFERRED FOOD_ORDERING place action link. Order-with-Google in-checkout is
 * dead; this preferred link IS the integration. Idempotent: an existing MERCHANT
 * FOOD_ORDERING link is patched, never duplicated.
 */
class PlaceActionLinkService
{
    private const ACTION_TYPE = 'FOOD_ORDERING';

    /**
     * FIX-2 (gap audit): service-level gate. The owner route group carries
     * `feature:gbp_link`, but this service is the thing that actually writes the
     * link on Google and was kill-switch-only — any future caller (job, API,
     * import) would have published the paid link for an unentitled store. Kill
     * veto always; entitlement too once the key is `plan_gated`. Delegates to the
     * shared serve-time twin of the `feature:` middleware.
     */
    private static function allowedFor(int $storeId): bool
    {
        return FeatureAccess::storeAllows($storeId, 'gbp_link');
    }

    /** Upsert the preferred ordering link for a BYOK-connected location. */
    public function setOrderingLink(int $storeId, string $uri): array
    {
        if (! self::allowedFor($storeId)) {
            throw ValidationException::withMessages(['gbp' => 'Google ordering link is not available.']);
        }

        $creds = Credential::for('gbp', $storeId);
        $locationId = $creds['location_id'] ?? null;
        if (empty($creds) || empty($locationId)) {
            throw ValidationException::withMessages(['gbp' => 'Connect your Google Business location first.']);
        }

        $client = GbpClientFactory::make($storeId);
        $existing = array_find($client->listActionLinks((string) $locationId), fn ($link) => ($link['placeActionType'] ?? null) === self::ACTION_TYPE
            && ($link['providerType'] ?? 'MERCHANT') === 'MERCHANT');

        if ($existing) {
            return $client->patchActionLink($existing['name'], [
                'uri' => $uri,
                'isPreferred' => true,
            ]);
        }

        return $client->createActionLink((string) $locationId, [
            'uri' => $uri,
            'placeActionType' => self::ACTION_TYPE,
            'isPreferred' => true,
        ]);
    }

    /** The current MERCHANT ordering link uri, or null. */
    public function currentLink(int $storeId): ?string
    {
        $creds = Credential::for('gbp', $storeId);
        $locationId = $creds['location_id'] ?? null;
        if (empty($locationId)) {
            return null;
        }
        foreach (GbpClientFactory::make($storeId)->listActionLinks((string) $locationId) as $link) {
            if (($link['placeActionType'] ?? null) === self::ACTION_TYPE) {
                return $link['uri'] ?? null;
            }
        }

        return null;
    }
}

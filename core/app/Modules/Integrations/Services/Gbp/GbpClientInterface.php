<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Gbp;

/**
 * GAPS-RUN-2 B4-P1 Google Business Profile client (Place Actions API v1). Real client
 * uses OAuth2 (business.manage scope, approval-gated); the Fake records links for CI.
 */
interface GbpClientInterface
{
    /** List place action links for a location: [ ['name'=>..,'uri'=>..,'placeActionType'=>..,'providerType'=>..,'isPreferred'=>bool], ... ]. */
    public function listActionLinks(string $locationId): array;

    /** Create a place action link. Returns the created link. */
    public function createActionLink(string $locationId, array $body): array;

    /** Patch an existing link by its resource name. Returns the updated link. */
    public function patchActionLink(string $name, array $body): array;

    // ===== google-partner Lane A — connect + menu sync surface =====

    /**
     * List the GBP accounts the connected user administers.
     * Each: ['name'=>'accounts/123', 'accountName'=>..., 'type'=>..., 'role'=>...].
     *
     * @return array<int, array<string,mixed>>
     */
    public function listAccounts(): array;

    /**
     * List locations under an account (name = 'accounts/123').
     * Each: ['name'=>'locations/456' (or 'accounts/123/locations/456'), 'title'=>..., 'storefrontAddress'=>...].
     *
     * @return array<int, array<string,mixed>>
     */
    public function listLocations(string $account): array;

    /**
     * Read the FoodMenus resource for a location. Returns ['name'=>..., 'menus'=>[...]] or [].
     *
     * @return array<string,mixed>
     */
    public function getFoodMenus(string $locationId): array;

    /**
     * Replace the FoodMenus resource for a location (PATCH, full replace via updateMask=menus).
     * $foodMenus is the FoodMenus body (['menus'=>[FoodMenu,...]]). Returns the updated resource.
     *
     * @param  array<string,mixed>  $foodMenus
     * @return array<string,mixed>
     */
    public function updateFoodMenus(string $locationId, array $foodMenus): array;
}

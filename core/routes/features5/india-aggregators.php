<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * PARITY-RUN T7 · Zomato + Swiggy routes. Owned exclusively by the T7 track.
 * Auto-required by the glob in routes/web.php, which runs BEFORE the {slug}
 * storefront catch-all, so every path declared here resolves instead of being
 * swallowed by publicView.
 *
 * Two zones with deliberately different gating:
 *
 *   Partner webhook  → NO feature:/entitled: middleware, ever. The caller is
 *                      Zomato's or Swiggy's server — an unauthenticated third
 *                      party with no session — so those aliases would resolve
 *                      their subject from a session that does not exist and 403
 *                      the partner forever. The entitlement question is asked
 *                      store-scoped INSIDE the controller
 *                      (IndiaAggregatorSync::enabled → FeatureAccess::storeAllows),
 *                      and only after the signature has proved which store the
 *                      caller speaks for. This mistake has bitten the codebase 3×.
 *
 *   Owner dashboard  → `feature:aggregator_zomato` / `feature:aggregator_swiggy`.
 *                      The caller is the authenticated merchant, which is exactly
 *                      the subject that middleware resolves; a kill-switched
 *                      platform should hide the whole screen rather than serve
 *                      controls that cannot work.
 *
 * The URI stays under the explicit `webhook/*` CSRF exemption because a
 * partner has no session and no CSRF token to send.
 */

\Juwri\Core\Support\ModuleRoutes::slot('Channels/features5-india-aggregators-1');

\Juwri\Core\Support\ModuleRoutes::slot('Channels/features5-india-aggregators-2');

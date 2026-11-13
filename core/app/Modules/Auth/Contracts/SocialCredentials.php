<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Contracts;

use Juwri\Modules\Auth\Services\Social\SocialCredentialResolver;

/**
 * P1-08 L1 public surface: customer social sign-in credentials (merchant or platform keys) for modules above Auth
 * (Customers). Implemented by {@see SocialCredentialResolver}.
 */
interface SocialCredentials
{
    /** Providers customers may sign in with. */
    public const PROVIDERS = ['google', 'facebook', 'apple'];

    public function isEnabled(string $provider, ?int $storeId = null): bool;

    /** Point the Socialite driver at the right credentials; false when the provider is not available. */
    public function configure(string $provider, ?int $storeId, string $redirectUrl): bool;
}

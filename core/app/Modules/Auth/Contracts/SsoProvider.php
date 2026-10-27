<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Contracts;

use Juwri\Modules\Auth\Services\Sso\NoSsoProvider;

/**
 * P1-13 13h extension point (EDITIONS: Auth — SSO/SAML/SCIM → ee): enterprise single sign-on for an account's staff
 * (SAML / OIDC) and SCIM user provisioning. Community has none ({@see NoSsoProvider}): password, 2FA, staff PIN,
 * phone OTP, passkeys and social sign-in are the Community sign-in methods. An ee implementation binds this
 * contract; no sign-in screen consults it yet (nothing is built — the contract only fixes the seam).
 */
interface SsoProvider
{
    /** Is single sign-on (SAML / OIDC) configured for this account? */
    public function ssoEnabled(int $accountId): bool;

    /**
     * The identity-provider connections staff of this account may sign in with.
     *
     * @return list<array{key: string, label: string, protocol: 'saml'|'oidc'}>
     */
    public function connections(int $accountId): array;

    /** Does this account provision its users through SCIM? */
    public function scimEnabled(int $accountId): bool;
}

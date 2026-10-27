<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Contracts;

/**
 * The partner-credential connector clients by connection key, for code outside Channels (the juwri Cloud partner
 * relay): which client interface a partner has, and that client built with the platform's own partner account
 * (services.* config — real when configured, else the connector's Fake).
 */
interface PartnerClients
{
    /** @return class-string|null the partner's connector-client interface, or null when it is not a relayable partner */
    public function interfaceFor(string $partner): ?string;

    /** The partner's client with the platform account (not a store's credentials). */
    public function platformClient(string $partner): object;
}

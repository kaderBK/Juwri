<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Editions;

use Juwri\Core\Editions\Community\NoInstallLicence;
use Juwri\Core\Services\HostedServices\HostedServiceClient;

/**
 * What a licensed installation shows a juwri-hosted service when it connects ({@see HostedServiceClient::register()}):
 * its licence ID and the SHA-256 of the licence file it installed (proof it holds the file, not just the ID that is
 * printed in admin footers). Community default ({@see NoInstallLicence}): none — a Community installation connects
 * with a free Community install ID instead. The Enterprise package binds the verified licence.
 *
 * The edition itself is never sent: the service reads it from JUWRI LTD's issued-licence register.
 */
interface InstallLicenceProof
{
    /** The verified licence's ID, or null (Community / no active licence). */
    public function licenceId(): ?string;

    /** Lowercase hex SHA-256 of the installed licence file's bytes, or null. */
    public function licenceFileSha256(): ?string;
}

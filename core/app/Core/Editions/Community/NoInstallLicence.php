<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions\Community;

use Juwri\Core\Contracts\Editions\InstallLicenceProof;

/** Community default of {@see InstallLicenceProof}: no licence — the installation connects with a Community install ID. */
final class NoInstallLicence implements InstallLicenceProof
{
    public function licenceId(): ?string
    {
        return null;
    }

    public function licenceFileSha256(): ?string
    {
        return null;
    }
}

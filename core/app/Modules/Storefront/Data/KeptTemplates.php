<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Data;

use Juwri\Modules\Storefront\Services\Theme\KeptTemplates as BaseKeptTemplates;

/** Public Data facade for KeptTemplates so other modules do not import Storefront\Services directly. */
final class KeptTemplates
{
    public const MODERN = BaseKeptTemplates::MODERN;
}

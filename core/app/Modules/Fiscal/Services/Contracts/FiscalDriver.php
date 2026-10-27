<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Contracts;

use Juwri\Modules\Fiscal\Contracts\FiscalAdapter;

/**
 * @deprecated J25-8: renamed to {@see FiscalAdapter} (one adapter per country). Kept as an alias interface so
 * existing implementations and type hints (and the legacy App\Services\Fiscal\Contracts\FiscalDriver name) keep
 * working; every FiscalDriver is a FiscalAdapter.
 */
interface FiscalDriver extends FiscalAdapter {}

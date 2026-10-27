<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Verticals\Services\Fitment;

/** P3-08b: a store fitment CSV that cannot be read at all (not one bad row: those are reported and skipped). */
final class FitmentImportException extends \RuntimeException {}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Rendering;

use RuntimeException;

/**
 * P1: an untrusted render breached its budget or errored. The caller (storefront)
 * MUST catch this and fall back to a safe default — a diner never sees a blank
 * menu or a stack trace.
 */
class RenderFailedException extends RuntimeException
{
}

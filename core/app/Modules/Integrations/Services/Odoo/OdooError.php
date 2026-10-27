<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Odoo;

use RuntimeException;

/**
 * P4-21: an Odoo call or mapping that did not go through. The message is shown to the owner (never a secret);
 * `permanent` = retrying the same thing will not help (wrong key, a mapping to fix) — the record still keeps its
 * retry schedule, so a fixed mapping is picked up without the owner doing anything.
 */
final class OdooError extends RuntimeException
{
    public function __construct(string $message, public readonly bool $permanent = false)
    {
        parent::__construct($message);
    }
}

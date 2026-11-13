<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge;

/**
 * GAPS-RUN-2 B1: outcome of pushing one order to a POS provider.
 */
class PosPushResult
{
    public function __construct(
        public bool $ok,
        public ?string $externalId = null,
        public ?string $error = null,
        public array $raw = [],
    ) {}

    public static function success(string $externalId, array $raw = []): self
    {
        return new self(true, $externalId, null, $raw);
    }

    public static function failure(string $error, array $raw = []): self
    {
        return new self(false, null, $error, $raw);
    }
}

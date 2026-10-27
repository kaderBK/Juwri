<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca;

/** A FATOORA call that failed (HTTP error, bad OTP, malformed answer). Message is safe to show the owner (no secrets). */
class ZatcaApiException extends \RuntimeException
{
    /** @var array<string, mixed> */
    public array $body;

    /** @param  array<string, mixed>  $body */
    public function __construct(string $message, public readonly int $httpStatus = 0, array $body = [])
    {
        parent::__construct($message);
        $this->body = $body;
    }
}

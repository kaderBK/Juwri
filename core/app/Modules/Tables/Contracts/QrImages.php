<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Contracts;

/** P6-172: a plain QR code image for other modules (the order receipt PDF), without reaching into Tables' renderer. */
interface QrImages
{
    /** PNG bytes of a plain QR code carrying $payload. */
    public function png(string $payload): string;
}

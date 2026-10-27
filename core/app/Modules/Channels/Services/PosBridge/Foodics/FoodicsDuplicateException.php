<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge\Foodics;

use RuntimeException;

/**
 * Thrown when Foodics rejects a createOrder with a 422 because the client UUID id was
 * already used — the natural idempotency signal. The driver catches it and treats the
 * push as already-succeeded (never a hard failure, never a duplicate).
 */
class FoodicsDuplicateException extends RuntimeException
{
}

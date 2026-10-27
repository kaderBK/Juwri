<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\InApp;

use RuntimeException;

/** P5-17: a purchase or notification that does not pass verification; the message is a stable reason code. */
final class InAppRejected extends RuntimeException {}

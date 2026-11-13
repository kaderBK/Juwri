<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Switching;

use RuntimeException;

/** P4-22: a switching file or source that cannot be imported; the message is shown to the owner (translated). */
final class SwitchError extends RuntimeException {}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Services\Offline;

use RuntimeException;

/** The till was revoked by the owner: it may only upload what it holds and wipe itself. */
final class DeviceRevoked extends RuntimeException {}

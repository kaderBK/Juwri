<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Media\Services;

/**
 * Thrown by MediaCopyService when a remote image cannot be downloaded/copied.
 * Carries a user-facing message (already localised) for the controller to relay.
 */
class MediaCopyException extends \RuntimeException implements \Juwri\Modules\Media\Contracts\MediaCopyFailed
{
}

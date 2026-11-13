<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Media\Contracts;

/** P1-08 L1 public surface: thrown by a {@see MediaCopier} when an image cannot be copied (catchable by other modules). */
interface MediaCopyFailed extends \Throwable {}

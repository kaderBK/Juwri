<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Contracts;

/** P1-08 L1 public surface: every AI provider in the chain failed (catchable by other modules). */
interface AiProviderFailure extends \Throwable {}

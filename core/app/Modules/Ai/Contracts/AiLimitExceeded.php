<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Contracts;

/** P1-08 L1 public surface: the AI usage limit is reached (catchable by other modules). */
interface AiLimitExceeded extends \Throwable {}

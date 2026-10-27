<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Grocery;

/** P3-09: a picking step that cannot be applied (wrong state, bad weight, a replacement above the line); the message is for the screen. */
final class GroceryPickingException extends \RuntimeException {}

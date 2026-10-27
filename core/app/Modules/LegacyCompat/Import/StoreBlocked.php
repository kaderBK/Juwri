<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

use RuntimeException;

/**
 * A store whose money does not reconcile (a ledger that does not sum to its balance): its import transaction is rolled
 * back and the store is reported as BLOCKED (plan §4, §6: a mismatch blocks the store's cut-over; it is never "fixed").
 * The message names ids and the check only, never amounts of a person.
 */
final class StoreBlocked extends RuntimeException {}

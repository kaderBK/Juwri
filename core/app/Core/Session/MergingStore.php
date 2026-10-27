<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Session;

use Illuminate\Session\Store;

/** P6-136: session store whose save keeps concurrent writes (see MergesConcurrentWrites). */
class MergingStore extends Store
{
    use MergesConcurrentWrites;
}

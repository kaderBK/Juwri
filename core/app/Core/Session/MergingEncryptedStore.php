<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Session;

use Illuminate\Session\EncryptedStore;

/** P6-136: encrypted variant (session.encrypt=true) of MergingStore. */
class MergingEncryptedStore extends EncryptedStore
{
    use MergesConcurrentWrites;
}

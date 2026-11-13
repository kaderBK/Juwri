<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Events;

use Carbon\CarbonInterface;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Models\EmailChangeUndo;

/**
 * BL-036 A1a: the old address used "This wasn't me — undo" on a sign-in email change.
 * Dispatched synchronously INSIDE the undo transaction, after the email is restored and
 * the account locked. Modules that hold access granted since the change remove it
 * (Integrations: API tokens minted since $since) and append what they removed to
 * $cleared, so the undo row, the activity log and the audit trail list it.
 * Owner-app sign-outs ride the CredentialsChanged event dispatched after the commit.
 */
class EmailChangeUndone
{
    /** @var list<string> what listeners removed or paused (e.g. api_tokens) */
    public array $cleared = [];

    /** @var array<string, mixed> extra facts listeners record on the undo row (e.g. paused webhook ids) */
    public array $meta = [];

    public function __construct(
        public User $user,
        public EmailChangeUndo $undo,
        public CarbonInterface $since,
    ) {}
}

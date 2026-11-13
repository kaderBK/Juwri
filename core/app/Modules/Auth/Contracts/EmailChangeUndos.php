<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Contracts;

use Illuminate\Support\Carbon;
use Juwri\Core\Models\User;

/**
 * BL-036 A1a: the "This wasn't me — undo" link for the OLD address after a sign-in email
 * change (implemented by Auth\Services\EmailChangeUndoService). Any screen that changes a
 * user's sign-in email mints one and puts it in the notice to the old address.
 */
interface EmailChangeUndos
{
    /** Reseller email changes and reseller "login as" are refused this long after an undo of a reseller-made change. */
    public const RESELLER_BLOCK_SECONDS = 7 * 86400;

    /**
     * Record a pending undo and return its absolute link, or null when there is nothing to
     * undo or it could not be stored (the notice then goes out without a link).
     *
     * @param  string  $source  EmailChangeUndo::SOURCE_SELF | SOURCE_RESELLER
     */
    public function tryIssue(User $user, string $oldEmail, string $newEmail, string $source, ?int $resellerId = null, ?int $actorId = null): ?string;

    /** The plain-text notice to the old address after a self-service change, with the link when there is one. */
    public function selfChangeNotice(?string $url): string;

    /** The undo paragraph alone (plain text; '' without a link). */
    public function undoParagraph(?string $url): string;

    /**
     * After the customer undid a RESELLER-made change: until when reseller email changes and
     * reseller "login as" stay refused for this user (null = not blocked).
     */
    public function resellerBlockedUntil(User $user): ?Carbon;

    /**
     * Is $email the old address of a live undo link (of another user than $exceptUserId)? Such an address
     * stays reserved for the account that may undo back to it: no sign-up or email change may claim it.
     */
    public function isReserved(string $email, ?int $exceptUserId = null): bool;

    /**
     * Validation rule refusing a reserved address (see isReserved()).
     *
     * @return \Closure(string, mixed, \Closure): void
     */
    public function reservedEmailRule(?int $exceptUserId = null): \Closure;
}

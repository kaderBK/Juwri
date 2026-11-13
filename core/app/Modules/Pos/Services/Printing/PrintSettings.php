<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Services\Printing;

use App\Models\StoreOption;
use Illuminate\Support\Str;

/**
 * PARITY-RUN T1: per-store printing options that are not per-printer.
 *
 * The pairing token lives here rather than on a profile because it authenticates
 * the DESKTOP SHELL to this store, not one printer — the operator pastes it into
 * the bridge once and every bridge profile rides it (print-bridge.md §Auth).
 */
class PrintSettings
{
    public const TOKEN_OPTION = 'print_bridge_token';

    /** The store's bridge pairing token, or null when the owner never generated one. */
    public static function pairingToken(int $storeId): ?string
    {
        $token = (string) (store_options($storeId, self::TOKEN_OPTION) ?: '');

        return $token !== '' ? $token : null;
    }

    /**
     * Generate (or rotate) the pairing token. 40 chars, per the frozen contract.
     * Rotating immediately invalidates every already-paired desktop shell — that
     * is the point: it is the only revoke a merchant has.
     */
    public static function rotatePairingToken(int $storeId): string
    {
        $token = Str::random(40);
        StoreOption::updateStoreOption($storeId, self::TOKEN_OPTION, $token);

        return $token;
    }

    /** Token, generating one on first read so the settings page always has something to show. */
    public static function ensurePairingToken(int $storeId): string
    {
        return self::pairingToken($storeId) ?: self::rotatePairingToken($storeId);
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Services\Printing;

/**
 * PARITY-RUN T1-3: RawBT intent URLs.
 *
 * RawBT is the de-facto Android bridge to Bluetooth/USB ESC/POS printers (the
 * single most-requested item on the competitor board). It has no HTTP API: a
 * page hands it bytes by navigating to a `rawbt:` URL, which Android resolves
 * to the installed app. That makes it the only transport that needs no pairing,
 * no LAN and no desktop shell — and the only one that cannot report success.
 *
 * Two shapes, both understood by RawBT ≥ 1.5:
 *   rawbt:base64,<payload>                     — raw ESC/POS bytes
 *   intent:base64,<payload>#Intent;scheme=rawbt;package=ru.a402d.rawbtprinter;end;
 * The intent form survives Chrome's stricter custom-scheme handling, so it is
 * the default; `simple()` is kept for in-app WebViews that resolve the short one.
 */
class RawBtLink
{
    public const PACKAGE = 'ru.a402d.rawbtprinter';

    /** Chrome-safe Android intent URL carrying base64 ESC/POS bytes. */
    public static function build(string $rawBytes): string
    {
        return 'intent:base64,' . base64_encode($rawBytes)
            . '#Intent;scheme=rawbt;package=' . self::PACKAGE . ';end;';
    }

    /** Short scheme form — WebViews and older launchers. */
    public static function simple(string $rawBytes): string
    {
        return 'rawbt:base64,' . base64_encode($rawBytes);
    }

    /** Same as build(), for a payload that is already base64. */
    public static function fromBase64(string $base64): string
    {
        return 'intent:base64,' . $base64
            . '#Intent;scheme=rawbt;package=' . self::PACKAGE . ';end;';
    }

    /** Play-store listing, linked from the printing settings help block. */
    public static function installUrl(): string
    {
        return 'https://play.google.com/store/apps/details?id=' . self::PACKAGE;
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use Juwri\Modules\Pos\Services\Printing\RawBtLink;
use PHPUnit\Framework\TestCase;

/**
 * PARITY-RUN T1-3. RawBT is the only transport with no handshake and no error
 * channel — if the URL is wrong nothing happens and the operator has no clue
 * why, so the exact string is pinned here.
 */
class RawBtLinkTest extends TestCase
{
    public function test_intent_url_carries_base64_and_resolves_to_the_rawbt_package(): void
    {
        $url = RawBtLink::build("\x1B@hello");

        $this->assertStringStartsWith('intent:base64,', $url);
        $this->assertStringContainsString(base64_encode("\x1B@hello"), $url);
        $this->assertStringContainsString('scheme=rawbt', $url);
        $this->assertStringContainsString('package=ru.a402d.rawbtprinter', $url);
        $this->assertStringEndsWith(';end;', $url);
    }

    public function test_simple_scheme_form_for_webviews(): void
    {
        $this->assertSame('rawbt:base64,' . base64_encode('abc'), RawBtLink::simple('abc'));
    }

    /** The job queue stores payloads already base64'd — re-encoding would break it. */
    public function test_from_base64_does_not_double_encode(): void
    {
        $b64 = base64_encode("\x1B@ticket");

        $this->assertSame(RawBtLink::build("\x1B@ticket"), RawBtLink::fromBase64($b64));
    }

    public function test_install_url_points_at_the_play_listing(): void
    {
        $this->assertStringContainsString('play.google.com', RawBtLink::installUrl());
        $this->assertStringContainsString(RawBtLink::PACKAGE, RawBtLink::installUrl());
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use Juwri\Modules\Pos\Services\Printing\EscPosInspector;
use PHPUnit\Framework\TestCase;

/**
 * The inspector is only useful as a validator if it actually FAILS on a bad
 * stream — a decoder that finds nothing wrong in everything proves nothing. So
 * this feeds it malformed input on purpose.
 */
class EscPosInspectorTest extends TestCase
{
    private EscPosInspector $insp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->insp = new EscPosInspector();
    }

    public function test_a_clean_stream_has_no_findings_and_reconstructs_its_text(): void
    {
        $bytes = "\x1B@"            // ESC @  init
            . "\x1Ba\x01" . 'HELLO' . "\x0A"   // centred line
            . "\x1BE\x01" . 'BOLD' . "\x1BE\x00" . "\x0A"
            . "\x1DV0";             // full cut

        $r = $this->insp->inspect($bytes);

        $this->assertSame([], $r['findings']);
        $this->assertSame(['HELLO', 'BOLD'], $r['text']);
        $this->assertSame('ESC @', $r['trace'][0]['type']);
        $this->assertSame('cut', end($r['trace'])['name']);
    }

    public function test_it_flags_the_malformed_double_height_constant_this_run_removed(): void
    {
        // GS ! was declared as `self::GS . '!0x11'` — i.e. GS ! 0x30, then the
        // literal text "x11". A printer sets size from 0x30 and PRINTS "x11".
        $bytes = "\x1D!" . '0x11' . 'PRICE' . "\x0A";

        $r = $this->insp->inspect($bytes);

        // The size command decodes, but the literal "x11" leaks into the text —
        // which is exactly the visible-on-paper bug a content test cannot see.
        $this->assertStringContainsString('x11', implode('', $r['text']));
    }

    public function test_it_flags_an_unknown_control_byte(): void
    {
        $bytes = 'OK' . "\x0A" . "\x07" . 'AFTER' . "\x0A"; // 0x07 BEL — never emitted

        $r = $this->insp->inspect($bytes);

        $this->assertNotEmpty($r['findings']);
        $this->assertStringContainsString('0x07', $r['findings'][0]['message']);
    }

    public function test_it_flags_an_invalid_justification_parameter(): void
    {
        $bytes = "\x1Ba\x09" . 'X' . "\x0A"; // ESC a 9 — only 0/1/2 are valid

        $r = $this->insp->inspect($bytes);

        $this->assertNotEmpty($r['findings']);
        $this->assertStringContainsString('justification', $r['findings'][0]['message']);
    }

    public function test_it_flags_a_truncated_raster_image(): void
    {
        // GS v 0 declaring far more data than is present — a real printer waits
        // for bytes that never come and the ticket hangs.
        $bytes = "\x1Dv0\x00\x10\x00\x10\x00" . 'tinydata';

        $r = $this->insp->inspect($bytes);

        $this->assertNotEmpty($r['findings']);
        $this->assertStringContainsString('data bytes', $r['findings'][0]['message']);
    }

    public function test_it_walks_past_a_well_formed_raster_image(): void
    {
        // GS v 0, m=0, 1 byte wide, 2 rows → 2 data bytes, then a normal line.
        $bytes = "\x1Dv0\x00\x01\x00\x02\x00" . "\xFF\xFF" . 'AFTER' . "\x0A";

        $r = $this->insp->inspect($bytes);

        $this->assertSame([], $r['findings']);
        $this->assertContains('AFTER', $r['text']);
        $this->assertSame('raster image', $r['trace'][0]['name']);
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Services\Printing;


/**
 * PARITY-RUN follow-up #3 — decode an ESC/POS byte stream so a ticket can be
 * validated WITHOUT a printer.
 *
 * Physical-print validation is hardware-gated: no head, no paper. But most of
 * what goes wrong on paper is catchable in the byte stream first — a malformed
 * command byte makes a real printer garble everything after it, and a stray
 * literal (the `GS !0x11` landmine this run removed would have printed "0x11")
 * is invisible to a text assertion. This walks the stream the way a printer's
 * firmware does, names every command, flags anything that is not a command the
 * renderer is supposed to emit, and reconstructs the plain-text the paper shows.
 *
 * It is a VALIDATOR, not a full ESC/POS emulator: it recognises the command set
 * {@see EscPosRenderer} actually uses and treats an unknown control byte as a
 * finding rather than trying to interpret it. That is the point — a byte the
 * renderer never meant to emit is exactly what we want surfaced.
 */
class EscPosInspector
{
    private const ESC = 0x1B;
    private const GS  = 0x1D;
    private const LF  = 0x0A;

    /** @var list<array{offset:int, type:string, name:string, detail:string}> */
    private array $trace = [];

    /** @var list<string> lines of reconstructed printable text */
    private array $text = [];

    /** @var list<array{offset:int, message:string}> */
    private array $findings = [];

    private string $line = '';

    /**
     * @return array{trace:list<array>, text:list<string>, findings:list<array>, bytes:int}
     */
    public function inspect(string $bytes): array
    {
        $this->trace = $this->findings = $this->text = [];
        $this->line = '';

        $n = strlen($bytes);
        $i = 0;
        while ($i < $n) {
            $b = ord($bytes[$i]);

            if ($b === self::ESC) {
                $i = $this->esc($bytes, $i, $n);
            } elseif ($b === self::GS) {
                $i = $this->gs($bytes, $i, $n);
            } elseif ($b === self::LF) {
                $this->flush();
                $this->cmd($i, 'LF', 'line feed', '');
                $i++;
            } elseif ($b < 0x20 && $b !== 0x09) {
                // Any other C0 control byte is not something the renderer emits.
                $this->finding($i, sprintf('unexpected control byte 0x%02X', $b));
                $i++;
            } else {
                // Printable (incl. UTF-8 continuation / high bytes for Arabic).
                $this->line .= $bytes[$i];
                $i++;
            }
        }
        $this->flush();

        return [
            'trace' => $this->trace,
            'text' => $this->text,
            'findings' => $this->findings,
            'bytes' => $n,
        ];
    }

    private function esc(string $s, int $i, int $n): int
    {
        $c = $i + 1 < $n ? $s[$i + 1] : '';
        switch ($c) {
            case '@':
                $this->cmd($i, 'ESC @', 'initialise printer', '');

                return $i + 2;
            case 'a': // ESC a n — justification
                $nParam = $i + 2 < $n ? ord($s[$i + 2]) : -1;
                $map = [0 => 'left', 0x30 => 'left', 1 => 'center', 0x31 => 'center', 2 => 'right', 0x32 => 'right'];
                if (! isset($map[$nParam])) {
                    $this->finding($i + 2, sprintf('ESC a with invalid justification 0x%02X', $nParam));
                }
                $this->cmd($i, 'ESC a', 'align', $map[$nParam] ?? '?');

                return $i + 3;
            case 'E': // ESC E n — emphasis (bit 0)
                $nParam = $i + 2 < $n ? ord($s[$i + 2]) : -1;
                $this->cmd($i, 'ESC E', 'bold', ($nParam & 1) ? 'on' : 'off');

                return $i + 3;
            case 'p': // ESC p m t1 t2 — drawer pulse
                $this->cmd($i, 'ESC p', 'cash-drawer kick', '');

                return $i + 5;
            default:
                $this->finding($i, sprintf('unrecognised ESC command byte 0x%02X', $c === '' ? -1 : ord($c)));

                return $i + 2;
        }
    }

    private function gs(string $s, int $i, int $n): int
    {
        $c = $i + 1 < $n ? $s[$i + 1] : '';
        switch ($c) {
            case 'V': // GS V m — cut
                $m = $i + 2 < $n ? ord($s[$i + 2]) : -1;
                $full = in_array($m, [0, 0x30], true);
                $partial = in_array($m, [1, 0x31], true);
                if (! $full && ! $partial) {
                    $this->finding($i + 2, sprintf('GS V with invalid cut mode 0x%02X', $m));
                }
                $this->cmd($i, 'GS V', 'cut', $full ? 'full' : ($partial ? 'partial' : '?'));

                return $i + 3;
            case '!': // GS ! n — character size
                $nParam = $i + 2 < $n ? ord($s[$i + 2]) : -1;
                $w = ($nParam >> 4) & 0x0F;
                $h = $nParam & 0x0F;
                $this->cmd($i, 'GS !', 'char size', sprintf('w×%d h×%d', $w + 1, $h + 1));

                return $i + 3;
            case 'v': // GS v 0 — raster bit image
                return $this->rasterImage($s, $i, $n);
            default:
                $this->finding($i, sprintf('unrecognised GS command byte 0x%02X', $c === '' ? -1 : ord($c)));

                return $i + 2;
        }
    }

    /** GS v 0 m xL xH yL yH d… — the Arabic/logo raster path. */
    private function rasterImage(string $s, int $i, int $n): int
    {
        // GS 'v' '0' m xL xH yL yH  → 8 header bytes, then xL+xH*256 * (yL+yH*256) data bytes.
        if ($i + 7 >= $n) {
            $this->finding($i, 'truncated GS v 0 raster header');

            return $n;
        }
        $xL = ord($s[$i + 4]);
        $xH = ord($s[$i + 5]);
        $yL = ord($s[$i + 6]);
        $yH = ord($s[$i + 7]);
        $widthBytes = $xL + $xH * 256;
        $height = $yL + $yH * 256;
        $dataLen = $widthBytes * $height;

        $this->flush();
        $this->cmd($i, 'GS v 0', 'raster image', sprintf('%d×%d px', $widthBytes * 8, $height));

        $end = $i + 8 + $dataLen;
        if ($end > $n) {
            $this->finding($i, sprintf('GS v 0 declares %d data bytes but only %d remain', $dataLen, $n - $i - 8));

            return $n;
        }

        return $end;
    }

    private function cmd(int $offset, string $type, string $name, string $detail): void
    {
        $this->trace[] = compact('offset', 'type', 'name', 'detail');
    }

    private function finding(int $offset, string $message): void
    {
        $this->findings[] = compact('offset', 'message');
    }

    private function flush(): void
    {
        if ($this->line !== '') {
            $this->text[] = rtrim($this->line, "\r");
            $this->line = '';
        }
    }
}

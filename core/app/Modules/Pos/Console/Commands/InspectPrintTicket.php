<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Console\Commands;

use App\Models\Order;
use Juwri\Modules\Pos\Services\Printing\EscPosInspector;
use Juwri\Modules\Pos\Services\Printing\EscPosRenderer;
use Illuminate\Console\Command;

/**
 * PARITY-RUN follow-up #3 — validate a printed ticket without a printer, and
 * hand the operator the exact bytes to send to a real one.
 *
 * Physical print validation needs paper the CI/dev box does not have. This
 * closes the gap on both sides:
 *
 *   1. It renders a REAL order through {@see EscPosRenderer} at a chosen width,
 *      decodes the ESC/POS with {@see EscPosInspector}, and prints the command
 *      trace + the plain-text the paper would show + any structural finding. A
 *      malformed command or a stray literal is caught here, before a printer
 *      garbles the rest of the ticket.
 *   2. With --raw it writes the raw bytes to a file, so the operator can
 *      `nc <printer-ip> 9100 < ticket.bin` (or `lp -o raw`) and compare the paper
 *      to the text this printed. That is the one step only hardware can do.
 *
 * Read-only: it renders and decodes, and touches no order.
 *
 * Usage:
 *   php artisan print:inspect 99485
 *   php artisan print:inspect 99485 --kind=kot --width=57
 *   php artisan print:inspect 99485 --raw=/tmp/ticket.bin
 */
class InspectPrintTicket extends Command
{
    protected $signature = 'print:inspect
        {order            : Order id to render}
        {--kind=receipt   : receipt | kot}
        {--width=80       : Paper width in mm (80 or 57)}
        {--rtl            : Force the Arabic raster/RTL lane}
        {--raw=           : Also write the raw ESC/POS bytes to this path}';

    protected $description = 'READ-ONLY: render an order to ESC/POS and validate the byte stream without a printer.';

    public function handle(EscPosInspector $inspector): int
    {
        $order = Order::with(['items'])->find($this->argument('order'));
        if (! $order) {
            $this->error('Order '.$this->argument('order').' not found.');

            return self::FAILURE;
        }

        $mm = (int) $this->option('width');
        if (! in_array($mm, [80, 57], true)) {
            $this->error('--width must be 80 or 57.');

            return self::INVALID;
        }

        $renderer = EscPosRenderer::forWidthMm($mm);
        if ($this->option('rtl')) {
            $renderer->rtl(true);
        }

        $kind = (string) $this->option('kind');
        $bytes = match ($kind) {
            'receipt' => $renderer->renderReceipt($order),
            'kot' => $renderer->renderKot($order),
            default => $this->reject("--kind must be receipt or kot, got '{$kind}'"),
        };
        if ($bytes === null) {
            return self::INVALID;
        }

        $result = $inspector->inspect($bytes);

        $this->line('');
        $this->info(sprintf('Order #%d · %s · %dmm · %d bytes', $order->id, $kind, $mm, $result['bytes']));
        $this->line('');

        $this->comment('── command trace ──');
        foreach ($result['trace'] as $t) {
            $this->line(sprintf('  %5d  %-10s %-16s %s', $t['offset'], $t['type'], $t['name'], $t['detail']));
        }

        $this->line('');
        $this->comment('── paper preview ──');
        $rule = str_repeat('─', $mm === 80 ? 48 : 32);
        $this->line('  ┌'.$rule.'┐');
        foreach ($result['text'] as $row) {
            // The text lane is already column-fitted by the renderer; show it verbatim.
            $this->line('  │'.mb_str_pad($row, $mm === 80 ? 48 : 32).'│');
        }
        $this->line('  └'.$rule.'┘');

        $this->line('');
        if ($result['findings']) {
            $this->error('  '.count($result['findings']).' structural finding(s) — a real printer may garble this ticket:');
            foreach ($result['findings'] as $f) {
                $this->line(sprintf('    <fg=red>offset %d: %s</>', $f['offset'], $f['message']));
            }
        } else {
            $this->info('  No structural findings — the byte stream is well-formed ESC/POS.');
        }

        if ($path = $this->option('raw')) {
            file_put_contents($path, $bytes);
            $this->line('');
            $this->info('  raw bytes → '.$path);
            $this->comment('  send to a networked printer:  nc <printer-ip> 9100 < '.$path);
            $this->comment('  or a CUPS raw queue:          lp -o raw -d <queue> '.$path);
        }
        $this->line('');

        return $result['findings'] ? self::FAILURE : self::SUCCESS;
    }

    private function reject(string $msg): ?string
    {
        $this->error($msg);

        return null;
    }
}

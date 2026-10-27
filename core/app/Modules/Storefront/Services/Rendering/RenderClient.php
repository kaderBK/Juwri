<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Rendering;

use Throwable;

/**
 * P1-2: the single seam every untrusted (theme) render passes through.
 *
 * $render is a closure that produces the rendered string — in Phase 2 it wraps
 * the sandboxed Liquid engine. The closure runs under a RenderBudget; on any
 * breach or error a RenderFailedException is thrown and the caller applies a
 * safe fallback (never blank). Isolation strategy is pluggable behind this
 * interface (inprocess | subprocess | jailed) so call sites never change.
 *
 * See rev/platform-v2/decisions/render-isolation.md. On shared/cPanel hosting
 * (no namespaces, proc_open often disabled) the effective strategy is
 * inprocess and safety rests on the language layer (Liquid is data-only +
 * ResourceLimits).
 */
class RenderClient
{
    /** Effective strategy, degrading gracefully when the host can't support the configured one. */
    public function strategy(): string
    {
        $configured = (string) config('platform.render_strategy', 'inprocess');

        if (in_array($configured, ['subprocess', 'jailed'], true) && ! self::procOpenAvailable()) {
            // Shared hosting: no subprocess possible — fall back to the language boundary.
            return 'inprocess';
        }

        return $configured;
    }

    public static function procOpenAvailable(): bool
    {
        if (! function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('proc_open', $disabled, true);
    }

    /**
     * Render untrusted output under a budget.
     *
     * @throws RenderFailedException on breach or error — caller falls back.
     */
    public function render(callable $render, ?RenderBudget $budget = null): string
    {
        $budget ??= RenderBudget::fromConfig();

        // Only the in-process path is enforced here; subprocess/jailed plug in
        // behind this method for VPS/prod without touching callers.
        return $this->renderInProcess($render, $budget);
    }

    protected function renderInProcess(callable $render, RenderBudget $budget): string
    {
        $alarm = $this->armWallClock($budget->maxWallSeconds);

        try {
            $out = (string) $render();
        } catch (RenderFailedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RenderFailedException('render failed: '.$e->getMessage(), 0, $e);
        } finally {
            $this->disarmWallClock($alarm);
        }

        $len = strlen($out);
        if ($len > $budget->maxOutputBytes) {
            throw new RenderFailedException("render output {$len}B exceeded budget {$budget->maxOutputBytes}B");
        }

        return $out;
    }

    /** Best-effort wall-clock guard via pcntl (unavailable/disabled on some hosts). */
    private function armWallClock(int $seconds): bool
    {
        if ($seconds < 1 || ! function_exists('pcntl_async_signals') || ! function_exists('pcntl_alarm')) {
            return false;
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () {
            throw new RenderFailedException('render exceeded wall-clock budget');
        });
        pcntl_alarm($seconds);

        return true;
    }

    private function disarmWallClock(bool $armed): void
    {
        if ($armed && function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }
    }
}

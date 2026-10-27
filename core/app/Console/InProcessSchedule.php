<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Console;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Support\RequestEpoch;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

/**
 * P6-134: a Schedule whose `command()` events run inside the `schedule:run` process.
 *
 * Laravel starts every scheduled artisan command as a child process (Symfony Process, proc_open). On shared hosting
 * `proc_open` is usually in `disable_functions`, and then `schedule:run` dies on the first command event with "The Process
 * class relies on proc_open": no queue drain, no backup, no daily job ever runs. The cron line looks fine and the heartbeat
 * (a closure) even keeps ticking. With this class the same commands run through `Artisan::call()` in the one cron process.
 *
 * The cost is that nothing runs in parallel and a child cannot be killed on its own: events still run one after another (as
 * they do without `runInBackground`), and a failing command reports through the usual `onFailure()` hooks.
 *
 * Used by {@see Kernel::resolveConsoleSchedule()} only when `proc_open` is not available; otherwise the stock Schedule runs.
 */
class InProcessSchedule extends Schedule
{
    /** RequestEpoch of the web-cron run that already logged its skip warning (one warning per run, also under Octane). */
    private static ?int $warnedSkip = null;

    /**
     * @param  SymfonyCommand|string  $command
     * @param  array<int|string, mixed>  $parameters
     * @return CallbackEvent
     */
    public function command($command, array $parameters = [])
    {
        if ($command instanceof SymfonyCommand) {
            $command = (string) $command->getName();
        } elseif (class_exists($command)) {
            $command = (string) Container::getInstance()->make($command)->getName();
        }

        $line = (string) $command;
        if ($parameters !== []) {
            $line .= ' '.$this->compileParameters($parameters);
        }

        $drain = str_contains($line, '--stop-when-empty');

        // withoutOverlapping() on a callback needs a name: the command line is the natural one.
        return $this->call(function () use ($line, $drain) {
            $app = Container::getInstance();
            $run = $line;

            if ($this->webContext()) {
                // Web cron (/cronjob) on a host without proc_open. Module commands are registered for the CLI only, and the
                // request has a time limit (cron.max_seconds): skip what is not registered and keep drains inside the limit.
                if (! array_key_exists((string) strtok($line, ' '), Artisan::all())) {
                    if (self::$warnedSkip !== RequestEpoch::id()) {
                        self::$warnedSkip = RequestEpoch::id(); // once per request: a web run skips dozens of module commands
                        Log::warning('schedule.skipped_in_web_cron', ['first' => (string) strtok($line, ' '), 'why' => 'module commands are registered for the CLI cron only; use the CLI cron for them']);
                    }

                    return null;
                }
                $budget = max(10, (int) config('cron.max_seconds', 50) - 10);
                $run = (string) preg_replace_callback('/--max-time=(\d+)/', static fn (array $m) => '--max-time='.min((int) $m[1], $budget), $line);
            }

            if ($drain) {
                // Tell queue_worker_stamp_heartbeat() this is a fallback drain, not a dedicated daemon (argv cannot say so here).
                $app->instance('queue.fallback_drain', true);
            }

            try {
                return Artisan::call($run) === 0 ? null : false; // false marks the event as failed (exit code 1, onFailure hooks)
            } finally {
                if ($drain) {
                    $app->forgetInstance('queue.fallback_drain');
                }
            }
        })->name($line);
    }

    /** Is this schedule:run a web request (the /cronjob trigger) rather than the CLI cron? */
    protected function webContext(): bool
    {
        return ! app()->runningInConsole();
    }
}

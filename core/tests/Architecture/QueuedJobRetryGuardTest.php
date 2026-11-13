<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Architecture;

use Illuminate\Contracts\Queue\ShouldQueue;
use Juwri\Core\Providers\QueueRoutingServiceProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\Support\EditionFolders;
use Tests\TestCase;

/**
 * P6-64: every queued class (jobs, queued mails / notifications / listeners) declares explicit $tries and
 * $timeout, and the timeout stays below the database/redis connection's retry_after — otherwise a job still
 * running is handed to a second worker and runs twice. The async connections dispatch after commit.
 */
class QueuedJobRetryGuardTest extends TestCase
{
    public function test_async_connections_dispatch_after_commit(): void
    {
        foreach (['database', 'redis'] as $conn) {
            $this->assertTrue((bool) config("queue.connections.$conn.after_commit"), "$conn queue must set after_commit");
        }
    }

    public function test_every_queued_class_declares_tries_and_timeout_below_retry_after(): void
    {
        // P6-64b: a job routed to the `heavy` queue is drained through the `heavy` connection (long retry_after);
        // everything else through the default database/redis connection (short retry_after).
        $routes = QueueRoutingServiceProvider::routes();
        $short = min((int) config('queue.connections.database.retry_after'), (int) config('queue.connections.redis.retry_after'));
        $long = (int) config('queue.connections.heavy.retry_after');
        $queued = $this->queuedClasses();
        $this->assertGreaterThan(40, count($queued), 'queued class discovery found too few classes');

        $bad = [];
        foreach ($queued as $class) {
            $r = new ReflectionClass($class);
            $defaults = $r->getDefaultProperties();
            // Declared on the class or an app parent (e.g. SettingsTemplateMail) — never a framework default.
            $own = fn (string $p): bool => $r->hasProperty($p)
                && ! str_starts_with($r->getProperty($p)->getDeclaringClass()->getName(), 'Illuminate\\');
            $tries = $own('tries') ? ($defaults['tries'] ?? null) : null;
            $timeout = $own('timeout') ? ($defaults['timeout'] ?? null) : null;
            if (! is_int($tries) || $tries < 1) {
                $bad[] = "$class: missing \$tries";
            }
            if (! is_int($timeout) || $timeout < 1) {
                $bad[] = "$class: missing \$timeout";
            } elseif ($timeout >= ($retryAfter = (($routes[$class] ?? null) === QueueRoutingServiceProvider::HEAVY ? $long : $short))) {
                $bad[] = "$class: \$timeout $timeout >= retry_after $retryAfter";
            }
        }
        $this->assertSame([], $bad, "queued classes without safe retry settings:\n".implode("\n", $bad));
    }

    public function test_short_and_long_retry_after_are_split(): void
    {
        foreach (['database', 'redis'] as $conn) {
            $this->assertLessThanOrEqual(150, (int) config("queue.connections.$conn.retry_after"), "$conn retry_after must stay short (order/webhook redelivery)");
        }
        $this->assertGreaterThan(1800, (int) config('queue.connections.heavy.retry_after'));
        $this->assertTrue((bool) config('queue.connections.heavy.after_commit'));
        $this->assertSame('heavy', config('queue.connections.heavy.queue'));
    }

    /** Workers: the default daemon must not drain `heavy` (short retry_after), and a heavy worker uses the heavy connection. */
    public function test_workers_drain_heavy_only_through_the_heavy_connection(): void
    {
        $entry = (string) file_get_contents(base_path('../docker/entrypoint.sh'));
        $kernel = (string) file_get_contents(app_path('Console/Kernel.php'));
        $sup = (string) file_get_contents(base_path('../deploy/supervisor/juwri-worker.conf'));
        foreach (['entrypoint' => $entry, 'kernel' => $kernel, 'supervisor' => $sup] as $name => $src) {
            preg_match_all('/queue:work([^\'"`\n]*)/', $src, $m);
            foreach ($m[1] as $args) {
                if (str_contains($args, 'heavy')) {
                    $this->assertMatchesRegularExpression('/^\s+heavy\s+--queue=heavy\b/', $args, "$name: heavy drained off the heavy connection: $args");
                }
            }
        }
        $this->assertStringContainsString('queue-heavy', (string) file_get_contents(base_path('../docker/compose.prod.yml')));
    }

    /** @return list<class-string> */
    private function queuedClasses(): array
    {
        $out = [];
        foreach ([app_path(), ...EditionFolders::dirs()] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
                if (! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                $src = (string) file_get_contents($file->getPathname());
                if (! preg_match('/^namespace\s+([^;]+);/m', $src, $ns)
                    || ! preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $src, $cl)) {
                    continue;
                }
                $class = $ns[1].'\\'.$cl[1];
                if (! class_exists($class)) {
                    continue;
                }
                $r = new ReflectionClass($class);
                if (! $r->isAbstract() && $r->implementsInterface(ShouldQueue::class)) {
                    $out[] = $class;
                }
            }
        }
        sort($out);

        return array_values(array_unique($out));
    }
}

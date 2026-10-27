<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Architecture;

use Illuminate\Contracts\Queue\ShouldQueue;
use Juwri\Core\Services\Tenancy\TenantJobs;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * P1-04 slice 7 (J25-6, J29-4): every queued class (jobs, queued mails / notifications / listeners) is classified
 * in TenantJobs as PLATFORM (always runs unscoped) or TENANT (restores the dispatcher's tenant). A new queued
 * class that is in neither list fails here — decide which one it is.
 */
class TenantJobsTest extends TestCase
{
    public function test_every_queued_class_is_classified_exactly_once(): void
    {
        $queued = $this->queuedClasses();
        $this->assertGreaterThan(40, count($queued), 'queued class discovery found too few classes');

        $platform = TenantJobs::platform();
        $tenant = TenantJobs::tenant();
        $this->assertSame([], array_values(array_intersect($platform, $tenant)), 'listed as both PLATFORM and TENANT');

        $unclassified = array_values(array_diff($queued, $platform, $tenant));
        $this->assertSame([], $unclassified, "queued classes missing from TenantJobs::PLATFORM / TENANT:\n".implode("\n", $unclassified));

        $stale = array_values(array_diff(array_merge($platform, $tenant), $queued));
        $this->assertSame([], $stale, "TenantJobs lists classes that are not (or no longer) queued:\n".implode("\n", $stale));
    }

    /** @return list<class-string> */
    private function queuedClasses(): array
    {
        $out = [];
        // P1-13 13c: an installed edition package's modules classify their own queued classes too.
        $dirs = [app_path(), ...\Tests\Support\EditionFolders::dirs()];
        $files = [];
        foreach ($dirs as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $f) {
                $files[] = $f;
            }
        }
        foreach ($files as $file) {
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
        sort($out);

        return $out;
    }
}

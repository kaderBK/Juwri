<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * P6-72: most models use `$guarded = []`, so a model write fed the raw request
 * (`$request->all()` / `->except(...)` / `->store()` / `->input()` with no key),
 * directly or through a variable assigned from it, lets a form post set any
 * column (store_id, user_id, role, plan_id ...). Writers must pass validated or
 * explicitly listed keys. Fails with file:line.
 */
class NoRequestMassAssignTest extends TestCase
{
    private const WRITE = '(?:->|::)(?:create|forceCreate|update|fill|forceFill|updateOrCreate|firstOrCreate|firstOrNew)\(\s*(?:\[[^\]]*\]\s*,\s*)?';

    private const RAW = '\$request->(?:all\(|except\(|post\(\s*\)|input\(\s*\))';

    /** @return list<int> 1-based line numbers of raw-request model writes in $src */
    public static function scan(string $src): array
    {
        $hits = [];
        if (preg_match_all('/'.self::WRITE.'(?:array_merge\(\s*)?'.self::RAW.'/', $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [, $off]) {
                $hits[] = substr_count($src, "\n", 0, $off) + 1;
            }
        }
        if (preg_match_all('/(\$\w+)\s*=\s*'.self::RAW.'/', $src, $vars)) {
            foreach (array_unique($vars[1]) as $var) {
                $re = '/'.self::WRITE.'(?:array_merge\(\s*)?'.preg_quote($var, '/').'\s*[,)]/';
                if (preg_match_all($re, $src, $w, PREG_OFFSET_CAPTURE)) {
                    foreach ($w[0] as [, $off]) {
                        $hits[] = substr_count($src, "\n", 0, $off) + 1;
                    }
                }
            }
        }
        sort($hits);

        return array_values(array_unique($hits));
    }

    public function test_scanner_goes_red_on_seeded_violations(): void
    {
        $bad = <<<'PHP'
        <?php
        Order::create($request->all());
        $store->update($request->except(['_token']));
        $m->fill($request->input());
        Foo::updateOrCreate(['id' => 1], $request->post());
        $data = $request->all();
        $user->forceFill($data)->save();
        PHP;
        $this->assertSame([2, 3, 4, 5, 7], self::scan($bad));

        $good = <<<'PHP'
        <?php
        Order::create($request->validated());
        $store->update($request->only(['title']));
        $m->fill(['name' => $request->input('name')]);
        $payload = $request->except(['_token']);
        Log::info($payload);
        PHP;
        $this->assertSame([], self::scan($good));
    }

    public function test_no_model_write_takes_the_raw_request(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        foreach (['app', 'ee', 'Modules'] as $dir) {
            if (! is_dir($root.'/'.$dir)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir));
            foreach ($it as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/tests/')) {
                    continue;
                }
                foreach (self::scan((string) file_get_contents($file->getPathname())) as $line) {
                    $offenders[] = substr($file->getPathname(), strlen($root) + 1).':'.$line;
                }
            }
        }
        $this->assertSame([], $offenders, 'Model writes must not take the raw request: '.implode(', ', $offenders));
    }
}

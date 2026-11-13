<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/**
 * P6-34: JSON contract snapshots. A response body is reduced to its SHAPE (keys + value types, a list by its first
 * element) and compared with a committed fixture under tests/Fixtures/<dir>/<name>.json. Values never matter, so the
 * snapshot is stable across seeds; adding, removing, renaming or retyping any field fails the test.
 *
 * Updating a contract on purpose (never silent):
 *     UPDATE_CONTRACT_SNAPSHOTS=1 php artisan test --filter=OwnerApp
 * rewrites the fixtures and still FAILS every test that rewrote one (assertPostConditions), so a run with the
 * flag can never go green by accident. Run again without the flag, then commit the fixture diff with the code change.
 * A missing fixture fails the same way: there is no auto-create without the flag.
 */
trait ContractSnapshots
{
    /** @var list<string> snapshots rewritten by this test under UPDATE_CONTRACT_SNAPSHOTS=1 */
    private array $contractSnapshotsWritten = [];

    /** A test that rewrote a snapshot never passes: the rewrite must be reviewed and the suite re-run without the flag. */
    protected function assertPostConditions(): void
    {
        parent::assertPostConditions();
        if ($this->contractSnapshotsWritten !== []) {
            $this->fail('Contract snapshots written: '.implode(', ', $this->contractSnapshotsWritten).'. Re-run without UPDATE_CONTRACT_SNAPSHOTS and commit the fixtures.');
        }
    }

    /** @return array<string, mixed>|string */
    protected function contractShape(mixed $value): array|string
    {
        if (is_array($value)) {
            if ($value === []) {
                return 'empty';
            }
            if (array_is_list($value)) {
                return ['list_of' => $this->contractShape($value[0])];
            }
            $out = [];
            foreach ($value as $k => $v) {
                $out[(string) $k] = $this->contractShape($v);
            }
            ksort($out);

            return $out;
        }

        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            default => 'string',
        };
    }

    /**
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $res
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function assertContract(TestResponse $res, string $dir, string $name, int $status): TestResponse
    {
        $res->assertStatus($status);
        $actual = ['status' => $status, 'body' => $this->contractShape($res->json() ?? [])];
        $path = base_path('tests/Fixtures/'.$dir.'/'.$name.'.json');
        $encoded = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        if (getenv('UPDATE_CONTRACT_SNAPSHOTS') === '1') {
            @mkdir(dirname($path), 0775, true);
            $changed = ! is_file($path) || file_get_contents($path) !== $encoded;
            file_put_contents($path, $encoded);
            if ($changed) {
                $this->contractSnapshotsWritten[] = $dir.'/'.$name;
            }

            return $res;
        }

        $this->assertFileExists($path, "Missing contract snapshot {$dir}/{$name}; create it deliberately with UPDATE_CONTRACT_SNAPSHOTS=1.");
        $this->assertSame(
            json_decode((string) file_get_contents($path), true),
            json_decode($encoded, true),
            "Response shape of {$dir}/{$name} changed. If intended, regenerate with UPDATE_CONTRACT_SNAPSHOTS=1 and commit the fixture."
        );

        return $res;
    }
}

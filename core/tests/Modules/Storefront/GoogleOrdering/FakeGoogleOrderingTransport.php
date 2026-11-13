<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\GoogleOrdering;

use Juwri\Modules\Storefront\Services\GoogleOrdering\Sftp\GoogleOrderingSftpTransport;

/** Test double: records every upload; failures can be scripted (connect, or the first N puts of a file). */
final class FakeGoogleOrderingTransport implements GoogleOrderingSftpTransport
{
    /** @var list<string> */
    public array $uploaded = [];

    public int $connects = 0;

    public int $puts = 0;

    public bool $closed = false;

    /** @param array<string,int> $failPuts remote basename => number of attempts that fail before one succeeds (use PHP_INT_MAX for always) */
    public function __construct(public int $failConnects = 0, public array $failPuts = []) {}

    public function connect(array $cfg): void
    {
        $this->connects++;
        if ($this->failConnects > 0) {
            $this->failConnects--;
            throw new \RuntimeException('connection refused');
        }
    }

    public function put(string $remotePath, string $localFile): void
    {
        $this->puts++;
        $name = basename($remotePath);
        if (($this->failPuts[$name] ?? 0) > 0) {
            $this->failPuts[$name]--;
            throw new \RuntimeException("put {$name} failed");
        }
        $this->uploaded[] = $name;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

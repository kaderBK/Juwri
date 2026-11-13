<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Services\Rwg\Sftp;

/** The Actions Center SFTP drop, behind a seam so tests use a fake and a dark build never opens a socket. */
interface RwgSftpTransport
{
    /**
     * @param  array{host:string,port:int,user:string,key:string,path:string}  $cfg
     *
     * @throws \RuntimeException when the connection or the login fails
     */
    public function connect(array $cfg): void;

    /** @throws \RuntimeException when the upload fails */
    public function put(string $remotePath, string $localFile): void;

    public function close(): void;
}

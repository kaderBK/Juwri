<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Session;

use Illuminate\Session\SessionManager;

/** P6-136: builds MergingStore / MergingEncryptedStore for every session driver. */
class MergingSessionManager extends SessionManager
{
    protected function buildSession($handler)
    {
        return $this->config->get('session.encrypt')
            ? $this->buildEncryptedSession($handler)
            : new MergingStore(
                $this->config->get('session.cookie'),
                $handler,
                null,
                $this->config->get('session.serialization', 'php')
            );
    }

    protected function buildEncryptedSession($handler)
    {
        return new MergingEncryptedStore(
            $this->config->get('session.cookie'),
            $handler,
            $this->container['encrypter'],
            null,
            $this->config->get('session.serialization', 'php'),
        );
    }
}

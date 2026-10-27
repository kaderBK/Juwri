<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Security;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * A just-created secret (API token, storefront token, driver token) shown to its owner ONCE.
 *
 * Before, the plain token rode in a session flash: a dashboard poll in flight when the form posted could save its
 * older session after the POST and drop the flash, so the owner never saw the token (file sessions, several
 * workers). Now the secret waits server-side, encrypted, under a key of its kind, the owner's id and the new row's
 * id; the redirect carries only that row id (never the secret, so it is never in a URL, a log or a session). The
 * page that shows it takes it out in one atomic read: a second read, another user, or a read after
 * {@see TTL_SECONDS} gets nothing.
 */
final class OneTimeSecrets
{
    public const TTL_SECONDS = 600;

    public function put(string $kind, int $userId, int $id, string $secret): void
    {
        Cache::put($this->key($kind, $userId, $id), Crypt::encryptString($secret), self::TTL_SECONDS);
    }

    /** The secret, once; null when it was read already, expired, or belongs to someone else. */
    public function pull(string $kind, int $userId, mixed $id): ?string
    {
        if (! is_numeric($id) || (int) $id <= 0 || $userId <= 0) {
            return null;
        }
        $key = $this->key($kind, $userId, (int) $id);

        // Atomic single read: two simultaneous reads cannot both get it.
        $value = Cache::lock($key.':lock', 10)->get(fn () => Cache::pull($key));
        if (! is_string($value)) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }

    private function key(string $kind, int $userId, int $id): string
    {
        return 'once-secret:'.$kind.':'.$userId.':'.$id;
    }
}

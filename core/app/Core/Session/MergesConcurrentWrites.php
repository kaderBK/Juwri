<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Session;

use Illuminate\Session\CookieSessionHandler;
use Illuminate\Session\DatabaseSessionHandler;

/**
 * P6-136: stop a slow request's session save from erasing what a faster
 * request wrote meanwhile (lost locale switch, lost flash, lost cart line).
 *
 * Laravel loads the whole session at the start of a request and writes the
 * whole session back at the end. Two requests that share a cookie therefore
 * race: the one that finishes LAST wins, and everything the other one wrote is
 * gone. session.block only serialises POST/PUT/PATCH/DELETE here (GETs and
 * pollers skip the lock — PreventSessionBlockingOnPolls), and the lock never
 * covers an unblocked request anyway, so a dashboard poll or a slow page GET
 * still overwrote a concurrent locale switch / flash on every driver.
 *
 * The fix: remember what was loaded, and at save time re-read the stored
 * session and write back only the keys THIS request changed or removed, on top
 * of the fresh copy. Keys the request never touched keep whatever another
 * request stored. Flash lists are merged so another request's new flash still
 * ages normally. Rules that keep the old full write:
 *  - the id changed (regenerate/migrate on login, 2FA, logout) or the session
 *    was flushed/invalidated: the request owns the whole session;
 *  - the stored copy vanished while we ran (another request destroyed it, e.g.
 *    logout): we do NOT write, so a slow request cannot resurrect a session
 *    that was signed out.
 * The window left is the microseconds between the re-read and the write, not
 * the whole request. Works the same on every handler (file, database, redis,
 * cookie-less array) because it only uses read()/write().
 *
 * P6-146 (stale session cookie after login): when this request's id is
 * replaced (regenerate/migrate/invalidate — login, 2FA, logout), the OLD id's
 * stored copy is overwritten with a tombstone that holds only a timestamp. It
 * never names the new id, so knowing an old id gives nothing (fixation
 * protection is unchanged: the old id can never reach the new session). A
 * request still running on the old id sees the tombstone (or the vanished
 * copy) at save time, skips the write and reports wasSuperseded(); the
 * StartSession subclass then sends NO session cookie, so the browser keeps the
 * new cookie the login response set. A request that STARTS on a fresh
 * tombstone (sent just before the login response arrived) is served as an
 * empty guest session and also sends no cookie; an old tombstone (after
 * TOMBSTONE_GRACE seconds) gets a brand-new id like any unknown session.
 * The cookie driver keeps the stock behaviour (see SESSION-RACE.md).
 */
trait MergesConcurrentWrites
{
    /** @var array<string, string>|null serialized value per top-level key as loaded; null = not loaded */
    protected ?array $loadedSnapshot = null;

    /** @var array<mixed> the `_flash` value as loaded (kept as a value: no unserialize of the fingerprint) */
    protected array $loadedFlash = [];

    protected ?string $loadedId = null;

    protected bool $ownsWholeSession = false;

    /** P6-146: this request's id was replaced by another request (login/logout) — no write, no cookie. */
    protected bool $superseded = false;

    /** Stored under a replaced id instead of its data: only the time it was replaced. */
    public const TOMBSTONE_KEY = '_juwri_superseded_at';

    /** Seconds a tombstone still means "a login/logout response is on its way to this browser". */
    public const TOMBSTONE_GRACE = 60;

    protected function loadSession()
    {
        $data = $this->readFromHandler();

        $this->superseded = false;
        if (isset($data[self::TOMBSTONE_KEY])) {
            if (now()->getTimestamp() - (int) $data[self::TOMBSTONE_KEY] <= self::TOMBSTONE_GRACE) {
                $this->superseded = true; // raced the login/logout response: serve empty, send no cookie
            } else {
                $this->setId(null); // long dead: start over on a fresh id, never reuse the old one
            }
            $data = [];
        }

        $this->loadedId = $this->getId();
        $this->ownsWholeSession = false;
        $this->loadedSnapshot = [];
        $this->loadedFlash = is_array($data['_flash'] ?? null) ? $data['_flash'] : [];
        foreach ($data as $key => $value) {
            $this->loadedSnapshot[(string) $key] = $this->fingerprint($value);
        }

        $this->attributes = array_replace($this->attributes, $data);

        $this->marshalErrorBag();
    }

    /** P6-146: true when the session id this request started on was replaced by another request meanwhile. */
    public function wasSuperseded(): bool
    {
        return $this->superseded;
    }

    public function migrate($destroy = false)
    {
        if ($this->superseded) {
            // Review P6-146: a request on a fresh tombstone (e.g. remember-me re-login, a CSRF-exempt logout) must not
            // destroy the tombstone or mint a cookie that would replace the one the login/logout response set. Nothing
            // of this request is saved anyway.
            return true;
        }

        $old = $this->getId();

        if ($this->loadedId !== null && $old === $this->loadedId && $this->loadedSnapshot !== null && $this->loadedSnapshot !== []
            && ! $this->handler instanceof CookieSessionHandler) {
            // Overwrite (rather than delete) the old copy: its data is gone either way, but a request still
            // running on the old id can now tell "replaced" from "never existed". Holds no pointer to the new id.
            $handler = $this->handler;
            if ($handler instanceof DatabaseSessionHandler) {
                // Review P6-146: no user_id / ip / agent on the tombstone row (it is not a device: "your sessions"
                // lists and logout-other-devices key on user_id).
                $handler = clone $handler;
                (fn () => $this->container = null)->call($handler); // no container: getDefaultPayload adds no user/request info
            }
            $handler->write($old, $this->prepareForStorage($this->encodeAttributes([self::TOMBSTONE_KEY => now()->getTimestamp()])));
            $destroy = false;
        }

        $result = parent::migrate($destroy);
        $this->superseded = false; // the new id is this request's own: its cookie must go out

        return $result;
    }

    public function flush()
    {
        $this->ownsWholeSession = true;

        parent::flush();
    }

    public function save()
    {
        if ($this->superseded) {
            // Started on a fresh tombstone and never got its own id: nothing to keep, nothing to send.
            $this->resetMergeState();
            $this->started = false;

            return;
        }

        // The cookie driver re-reads the REQUEST cookie, not server state: there is nothing to merge with, and an
        // expiry passing mid-request would read as "destroyed" and drop the save. Keep the stock full write.
        if ($this->ownsWholeSession || $this->loadedSnapshot === null || $this->loadedId !== $this->getId()
            || $this->handler instanceof CookieSessionHandler) {
            $this->resetMergeState();
            parent::save();

            return;
        }

        $this->ageFlashData();
        $this->prepareErrorBagForSerialization();

        $stored = $this->readFromHandler();

        if ($this->superseded || isset($stored[self::TOMBSTONE_KEY]) || ($stored === [] && $this->loadedSnapshot !== [])) {
            // Replaced or destroyed under us (login regenerate / logout in another request): never resurrect it,
            // and tell StartSession not to send the stale id back to the browser (P6-146).
            $this->superseded = true;
            $this->resetMergeState();
            $this->started = false;

            return;
        }

        $merged = $stored;
        foreach ($this->attributes as $key => $value) {
            $key = (string) $key;
            if (! array_key_exists($key, $this->loadedSnapshot) || $this->loadedSnapshot[$key] !== $this->fingerprint($value)) {
                $merged[$key] = $value;
            }
        }
        foreach (array_keys($this->loadedSnapshot) as $key) {
            if (! array_key_exists($key, $this->attributes)) {
                unset($merged[$key]);
            }
        }

        $othersFlashed = [];
        $merged['_flash'] = $this->mergeFlash($stored['_flash'] ?? null, $stored, $othersFlashed);
        foreach ($othersFlashed as $key) {
            if (array_key_exists($key, $stored)) {
                $merged[$key] = $stored[$key]; // their fresh flash value wins over our aged-out copy
            }
        }

        $this->attributes = $merged;
        $this->resetMergeState();

        $this->handler->write($this->getId(), $this->prepareForStorage($this->encodeAttributes($this->attributes)));

        $this->started = false;
    }

    /**
     * Our aged flash lists, plus every key another request flashed meanwhile
     * (in the stored old/new list but not in the one we loaded, or flashed again
     * under the same key with a new value), so that flash
     * is shown on the next request and then aged out as usual — never stranded.
     *
     * @param  array<string, mixed>  $storedAll  the whole stored session
     * @param  array<int, mixed>|null  $othersFlashed  out: keys another request flashed
     * @return array{old: array<int, string>, new: array<int, string>}
     */
    protected function mergeFlash(mixed $storedFlash, array $storedAll, ?array &$othersFlashed = null): array
    {
        $loaded = $this->loadedFlash;
        $stored = is_array($storedFlash) ? $storedFlash : [];

        $out = [];
        $othersFlashed = [];
        foreach (['old', 'new'] as $list) {
            $theirs = array_values(array_filter(
                (array) ($stored[$list] ?? []),
                fn ($key) => ! in_array($key, (array) ($loaded[$list] ?? []), true)
                    // re-flashed under the same key (e.g. "status" twice): the stored value is not the one we loaded
                    || (array_key_exists((string) $key, $storedAll) && $this->fingerprint($storedAll[(string) $key]) !== ($this->loadedSnapshot[(string) $key] ?? null)),
            ));
            $othersFlashed = array_merge($othersFlashed, $theirs);
            $out[$list] = array_values(array_unique(array_merge((array) $this->get('_flash.'.$list, []), $theirs)));
        }
        $othersFlashed = array_values(array_unique(array_map('strval', $othersFlashed)));

        return $out;
    }

    /** @param  array<string, mixed>  $attributes */
    protected function encodeAttributes(array $attributes): string
    {
        return $this->serialization === 'json' ? (string) json_encode($attributes) : serialize($attributes);
    }

    protected function fingerprint(mixed $value): string
    {
        try {
            return serialize($value);
        } catch (\Throwable) {
            return spl_object_hash((object) ['v' => $value]);
        }
    }

    protected function resetMergeState(): void
    {
        $this->loadedSnapshot = null;
        $this->loadedFlash = [];
        $this->loadedId = null;
        $this->ownsWholeSession = false;
    }
}

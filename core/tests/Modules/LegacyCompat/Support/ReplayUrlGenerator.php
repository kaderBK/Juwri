<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat\Support;

use Juwri\Modules\LegacyCompat\Support\QrIdDecoder;

/**
 * M7-B: the old printed-URL forms of the synthetic legacy set as an ordered, deterministic list of replay cases.
 *
 * The list is built from the manifest {@see \Juwri\Modules\LegacyCompat\Database\Factories\LegacyMbcFixture::populate()}
 * returns, so it holds only invented values (J19). Every case is one HTTP request: the path (percent-encoded), the
 * method and what the answer must be — the status, the exact Location, the Cache-Control rule, the scan counted for
 * the store and the table/room context the page must show.
 *
 * Two facts about the current code shape the expectations and are NOT bugs to fix here:
 *
 * - `/restaurant/{slug}` and `/restaurant/{slug}/{title}` are answered by CORE's own route
 *   (`restaurant/{slug}/{slug_title?}`), not by the module: the module's route is a Laravel FALLBACK route, so every
 *   non-fallback route is matched first (security review B2). Over HTTP those paths therefore render the storefront
 *   (200) and the module's 301 is never reached; the module's 301 contract is asserted against the controller in
 *   {@see \Tests\Modules\LegacyCompat\LegacyQrReplayTest}.
 * - a slug that is also a core top-level path (`admin`, `r`, `ar` — plan §5.4) gets NO case at all: the module must
 *   not answer it. The skipped ones are listed by {@see skipped()} so the test can assert them by name (OPEN item).
 */
final class ReplayUrlGenerator
{
    /** Slugs that collide with a core top-level path (plan §5.4): no case is emitted for them. */
    public const RESERVED_SLUGS = ['admin', 'r', 'ar'];

    /** The frozen vector file: the only source of the two old passphrases. */
    public const VECTORS_FILE = 'tests/Fixtures/LegacyCompat/qr-id-vectors.json';

    /** A passphrase that is NOT configured: a code made with it decodes to nothing. */
    private const UNKNOWN_PASSPHRASE = 'not-configured-passphrase';

    /** Not Base64 at all. */
    private const BAD_BASE64 = '!!!';

    /** The printed source marker of a flyer QR. */
    private const SRC = 'flyer';

    /** The title segment of the old `/restaurant/{slug}/{title}` form. */
    private const TITLE = 'some-title';

    /** @var list<array<string, mixed>> the manifest's active stores, in manifest order */
    private array $stores;

    /** @var list<string> */
    private array $passphrases;

    /**
     * @param  array<string, mixed>  $manifest  the manifest LegacyMbcFixture::populate() returns
     * @param  list<string>  $passphrases  the two old passphrases (see {@see passphrases()})
     */
    public function __construct(array $manifest, array $passphrases)
    {
        $stores = [];
        foreach ($manifest['stores'] ?? [] as $store) {
            if (is_array($store) && ($store['active'] ?? false) === true) {
                $stores[] = $store;
            }
        }

        $this->stores = $stores;
        $this->passphrases = array_values(array_filter($passphrases, 'is_string'));
    }

    /**
     * The two old passphrases of the frozen vector file. Never hard-coded here: the file is the only source.
     *
     * @return list<string>
     */
    public static function passphrases(): array
    {
        $file = base_path(self::VECTORS_FILE);
        $json = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $pass = is_array($json) ? ($json['passphrases'] ?? []) : [];

        $out = [];
        foreach (['variant_a', 'variant_b'] as $key) {
            if (isset($pass[$key]) && is_string($pass[$key]) && $pass[$key] !== '') {
                $out[] = $pass[$key];
            }
        }

        return $out;
    }

    /**
     * Every replay case, in a fixed order (store order, then form order).
     *
     * @return list<array{id: string, method: string, url: string, expect: array<string, mixed>}>
     */
    public function cases(): array
    {
        $cases = [];
        $replayable = $this->replayable();

        foreach ($replayable as $store) {
            foreach ($this->casesFor($store, $replayable) as $case) {
                $cases[] = $case;
            }
        }

        // The forms that name no store at all: core answers 404 for them (the module has nothing to add).
        $cases[] = $this->case('g-restaurant-unknown', 'GET', '/restaurant/no-such-store', [
            'status' => 404,
            'scan' => 0,
            'note' => 'no store under that slug: 404, never a redirect',
        ]);
        $cases[] = $this->case('g-restaurant-unknown-title', 'GET', '/restaurant/no-such-store/'.self::TITLE, [
            'status' => 404,
            'scan' => 0,
            'note' => 'no store under that slug with a title: 404, never a redirect',
        ]);

        return $cases;
    }

    /**
     * The stores that get no case: their slug is a core top-level path (plan §5.4, OPEN).
     *
     * @return list<array{store_id: int, slug: string, reason: string}>
     */
    public function skipped(): array
    {
        $out = [];
        foreach ($this->stores as $store) {
            $slug = (string) ($store['slug'] ?? '');
            if (in_array($slug, self::RESERVED_SLUGS, true)) {
                $out[] = [
                    'store_id' => (int) ($store['id'] ?? 0),
                    'slug' => $slug,
                    'reason' => 'the slug is a core top-level path (plan 5.4): the module must not answer it',
                ];
            }
        }

        return $out;
    }

    /**
     * The cases of one store.
     *
     * @param  array<string, mixed>  $store
     * @param  list<array<string, mixed>>  $all
     * @return list<array{id: string, method: string, url: string, expect: array<string, mixed>}>
     */
    private function casesFor(array $store, array $all): array
    {
        $sid = (int) ($store['id'] ?? 0);
        $slug = (string) ($store['slug'] ?? '');
        $path = '/'.rawurlencode($slug);
        $base = url($path);

        $passA = $this->passphrases[0] ?? '';
        $passB = $this->passphrases[1] ?? $passA;
        $qrA = $this->qr($slug, $passA);
        $qrB = $this->qr($slug, $passB);

        $table = $store['tables'][0] ?? null;
        $tableKey = is_array($table) ? (string) ($table['key'] ?? '') : '';
        $tableId = is_array($table) ? (int) ($table['id'] ?? 0) : 0;

        $branch = (int) ($store['branches'][0] ?? 0);
        $room = $store['rooms'][0] ?? null;
        $roomKey = is_array($room) ? (string) ($room['key'] ?? '') : '';
        $roomId = is_array($room) ? (int) ($room['id'] ?? 0) : 0;
        $roomToken = is_array($room) ? (string) ($room['token'] ?? '') : $this->plainToken($sid);

        $foreign = $this->foreignStore($store, $all);
        $foreignTableKey = (string) ($foreign['tables'][0]['key'] ?? '');
        $foreignBranch = (int) ($foreign['branches'][0] ?? 0);
        $foreignRoomToken = $this->foreignRoomToken($store, $all);

        $cases = [];

        $cases[] = $this->case('s'.$sid.'-plain', 'GET', $path, [
            'status' => 200,
            'scan' => 0,
            'note' => 'the storefront page of the migrated store',
        ]);

        if ($slug !== (string) $sid) {
            $cases[] = $this->case('s'.$sid.'-id', 'GET', '/'.$sid, [
                'status' => 200,
                'scan' => 0,
                'note' => 'the numeric id form answers the same page as the slug',
            ]);
        }

        $cases[] = $this->case('s'.$sid.'-qr-a', 'GET', $path.'?qr-id='.$qrA, [
            'status' => 302,
            'location' => $base,
            'cache' => 'no-store',
            'scan' => 1,
            'note' => 'a code of the first old passphrase: one scan, the clean path, nothing added',
        ]);

        $cases[] = $this->case('s'.$sid.'-qr-b', 'GET', $path.'?qr-id='.$qrB, [
            'status' => 302,
            'location' => $base,
            'cache' => 'no-store',
            'scan' => 1,
            'note' => 'the second old passphrase decodes the same way',
        ]);

        $cases[] = $this->case('s'.$sid.'-qr-a-table', 'GET', $path.'?qr-id='.$qrA.'&table='.$tableKey, [
            'status' => 302,
            'location' => $base.'?table='.$tableKey,
            'cache' => 'no-store',
            'scan' => 1,
            'note' => 'the table key is kept and comes first',
        ]);

        $cases[] = $this->case('s'.$sid.'-qr-a-src', 'GET', $path.'?qr-id='.$qrA.'&src='.self::SRC, [
            'status' => 302,
            'location' => $base.'?src='.self::SRC,
            'cache' => 'no-store',
            'scan' => 1,
            'note' => 'the printed source marker is kept',
        ]);

        $cases[] = $this->case('s'.$sid.'-qr-a-rt', 'GET', $path.'?qr-id='.$qrA.'&rt='.$roomToken, [
            'status' => 302,
            'location' => $base.'?rt='.$roomToken,
            'cache' => 'no-store',
            'scan' => 1,
            'note' => 'the room token is carried, never checked here',
        ]);

        $cases[] = $this->case('s'.$sid.'-qr-a-table-src-rt', 'GET', $path.'?qr-id='.$qrA.'&table='.$tableKey.'&src='.self::SRC.'&rt='.$roomToken, [
            'status' => 302,
            'location' => $base.'?table='.$tableKey.'&src='.self::SRC.'&rt='.$roomToken,
            'cache' => 'no-store',
            'scan' => 1,
            'note' => 'table, src and rt lead the clean URL, in that order',
        ]);

        $cases[] = $this->case('s'.$sid.'-tampered-other-store', 'GET', $path.'?qr-id='.$this->qr((string) ($foreign['slug'] ?? ''), $passA), [
            'status' => 200,
            'scan' => 0,
            'note' => 'a code of another store: served direct, no scan',
        ]);

        $cases[] = $this->case('s'.$sid.'-tampered-base64', 'GET', $path.'?qr-id='.self::BAD_BASE64, [
            'status' => 200,
            'scan' => 0,
            'note' => 'not Base64: served direct, no scan',
        ]);

        $upper = strtoupper($slug);
        if ($upper !== $slug) {
            $cases[] = $this->case('s'.$sid.'-tampered-case', 'GET', $path.'?qr-id='.$this->qr($upper, $passA), [
                'status' => 200,
                'scan' => 0,
                'note' => 'the code of the same slug in another case: served direct, no scan',
            ]);
        }

        $cases[] = $this->case('s'.$sid.'-tampered-passphrase', 'GET', $path.'?qr-id='.$this->qr($slug, self::UNKNOWN_PASSPHRASE), [
            'status' => 200,
            'scan' => 0,
            'note' => 'a code of a passphrase that is not configured: served direct, no scan',
        ]);

        $cases[] = $this->case('s'.$sid.'-table-own', 'GET', $path.'?table='.$tableKey, [
            'status' => 200,
            'scan' => 0,
            'table' => $tableId,
            'note' => 'a table key of this store opens that table',
        ]);

        $cases[] = $this->case('s'.$sid.'-table-foreign', 'GET', $path.'?table='.$foreignTableKey, [
            'status' => 200,
            'scan' => 0,
            'table' => null,
            'note' => 'a table key of another store: the menu without a table',
        ]);

        $cases[] = $this->case('s'.$sid.'-table-unknown', 'GET', $path.'?table=no-such-key-'.$sid, [
            'status' => 200,
            'scan' => 0,
            'table' => null,
            'note' => 'an unknown table key: the menu without a table',
        ]);

        $cases[] = $this->case('s'.$sid.'-branch-own', 'GET', $path.'?branch_id='.$branch, [
            'status' => 200,
            'scan' => 0,
            'note' => 'a branch of this store: the page of that branch',
        ]);

        $cases[] = $this->case('s'.$sid.'-branch-foreign', 'GET', $path.'?branch_id='.$foreignBranch, [
            'status' => 200,
            'scan' => 0,
            'note' => 'a branch of another store: never an error, the menu without that branch',
        ]);

        $cases[] = $this->case('s'.$sid.'-restaurant-slug', 'GET', '/restaurant/'.rawurlencode($slug), [
            'status' => 200,
            'scan' => 0,
            'note' => 'core answers this path itself (the module route is a fallback): the storefront, not a redirect',
        ]);

        $cases[] = $this->case('s'.$sid.'-restaurant-title', 'GET', '/restaurant/'.rawurlencode($slug).'/'.self::TITLE, [
            'status' => 200,
            'scan' => 0,
            'note' => 'core answers this path itself (the module route is a fallback): the storefront, not a redirect',
        ]);

        $cases[] = $this->case('s'.$sid.'-restaurant-id', 'GET', '/restaurant/'.$sid, [
            'status' => 200,
            'scan' => 0,
            'note' => 'the numeric id under /restaurant: core answers it as the storefront',
        ]);

        $cases[] = $this->case('s'.$sid.'-restaurant-table', 'GET', '/restaurant/'.rawurlencode($slug).'?table='.$tableKey.'&x=1', [
            'status' => 200,
            'scan' => 0,
            'table' => $tableId,
            'note' => 'the query is read on the core path too: the table is open, the extra param is ignored',
        ]);

        if (is_array($room)) {
            $cases[] = $this->case('s'.$sid.'-room-link', 'GET', $path.'?table='.$roomKey.'&rt='.$roomToken, [
                'status' => 200,
                'scan' => 0,
                'table' => $roomId,
                'room_token' => $roomToken,
                'note' => 'the printed room QR: the room is open and the token is stashed in the session',
            ]);
        }

        if ($foreignRoomToken !== null) {
            $cases[] = $this->case('s'.$sid.'-qr-a-rt-other-room', 'GET', $path.'?qr-id='.$qrA.'&rt='.$foreignRoomToken, [
                'status' => 302,
                'location' => $base.'?rt='.$foreignRoomToken,
                'cache' => 'no-store',
                'scan' => 1,
                'note' => 'the token of another room is carried as it is: it is checked at charge time, not here',
            ]);
        }

        $cases[] = $this->case('s'.$sid.'-head-qr', 'HEAD', $path.'?qr-id='.$qrA, [
            'status' => 200,
            'scan' => 0,
            'note' => 'a HEAD request is never redirected and never counted',
        ]);

        $cases[] = $this->case('s'.$sid.'-post-qr', 'POST', $path.'?qr-id='.$qrA, [
            'status' => 405,
            'scan' => 0,
            'note' => 'a POST is never redirected: the storefront path has no POST route',
        ]);

        return $cases;
    }

    /**
     * The active stores that get cases (the reserved slugs do not).
     *
     * @return list<array<string, mixed>>
     */
    private function replayable(): array
    {
        $out = [];
        foreach ($this->stores as $store) {
            if (! in_array((string) ($store['slug'] ?? ''), self::RESERVED_SLUGS, true)) {
                $out[] = $store;
            }
        }

        return $out;
    }

    /**
     * The next store of the list (a different one), used for the foreign table key and branch.
     *
     * @param  array<string, mixed>  $store
     * @param  list<array<string, mixed>>  $all
     * @return array<string, mixed>
     */
    private function foreignStore(array $store, array $all): array
    {
        $count = count($all);
        if ($count < 2) {
            return $store;
        }

        $index = 0;
        foreach ($all as $position => $candidate) {
            if ((int) ($candidate['id'] ?? 0) === (int) ($store['id'] ?? 0)) {
                $index = $position;

                break;
            }
        }

        return $all[($index + 1) % $count];
    }

    /**
     * The first room token of ANOTHER store (the room token is checked at charge time, not on the page).
     *
     * @param  array<string, mixed>  $store
     * @param  list<array<string, mixed>>  $all
     */
    private function foreignRoomToken(array $store, array $all): ?string
    {
        $count = count($all);
        if ($count < 2) {
            return null;
        }

        $index = 0;
        foreach ($all as $position => $candidate) {
            if ((int) ($candidate['id'] ?? 0) === (int) ($store['id'] ?? 0)) {
                $index = $position;

                break;
            }
        }

        for ($step = 1; $step <= $count; $step++) {
            $candidate = $all[($index + $step) % $count];
            $rooms = $candidate['rooms'] ?? [];
            if (is_array($rooms) && $rooms !== []) {
                $token = (string) ($rooms[0]['token'] ?? '');

                return $token !== '' ? $token : null;
            }
        }

        return null;
    }

    /** A room token of no room (only carried): deterministic, 40 hex characters like a real one. */
    private function plainToken(int $storeId): string
    {
        return substr(hash('sha256', 'rt|'.$storeId), 0, 40);
    }

    /** The old qr-id of $plaintext under $passphrase, percent-encoded for the query string. */
    private function qr(string $plaintext, string $passphrase): string
    {
        return urlencode(QrIdDecoder::encode($plaintext, $passphrase));
    }

    /**
     * @param  array<string, mixed>  $expect
     * @return array{id: string, method: string, url: string, expect: array<string, mixed>}
     */
    private function case(string $id, string $method, string $url, array $expect): array
    {
        return ['id' => $id, 'method' => $method, 'url' => $url, 'expect' => $expect];
    }
}

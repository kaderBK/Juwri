<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Services\Rwg\Feeds;

/**
 * GOOGLE-PARTNER Lane C — one Reserve-with-Google feed file.
 *
 * A contributor produces exactly one JSON feed (Merchants / Services / Availability
 * in Lane C; Waitlist in Lane D). RwgFeedBuilder discovers contributors from the
 * `services.reserve_with_google.feed_builders` config array and asks each for its
 * filename + payload — so a lane adds a feed by writing a class and appending its
 * name to that array, never by editing another lane's files.
 */
interface RwgFeedContributor
{
    /** The feed filename written under storage/app/rwg/ and dropped via SFTP (e.g. 'merchant_feed.json'). */
    public function filename(): string;

    /**
     * The full feed as a JSON-serializable array (Actions Center generic-feed shape:
     * a `metadata` object + the feed's entity array). Use RwgFeedBuilder::metadata().
     *
     * @return array<string,mixed>
     */
    public function build(): array;
}

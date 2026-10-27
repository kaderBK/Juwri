<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Content;

use Juwri\Core\Editions\Community\NoResellers;

/**
 * P1-13 extension point: the reseller-owned content the Content module's blog autopilot and admin screens read and
 * write (reseller blog posts, reseller pages). The Reseller module (ee) binds it; Community has no resellers
 * ({@see NoResellers}: reads are empty, writes refuse).
 */
interface ResellerContent
{
    /** The reseller's brand name; throws when the reseller does not exist (findOrFail semantics). */
    public function brandName(int $resellerId): string;

    /** The reseller's default content locale. */
    public function defaultLocale(int $resellerId): string;

    /** The user who owns the reseller, or null. */
    public function ownerUserId(int $resellerId): ?int;

    /**
     * The reseller's latest published blog posts, newest first: slug + per-locale title map.
     *
     * @return list<array{slug: string, title: array<string, string>}>
     */
    public function publishedPosts(int $resellerId, int $limit): array;

    public function postSlugTaken(int $resellerId, string $slug): bool;

    /**
     * Create a blog post for the reseller; returns its id.
     *
     * @param  array{slug: string, title: array<string, string>, excerpt: array<string, string>, body: array<string, string>, tags: string, status: string, cover_image?: ?string}  $attributes
     */
    public function createPost(int $resellerId, array $attributes): int;

    /**
     * How many of these posts were created since the date.
     *
     * @param  list<int>  $storeIds
     */
    public function postsCreatedSince(int $resellerId, array $storeIds, \DateTimeInterface $since): int;

    /**
     * Default page / blog-article caps per reseller (admin feature controls).
     *
     * @return array{pages: int, blogPosts: int}
     */
    public function pageCapDefaults(): array;

    /**
     * Reseller pages per locked template id.
     *
     * @return array<int|string, int>
     */
    public function lockedTemplateUsage(): array;
}

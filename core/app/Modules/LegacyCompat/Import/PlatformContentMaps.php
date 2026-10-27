<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

/**
 * J59g platform content: the blog (posts, categories and their links), the help and legal pages, FAQ entries,
 * testimonials and announcements. Platform rows (no store). Ids are kept (blog posts and pages are in URLs the old
 * domain served, and the migrated install runs on that same domain, J59b), except for the kinds this install ships rows
 * of its own (languages, testimonials): those are added with new ids. A blog author is kept only when that user
 * was imported. Languages this install does not ship yet are added (new ids: a language is addressed by its code).
 */
final class PlatformContentMaps
{
    private const TIMESTAMPS = ['created_at' => 'created_at', 'updated_at' => 'updated_at'];

    /** Longest testimonial text this install stores (the target column). */
    public const TESTIMONIAL_MAX = 255;

    /** @return list<TableMap> platform loaders in write order (categories and posts before their links) */
    public static function platform(): array
    {
        return [
            self::languages(), self::blogCategories(), self::blogPosts(), self::blogCategoryLinks(), self::pages(),
            self::faqEntries(), self::testimonials(), self::announcements(),
        ];
    }

    public static function blogCategories(): TableMap
    {
        return new TableMap(
            source: 'blog_categories', target: 'blog_categories', scope: TableMap::PLATFORM,
            columns: ['title' => 'title', 'slug' => 'slug', 'position' => 'position'], bools: ['active' => 'active'],
        );
    }

    public static function blogPosts(): TableMap
    {
        $same = ['author', 'title', 'slug', 'locale', 'translation_group', 'description', 'meta_description', 'image', 'tags', 'status'];

        return new TableMap(
            source: 'blog', target: 'blog_posts', scope: TableMap::PLATFORM,
            columns: array_combine($same, $same), dates: self::TIMESTAMPS, refs: ['author' => 'users'],
            transform: static function (array $out): array {
                if (! in_array($out['status'] ?? null, ['publish', 'pending'], true)) {
                    $out['status'] = 'pending'; // an unknown state is never published by the move
                }

                return $out;
            },
        );
    }

    public static function blogCategoryLinks(): TableMap
    {
        return new TableMap(
            source: 'blog_cat_relation', target: 'blog_category_post', scope: TableMap::PLATFORM,
            columns: ['blog_id' => 'blog_id', 'category_id' => 'category_id'],
            requiredRefs: ['blog_id' => 'blog_posts', 'category_id' => 'blog_categories'],
        );
    }

    public static function pages(): TableMap
    {
        $same = ['translation_lang', 'type', 'builder', 'slug', 'title', 'content', 'design_json'];

        return new TableMap(
            source: 'pages', target: 'pages', scope: TableMap::PLATFORM,
            columns: array_combine($same, $same), dates: self::TIMESTAMPS, bools: ['active' => 'active'],
            transform: static function (array $out): array {
                $out['type'] = (string) ($out['type'] ?? '0') === '1' ? '1' : '0';

                return $out;
            },
        );
    }

    public static function faqEntries(): TableMap
    {
        return new TableMap(
            source: 'faq_entries', target: 'faq_entries', scope: TableMap::PLATFORM,
            columns: ['faq_title' => 'faq_title', 'faq_content' => 'faq_content', 'translation_lang' => 'translation_lang'],
            bools: ['active' => 'active'],
        );
    }

    /**
     * Testimonials: this install ships sample ones, so the source's are added with new ids (a testimonial is in no URL).
     * A text longer than this install stores is skipped (reported), never cut.
     */
    public static function testimonials(): TableMap
    {
        return new TableMap(
            source: 'testimonials', target: 'testimonials', scope: TableMap::PLATFORM,
            columns: ['name' => 'name', 'designation' => 'designation', 'content' => 'content', 'image' => 'image', 'translations' => 'translations'],
            dates: self::TIMESTAMPS, freshIds: true,
            transform: static function (array $out): array|string {
                if (mb_strlen((string) ($out['content'] ?? '')) > self::TESTIMONIAL_MAX) {
                    return 'testimonial longer than '.self::TESTIMONIAL_MAX.' characters (re-enter it shorter)';
                }
                foreach (['name', 'designation', 'content', 'image'] as $column) {
                    $out[$column] = (string) ($out[$column] ?? '');
                }

                return $out;
            },
        );
    }

    public static function announcements(): TableMap
    {
        $same = ['title', 'body', 'type', 'link_url', 'link_label', 'starts_at', 'ends_at', 'target'];

        return new TableMap(
            source: 'announcements', target: 'announcements', scope: TableMap::PLATFORM,
            columns: array_combine($same, $same), dates: self::TIMESTAMPS, bools: ['active' => 'active'],
        );
    }

    /**
     * Languages: this install ships its own; a source language whose code is already here is kept as is (counted), a
     * new one is added with a new id.
     */
    public static function languages(): TableMap
    {
        return new TableMap(
            source: 'languages', target: 'languages', scope: TableMap::PLATFORM,
            columns: ['code' => 'code', 'direction' => 'direction', 'name' => 'name', 'position' => 'position'],
            bools: ['active' => 'active'], freshIds: true,
            transform: static function (array $out, array $row, ImportContext $ctx): array|string {
                $code = strtolower(trim((string) ($out['code'] ?? '')));
                if ($code === '') {
                    return 'language without a code';
                }
                if ($ctx->target->table('languages')->whereRaw('lower(code) = ?', [$code])->exists()) {
                    return 'language already on this install';
                }

                return $out;
            },
        );
    }
}

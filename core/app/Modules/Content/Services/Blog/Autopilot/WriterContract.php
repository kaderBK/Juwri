<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\Blog\Autopilot;

use Juwri\Modules\Content\Models\BlogTopic;

/**
 * Blog Autopilot writing-engine seam.
 *
 * Lane A implements this (AutopilotWriter) and binds it in a service
 * provider; Lane B codes controllers against the contract and binds fakes in
 * tests. Neither lane edits this interface — changes go through F0.
 */
interface WriterContract
{
    /**
     * Suggest fresh, deduplicated topic ideas. Platform ideas ($resellerId
     * null) are grounded in product facts (FeatureRegistry, existing published
     * titles for dedupe); reseller ideas in the reseller's brand + locales.
     * Does NOT persist — callers create the BlogTopic rows (source=ai).
     *
     * @return array<int, array{title: string, keyword: string, angle: string}>
     */
    public function suggestTopics(int $count, ?int $resellerId = null): array;

    /**
     * Write a complete draft for the topic: outline -> sections -> assembly ->
     * meta description/tags/internal links (+ hero image when enabled).
     * Creates the Blog (status 'pending') or ResellerBlogPost (draft) row, links
     * it on the topic, sets topic status 'drafted', and returns the created
     * post id. Throws on unrecoverable AI failure (the job marks the topic
     * failed).
     */
    public function writeDraft(BlogTopic $topic): int;
}

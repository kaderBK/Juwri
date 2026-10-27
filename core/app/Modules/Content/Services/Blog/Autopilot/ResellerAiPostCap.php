<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\Blog\Autopilot;

use App\Services\Entitlements;
use Juwri\Core\Contracts\Content\ResellerContent;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\User;
use Juwri\Modules\Content\Models\BlogTopic;

/**
 * Blog Autopilot A5 — V2 monetization: per-reseller monthly AI-post cap.
 *
 * Hybrid model (owner decision 3): platform AI keys + a monthly cap from the
 * reseller owner's plan settings key `ai_blog_posts_limit` (0 = feature off,
 * DEFAULT; 999 = unlimited sentinel). A reseller-scoped BYOK AI credential
 * (feature='reseller_ai') removes the cap entirely.
 *
 * Enforcement is best-effort check-then-dispatch (SPEC rule 13): surfaces call
 * allows() before dispatching, and the writer re-checks inside the job.
 */
class ResellerAiPostCap
{
    /** Autopilot-created ResellerPosts this calendar month (marked via topic rows). */
    public function used(int $resellerId): int
    {
        $storeIds = BlogTopic::forReseller($resellerId)
            ->whereNotNull('reseller_blog_post_id')
            ->pluck('reseller_blog_post_id');

        if ($storeIds->isEmpty()) {
            return 0;
        }

        return app(ResellerContent::class)->postsCreatedSince($resellerId, $storeIds->map(fn ($id) => (int) $id)->values()->all(), now()->startOfMonth());
    }

    /** Monthly cap from the owner's plan settings. 0 = off, 999 = unlimited. */
    public function limit(int $resellerId): int
    {
        $ownerId = app(ResellerContent::class)->ownerUserId($resellerId);
        $owner = $ownerId ? User::find($ownerId) : null;
        if (! $owner) {
            return 0;
        }

        $raw = Entitlements::planValue($owner, (string) config('blog_autopilot.reseller_cap_key', 'ai_blog_posts_limit'));

        return ($raw === null || $raw === '') ? 0 : (int) $raw;
    }

    /** Reseller-scoped BYOK AI key (feature='reseller_ai') lifts the cap. */
    public function byok(int $resellerId): bool
    {
        try {
            return Credential::where('reseller_id', $resellerId)
                ->whereNull('store_id')
                ->where('feature', 'reseller_ai')
                ->where('active', true)
                ->whereNotNull('secrets')
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** byok || used < limit (999 = always, 0 = never). */
    public function allows(int $resellerId): bool
    {
        if ($this->byok($resellerId)) {
            return true;
        }

        $limit = $this->limit($resellerId);
        if ($limit === 999) {
            return true;
        }
        if ($limit <= 0) {
            return false;
        }

        return $this->used($resellerId) < $limit;
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One row in the Blog Autopilot topic backlog.
 *
 * reseller_id NULL = platform blog topic (drafts into Blog); set = reseller
 * white-label topic (drafts into ResellerBlogPost). Status flow:
 * queued -> drafting -> drafted -> published, or failed / rejected.
 */
class BlogTopic extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reseller_id',
        'title',
        'keyword',
        'angle',
        'locale',
        'status',
        'source',
        'blog_id',
        'reseller_blog_post_id',
        'error',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Blog, $this> */
    public function blog()
    {
        return $this->belongsTo(Blog::class, 'blog_id');
    }

    /**
     * @param  Builder<BlogTopic>  $q
     * @return Builder<BlogTopic>
     */
    public function scopeQueued(Builder $q): Builder
    {
        return $q->where('status', 'queued');
    }

    /**
     * @param  Builder<BlogTopic>  $q
     * @return Builder<BlogTopic>
     */
    public function scopePlatform(Builder $q): Builder
    {
        return $q->whereNull('reseller_id');
    }

    /**
     * @param  Builder<BlogTopic>  $q
     * @return Builder<BlogTopic>
     */
    public function scopeForReseller(Builder $q, int $resellerId): Builder
    {
        return $q->where('reseller_id', $resellerId);
    }
}

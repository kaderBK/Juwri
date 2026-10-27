<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Models;

use Juwri\Modules\Content\Jobs\PingIndexNow;
use Juwri\Modules\Content\Jobs\TranslateBlogPostJob;
use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Juwri\Core\Models\User;

class Blog extends Model
{
    use HasFactory, Sluggable;

    /** Legacy singular table name. */
    public $table = 'blog_posts';

    /**
     * Columns the admin editor and the blog autopilot write.
     *
     * @var list<string>
     */
    protected $fillable = [
        'author',
        'title',
        'slug',
        'locale',
        'translation_group',
        'description',
        'meta_description',
        'image',
        'tags',
        'status',
    ];

    /**
     * Post URLs carry a slug generated from the title.
     *
     * @return array<string, array<string, string>>
     */
    public function sluggable(): array
    {
        return ['slug' => ['source' => 'title']];
    }

    protected static function booted(): void
    {
        // rev/seo R20: submit newly-published or edited-published posts to
        // IndexNow (Bing/Yandex) for near-instant crawl. Guarded so drafts and
        // unrelated saves don't ping, and no-ops when no key is configured.
        static::saved(function (Blog $blog) {
            if ($blog->status !== 'publish' || empty(config('seo.indexnow_key'))) {
                return;
            }
            // The test queue runs sync, so auto-pinging here would fire real
            // POSTs to the live IndexNow with the real key on every blog test.
            // Off in tests unless the IndexNow test explicitly opts in.
            if (app()->runningUnitTests() && ! config('seo.indexnow_test_optin')) {
                return;
            }
            if (! $blog->wasRecentlyCreated
                && ! $blog->wasChanged(['status', 'title', 'description', 'meta_description', 'slug'])) {
                return;
            }
            PingIndexNow::dispatch([
                url('/blog/'.$blog->id.'/'.$blog->slug),
                route('sitemap'),
            ]);
        });

        // Blog Autopilot A6: fan a published AUTOPILOT post (a BlogTopic row
        // points at it) out into the configured target locales. The job is
        // idempotent (skips existing translations), so manual admin re-saves
        // are safe; non-autopilot posts and empty locale config never dispatch.
        static::saved(function (Blog $blog) {
            if (TranslateBlogPostJob::shouldFanOut($blog)) {
                TranslateBlogPostJob::dispatch($blog->id);
            }
        });
    }

    /**
     * The author (blog.author holds the user id).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author');
    }

    /** @return HasMany<BlogComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(BlogComment::class, 'blog_id');
    }

    /**
     * Categories through the blog_cat_relation pivot.
     *
     * @return BelongsToMany<BlogCategory, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(BlogCategory::class, table: 'blog_category_post', foreignPivotKey: 'blog_id', relatedPivotKey: 'category_id');
    }
}

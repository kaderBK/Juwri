<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Models;

use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BlogCategory extends Model
{
    use HasFactory, Sluggable;

    public $table = 'blog_categories';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'title',
        'slug',
        'position',
        'active',
    ];

    /** @return array<string, array<string, string>> */
    public function sluggable(): array
    {
        return ['slug' => ['source' => 'title']];
    }

    /**
     * Posts filed under this category (blog_cat_relation pivot).
     *
     * @return BelongsToMany<Blog, $this>
     */
    public function blogs(): BelongsToMany
    {
        return $this->belongsToMany(Blog::class, table: 'blog_category_post', foreignPivotKey: 'category_id', relatedPivotKey: 'blog_id');
    }
}

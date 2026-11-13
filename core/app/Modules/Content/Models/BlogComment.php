<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Juwri\Core\Models\User;

class BlogComment extends Model
{
    use HasFactory;

    public $table = 'blog_comments';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'blog_id',
        'name',
        'email',
        'comment',
        'parent',
        'active',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Blog, $this> */
    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class, 'blog_id');
    }

    /** @return HasMany<BlogComment, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent');
    }
}

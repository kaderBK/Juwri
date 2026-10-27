<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Models;

use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Language;

class Page extends Model
{
    use HasFactory, Sluggable;

    public $table = 'pages';

    /** @var list<string> */
    protected $fillable = [
        'title',
        'content',
        'slug',
        'type',
        'translation_lang',
        'active',
        'builder',
        'design_json',
    ];

    /**
     * design_json is the Builder.js editor state (base template id + getData()),
     * reloaded verbatim into the editor on edit.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'design_json' => 'array',
    ];

    /**
     * True when this page is authored with the Builder.js freeform HTML builder
     * (rendered `content` is inline-CSS HTML) rather than the classic TinyMCE editor.
     */
    public function isBuilderMode(): bool
    {
        return ($this->builder ?? 'classic') === 'html';
    }

    /**
     * True when this page is authored as TRUSTED raw HTML + JS in the admin
     * (superadmin) panel — rendered as-authored (only nonce-stamped), NOT through
     * the Builder.js sanitiser. Admin-only; never reachable by store owners/resellers.
     */
    public function isTrustedMode(): bool
    {
        return ($this->builder ?? 'classic') === 'trusted';
    }

    /** @return array<string, array<string, string>> */
    public function sluggable(): array
    {
        return ['slug' => ['source' => 'title']];
    }

    /** @return BelongsTo<Language, $this> */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class, foreignKey: 'translation_lang', ownerKey: 'code');
    }
}

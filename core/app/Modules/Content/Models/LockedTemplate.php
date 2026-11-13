<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A superadmin-authored "locked" page template. Its code (HTML/CSS/JS) lives in
 * immutable versions; users only fill typed slots. See LockedTemplateVersion.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string|null $category
 * @property string|null $thumbnail
 * @property string $status
 * @property int|null $current_version
 */
class LockedTemplate extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'category',
        'thumbnail',
        'status',
        'current_version',
    ];

    /** @return HasMany<LockedTemplateVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(LockedTemplateVersion::class)->orderByDesc('version');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published' && $this->current_version !== null;
    }

    /** The version new pages start on (the published "current" one). */
    public function currentVersion(): ?LockedTemplateVersion
    {
        if ($this->current_version === null) {
            return null;
        }

        return $this->versionNumber((int) $this->current_version);
    }

    public function versionNumber(int $version): ?LockedTemplateVersion
    {
        return LockedTemplateVersion::query()
            ->where('locked_template_id', $this->id)
            ->where('version', $version)
            ->first();
    }

    public function latestVersion(): ?LockedTemplateVersion
    {
        return LockedTemplateVersion::query()
            ->where('locked_template_id', $this->id)
            ->orderByDesc('version')
            ->first();
    }
}

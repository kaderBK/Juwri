<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable version of a locked template. `manifest` is the compiled JSON the
 * renderer reads: sections (trusted HTML with `{{slot:key}}` placeholders), the slot
 * schema, palette + settings, and the stylesheet/script list. Assets are served from
 * `assets_base` (repo-root /assets/... — the docroot is the repo root).
 *
 * @property int $id
 * @property int $locked_template_id
 * @property int $version
 * @property string $manifest
 * @property string|null $library JSON list of ready-made sections users can add (see Manifest::library)
 * @property string $assets_base
 * @property string|null $notes
 * @property int|null $created_by
 */
class LockedTemplateVersion extends Model
{
    protected $fillable = [
        'locked_template_id',
        'version',
        'manifest',
        'library',
        'assets_base',
        'notes',
        'created_by',
    ];

    /** @var array<string, mixed>|null per-instance decode memo */
    private ?array $decoded = null;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $decodedLibrary = null;

    /** @return BelongsTo<LockedTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(LockedTemplate::class, 'locked_template_id');
    }

    /** @return array<string, mixed> */
    public function manifestData(): array
    {
        if ($this->decoded === null) {
            $data = json_decode((string) $this->manifest, true);
            $this->decoded = is_array($data) ? $data : [];
        }

        return $this->decoded;
    }

    /**
     * The section library (decoded lazily — it is large and most renders don't need it).
     *
     * @return array<int, array<string, mixed>>
     */
    public function libraryData(): array
    {
        if ($this->decodedLibrary === null) {
            $data = json_decode((string) $this->library, true);
            $this->decodedLibrary = is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
        }

        return $this->decodedLibrary;
    }

    /**
     * The manifest with the library attached under `library` — what the editor and
     * Content::merge work with, and what the renderer needs when the page uses a
     * library section.
     *
     * @return array<string, mixed>
     */
    public function manifestWithLibrary(): array
    {
        $m = $this->manifestData();
        $lib = $this->libraryData();
        if ($lib !== []) {
            $m['library'] = $lib;
        }

        return $m;
    }

    /**
     * Are this version's files still on disk? Templates can be withdrawn from a
     * deployment (the purchased marketing templates left the repo in juwri WI-05c)
     * while database rows and pages still point at them; callers then fall back
     * instead of serving a page whose stylesheets and images are gone.
     */
    public function assetsAvailable(): bool
    {
        $base = trim($this->assets_base, '/');

        return $base !== '' && ! str_contains($base, '..') && is_dir(public_path($base));
    }

    /** Public URL prefix for this version's assets (no trailing slash). */
    public function assetsUrl(): string
    {
        return rtrim(asset(trim($this->assets_base, '/')), '/');
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Models;

use App\Models\Concerns\TranslatesForStorefront;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * P3-08: the size guide content type — a measurement table a store writes once and links to its items
 * (`items.size_guide_id`). `columns` are the header labels ("Size", "Chest", "Waist"), `rows` one list of cells per
 * size; `unit` cm or in. Shown on the storefront item page (ItemDetail.size_guide_url) and read by the headless API.
 *
 * @property int $id
 * @property string|null $public_id
 * @property int $store_id
 * @property string $title
 * @property string $unit
 * @property list<string> $columns
 * @property list<list<string>> $rows
 * @property string|null $note
 * @property bool $active
 * @property int $position
 */
class SizeGuide extends Model
{
    use BelongsToAccount;
    use HasUuids;
    use TranslatesForStorefront;

    protected $table = 'size_guides';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'translations' => 'object',
        'columns' => 'array',
        'rows' => 'array',
        'active' => 'boolean',
        'position' => 'integer',
    ];

    /** Most columns and rows a guide may have (an owner form and an import are both held to it). */
    public const MAX_COLUMNS = 12;

    public const MAX_ROWS = 40;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** The title in the storefront visitor's language. */
    public function getTitleAttribute(mixed $value): mixed
    {
        return $this->storefrontTranslation('title') ?? $value;
    }

    /** The note in the storefront visitor's language. */
    public function getNoteAttribute(mixed $value): mixed
    {
        return $this->storefrontTranslation('note') ?? $value;
    }
}

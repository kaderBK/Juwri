<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * Theme Studio phase D: one AI theme-builder generation — up to three
 * proposals (rev/theme-studio/AI-BUILDER-CONTRACT.md `Proposal`) for a store
 * (store_id) or, for superadmin sets, a demo vertical. Addressed publicly by
 * `public_id` (a ULID), never by the auto-increment id.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $store_id
 * @property int|null $created_by
 * @property string|null $vertical
 * @property array<string,mixed>|null $brief
 * @property array<int|string,mixed>|null $proposals
 * @property array<string,mixed>|null $meta
 * @property bool $fallback
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 */
class AiThemeProposal extends Model
{
    use BelongsToAccount;

    protected $table = 'ai_theme_proposals';

    protected $guarded = [];

    protected $casts = [
        'brief' => 'array',
        'proposals' => 'array',
        'meta' => 'array',
        'fallback' => 'boolean',
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            if (empty($row->public_id)) {
                $row->public_id = strtolower((string) Str::ulid());
            }
        });
    }

    /** @param  Builder<self>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** @return array<string,mixed>|null the proposal with this id (p1…p3) */
    public function proposal(string $id): ?array
    {
        foreach ($this->proposals ?? [] as $proposal) {
            if (is_array($proposal) && ($proposal['id'] ?? null) === $id) {
                return $proposal;
            }
        }

        return null;
    }

    /** @return array{set_id:string,fallback:bool,proposals:list<array<string,mixed>>,expires_at:?string} */
    public function toContract(): array
    {
        return [
            'set_id' => (string) $this->public_id,
            'fallback' => (bool) $this->fallback,
            'proposals' => array_values($this->proposals ?? []),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}

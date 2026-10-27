<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Theme Studio phase E1: one AI-authored library section in the superadmin
 * review queue (/admin/theme-studio/sections).
 *
 * status: generating → passed (every gate green, awaiting review) | failed
 * (still failing after the repair rounds) → approved | rejected. Approval
 * only marks the row; `php artisan studio:section-pr {id}` (run from a
 * developer checkout) writes the files and opens the PR.
 *
 * @property int $id
 * @property string|null $type
 * @property string $status
 * @property string $prompt
 * @property list<string>|null $verticals
 * @property string $library_version
 * @property string|null $liquid
 * @property string|null $css
 * @property array<string,mixed>|null $schema
 * @property array{en?:array<string,string>,ar?:array<string,string>}|null $locales
 * @property string|null $fixture_notes
 * @property array<string,mixed>|null $gate_report
 * @property list<array<string,mixed>>|null $attempts
 * @property int $repair_rounds
 * @property array<string,string>|null $screenshots
 * @property string|null $error
 * @property int|null $created_by
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_notes
 * @property string|null $pr_url
 * @property Carbon|null $created_at
 */
class AiSectionDraft extends Model
{
    public const STATUS_GENERATING = 'generating';

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $table = 'ai_section_drafts';

    protected $guarded = [];

    protected $casts = [
        'verticals' => 'array',
        'schema' => 'array',
        'locales' => 'array',
        'gate_report' => 'array',
        'attempts' => 'array',
        'screenshots' => 'array',
        'repair_rounds' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    /** Only a draft whose gates all passed can be approved. */
    public function reviewable(): bool
    {
        return $this->status === self::STATUS_PASSED;
    }

    /**
     * The model answer shape (what SectionGates / Candidate read).
     *
     * @return array<string,mixed>
     */
    public function answer(): array
    {
        return [
            'type' => (string) $this->type,
            'liquid' => (string) $this->liquid,
            'css' => (string) $this->css,
            'schema' => $this->schema ?? [],
            'locales' => $this->locales ?? ['en' => [], 'ar' => []],
            'fixture_notes' => (string) $this->fixture_notes,
        ];
    }

    /** Storage dir (under storage/app) for this draft's screenshots. */
    public function storageDir(): string
    {
        return 'ai-section-drafts/'.$this->id;
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * #34 (#9): owner-approved translation memory. Reused before calling the AI so
 * shared strings translate consistently and quality compounds over time.
 */
class TranslationMemory extends Model
{
    use BelongsToAccount;

    protected $table = 'translation_memory_entries';

    protected $guarded = [];

    /** Look up an approved translation for a source string. */
    public static function lookup(int $storeId, string $lang, string $sourceText): ?string
    {
        $row = static::where('store_id', $storeId)
            ->where('lang', $lang)
            ->where('source_hash', sha1($sourceText))
            ->first();

        return $row?->target_text;
    }

    /** Store (or refresh) an approved translation. */
    public static function remember(int $storeId, string $lang, string $sourceText, string $targetText): void
    {
        $sourceText = trim($sourceText);
        $targetText = trim($targetText);
        if ($sourceText === '' || $targetText === '') {
            return;
        }

        static::updateOrCreate(
            ['store_id' => $storeId, 'lang' => $lang, 'source_hash' => sha1($sourceText)],
            ['source_text' => $sourceText, 'target_text' => $targetText]
        );
    }
}

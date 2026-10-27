<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Language;

class Faq extends Model
{
    use HasFactory;

    public $table = 'faq_entries';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'faq_title',
        'faq_content',
        'translation_lang',
        'active',
    ];

    /**
     * The language this entry is written for (none = every language).
     *
     * @return BelongsTo<Language, $this>
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class, foreignKey: 'translation_lang', ownerKey: 'code');
    }

    /**
     * Active FAQs for a locale, with an English fallback.
     *
     * A locale shows its OWN translated set when one exists; otherwise it falls
     * back to the English base (rows tagged 'en' OR left untagged/NULL). This is
     * an if/else, never an OR — so a translated language never shows the English
     * rows alongside its own (which the old `lang OR NULL` query did → dupes),
     * and a language with no translation yet shows English instead of a blank
     * page. Ordered by id so the on-page order is stable. Returns a Builder so
     * callers can ->limit() (home) or ->paginate() (/faq).
     *
     * @param  string|null  $lang  defaults to the current request locale
     * @return Builder<Faq>
     */
    public static function localized(?string $lang = null)
    {
        $lang = $lang ?: get_lang();

        // English base = rows tagged 'en' OR left untagged (NULL). A non-English
        // locale uses its OWN set when it has one, otherwise this base.
        $useOwnSet = $lang !== 'en'
            && static::where('active', 1)->where('translation_lang', $lang)->exists();

        return static::where('active', 1)
            ->when($useOwnSet,
                fn ($q) => $q->where('translation_lang', $lang),
                fn ($q) => $q->where(function ($w) {
                    $w->where('translation_lang', 'en')->orWhereNull('translation_lang');
                })
            )
            ->orderBy('id');
    }
}

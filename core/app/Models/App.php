<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Core\Models\User;
use Juwri\Modules\AppStore\Models\MarketplaceReview;

/**
 * P3-2: a registered third-party app. Its code runs on the PARTNER's servers;
 * this row is identity + requested scopes + webhook subscription. Only
 * `approved` apps are installable/listed (superadmin review gate).
 *
 * P1-05: `price` is stored as `price_minor` in the row's `currency_code` (the platform currency; a catalogue row —
 * an edited price moves to the platform's current currency). It reads as a number (the column was uncast).
 * `usage_price` stays a decimal: a per-unit rate with sub-minor precision (0.0125/msg), not an amount.
 *
 * @property float $price major amount
 * @property int $price_minor
 * @property string|null $currency_code
 */
class App extends Model implements MinorMoneyModel
{
    use HasMinorMoney;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['price' => 'price_minor'];

    protected $casts = [
        'scopes' => 'array',
        'webhook_events' => 'array',
        'grants_features' => 'array', // P4 feature-platform: ADMIN-ONLY (D9)
        'translations' => 'array',    // {locale: {name, tagline, description}} — display only
    ];

    /**
     * Store-catalog copy is localized for display: when the active locale has a
     * translation, cards/detail show it; otherwise the stored (English) value.
     * Search/sort still run on the raw columns, so English queries keep working.
     */
    public function getNameAttribute(?string $value): ?string
    {
        return $this->localizedField('name', $value);
    }

    public function getTaglineAttribute(?string $value): ?string
    {
        return $this->localizedField('tagline', $value);
    }

    public function getDescriptionAttribute(?string $value): ?string
    {
        return $this->localizedField('description', $value);
    }

    /** Active-locale translation for $field, else the stored $value. Null-safe. */
    protected function localizedField(string $field, ?string $value): ?string
    {
        $t = $this->translations[app()->getLocale()] ?? null;
        if (is_array($t) && isset($t[$field]) && is_string($t[$field]) && $t[$field] !== '') {
            return $t[$field];
        }

        return $value;
    }

    public function developer()
    {
        return $this->belongsTo(User::class, 'developer_user_id');
    }

    public function installations()
    {
        return $this->hasMany(AppInstallation::class);
    }

    public function media()
    {
        return $this->morphMany(MarketplaceMedia::class, 'mediable')->orderBy('position');
    }

    /** Screenshots only, ordered — the gallery source for the detail page. */
    public function screenshots()
    {
        return $this->media()->where('kind', 'screenshot');
    }

    /**
     * The square listing icon (kind=icon), if one was uploaded/generated.
     *
     * @return MorphOne<MarketplaceMedia, $this>
     */
    public function icon(): MorphOne
    {
        return $this->morphOne(MarketplaceMedia::class, 'mediable')->where('kind', 'icon');
    }

    public function reviews()
    {
        return $this->morphMany(MarketplaceReview::class, 'reviewable');
    }

    public function isInstallable(): bool
    {
        return $this->status === 'approved';
    }

    /** Short localized price label for cards/detail (free | $X/period | usage). */
    public function pricingSummary(): string
    {
        return match ($this->billing_type) {
            'recurring' => price_symbol_format($this->price).' '.(
                (int) $this->billing_period_days === 30
                    ? ___('/mo')
                    : ___('every :days days', ['days' => (int) $this->billing_period_days])
            ),
            'usage' => ___('Usage-based'),
            default => ___('Free'),
        };
    }

    public function isFreeBilling(): bool
    {
        return $this->billing_type === 'free';
    }
}

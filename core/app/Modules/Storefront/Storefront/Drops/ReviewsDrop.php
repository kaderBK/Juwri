<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use App\Models\MenuReview;
use Keepsuit\Liquid\Drop;

/**
 * A single approved item review, as it reaches `item.reviews` in a Liquid
 * template:
 *
 *   {% for r in item.reviews %}{{ r.rating }} {{ r.author }} {{ r.body }}{% endfor %}
 *
 * SECURITY-CRITICAL surface, same discipline as CustomerDrop: reviewer_name
 * (a diner-chosen display name), stars, body, is_verified and created_at
 * are the only things a public review card shows — never the linked
 * customer_id, session_hash, store_id, or any other column. The model
 * is held protected and never passed through.
 *
 * Unlike merchant-authored Drop strings (restaurant/item name & description,
 * which the storefront theme is expected to `| escape` itself per the theme
 * docs), a review's `author`/`body` are PUBLIC, diner-submitted free text —
 * a materially different trust tier. They are pre-escaped here as defense
 * in depth so a theme that forgets `| escape` can never render a stored
 * script from an anonymous review. A theme should NOT also pipe these two
 * through `| escape` (harmless double-encode, but pointless).
 */
class ReviewsDrop extends Drop
{
    public function __construct(protected MenuReview $review) {}

    public function id(): int
    {
        return (int) $this->review->id;
    }

    /** Diner-chosen display name — never the linked customer_id. Pre-escaped. */
    public function author(): ?string
    {
        return $this->escape($this->review->reviewer_name);
    }

    public function rating(): int
    {
        return (int) $this->review->stars;
    }

    /** Free-text review body — pre-escaped (see class docblock). */
    public function body(): ?string
    {
        return $this->escape($this->review->body);
    }

    /** True when the reviewer had a delivered order containing this item. */
    public function is_verified(): bool
    {
        return (bool) $this->review->is_verified;
    }

    public function created_at(): ?string
    {
        return optional($this->review->created_at)->toIso8601String();
    }

    private function escape(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

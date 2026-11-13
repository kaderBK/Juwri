<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use Keepsuit\Liquid\Drop;

/**
 * Theme Studio B0.6: the `social` global — contact and social links, from the
 * same sources as the Blade about sheet (instagram_url / facebook_url options,
 * google_review_url, storefront_whatsapp_number()). Null when not set.
 */
class SocialDrop extends Drop
{
    /** @param  array<string,mixed>  $data  built by App\Storefront\StorefrontExtras */
    public function __construct(protected array $data = []) {}

    /** Instagram profile URL. */
    public function instagram_url(): ?string
    {
        return isset($this->data['instagram_url']) && is_scalar($this->data['instagram_url']) ? (string) $this->data['instagram_url'] : null;
    }

    /** Facebook page URL. */
    public function facebook_url(): ?string
    {
        return isset($this->data['facebook_url']) && is_scalar($this->data['facebook_url']) ? (string) $this->data['facebook_url'] : null;
    }

    /** Google "write a review" URL. */
    public function google_review_url(): ?string
    {
        return isset($this->data['google_review_url']) && is_scalar($this->data['google_review_url']) ? (string) $this->data['google_review_url'] : null;
    }

    /** WhatsApp number, ASCII digits only. */
    public function whatsapp(): ?string
    {
        return isset($this->data['whatsapp']) && is_scalar($this->data['whatsapp']) ? (string) $this->data['whatsapp'] : null;
    }

    /** https://wa.me/<digits> link. */
    public function whatsapp_url(): ?string
    {
        return isset($this->data['whatsapp_url']) && is_scalar($this->data['whatsapp_url']) ? (string) $this->data['whatsapp_url'] : null;
    }

    /** Store phone. */
    public function phone(): ?string
    {
        return isset($this->data['phone']) && is_scalar($this->data['phone']) ? (string) $this->data['phone'] : null;
    }

    /** Store address. */
    public function address(): ?string
    {
        return isset($this->data['address']) && is_scalar($this->data['address']) ? (string) $this->data['address'] : null;
    }

    /** Google Maps search link for the address. */
    public function maps_url(): ?string
    {
        return isset($this->data['maps_url']) && is_scalar($this->data['maps_url']) ? (string) $this->data['maps_url'] : null;
    }
}

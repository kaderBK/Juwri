<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Contracts\Tenancy\ResellerContext;

/**
 * Reseller v2 P4 (4.6): the single per-request resolution of "whose brand is this".
 *
 * Resolution order:
 *   1. custom-domain host match (a reseller's own domain), then
 *   2. the authenticated user's reseller_id (a reseller's merchant on the PLATFORM
 *      domain still sees the reseller's brand — closes the biggest leak).
 *
 * Views/helpers consume this object instead of scattered brand_title()/brand_logo()
 * calls. Memoized on the container so it resolves at most once per request.
 *
 * P1-13: moved to Core; resellers come from the {@see ResellerContext} extension
 * point (Reseller, ee). In Community there is none, so the brand is the platform's.
 */
class BrandContext
{
    public function __construct(public ?Model $reseller) {}

    /**
     * Resolve the brand context for this request. Memoized on the container
     * (rebound per request in the real app); the underlying resolveForRequest /
     * forUser are cache-backed, so repeated calls are cheap either way.
     */
    public static function current(): self
    {
        $reseller = null;
        try {
            $resellers = app(ResellerContext::class);
            $reseller = $resellers->forRequest(); // host match (cache-backed)
            if (! $reseller && auth()->check() && auth()->user()->reseller_id) {
                $reseller = $resellers->forUser(auth()->user()); // cache-backed
            }
            if ($reseller && ! $reseller->getAttribute('active')) {
                $reseller = null; // only an active reseller brands the request
            }
        } catch (\Throwable $e) {
            $reseller = null;
        }

        return new self($reseller);
    }

    public function isReseller(): bool
    {
        return $this->reseller !== null;
    }

    public function name(): string
    {
        return $this->reseller && $this->reseller->getAttribute('brand_name')
            ? (string) $this->reseller->getAttribute('brand_name')
            : (string) config('settings.site_title', 'juwri');
    }

    public function shortName(): string
    {
        $name = $this->name();

        return str_contains($name, '|') ? trim((string) array_slice(explode('|', $name), -1)[0]) : $name;
    }

    public function logo(): ?string
    {
        return $this->reseller && $this->reseller->getAttribute('brand_logo')
            ? $this->reseller->getAttribute('brand_logo')
            : config('settings.site_logo');
    }

    public function favicon(): ?string
    {
        // C6: dedicated favicon (upload or URL) → the logo → the platform favicon.
        if ($this->reseller && $this->reseller->getAttribute('favicon_url')) {
            return (string) $this->reseller->getAttribute('favicon_url');
        }

        return $this->reseller && $this->reseller->getAttribute('brand_logo')
            ? $this->reseller->getAttribute('brand_logo')
            : (config('settings.site_favicon') ? asset('storage/logo/'.config('settings.site_favicon')) : null);
    }

    public function primaryColor(): ?string
    {
        // BL-036 R1: callers inline this into CSS — strict #rrggbb only.
        $platform = HexColor::safe(config('settings.theme_color'));

        return $this->reseller && $this->reseller->getAttribute('primary_color')
            ? HexColor::safe($this->reseller->getAttribute('primary_color'), $platform)
            : $platform;
    }

    public function supportEmail(): ?string
    {
        return $this->reseller && $this->reseller->getAttribute('support_email')
            ? $this->reseller->getAttribute('support_email')
            : config('settings.site_email');
    }

    public function supportUrl(): ?string
    {
        return $this->reseller?->getAttribute('support_url');
    }

    /** C6: reseller support phone (settings.support_contact.phone). */
    public function supportPhone(): ?string
    {
        $v = $this->reseller ? (($this->reseller->getAttribute('settings') ?? [])['support_contact']['phone'] ?? null) : null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    /** C6: reseller support WhatsApp number (settings.support_contact.whatsapp). */
    public function supportWhatsapp(): ?string
    {
        $v = $this->reseller ? (($this->reseller->getAttribute('settings') ?? [])['support_contact']['whatsapp'] ?? null) : null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    /** Whether the platform "Powered by" line should render (Agency removes it). */
    public function showPoweredBy(): bool
    {
        if (! $this->reseller) {
            return false; // platform site itself doesn't need a "powered by"
        }

        return ! app(ResellerContext::class)->entitled($this->reseller, 'remove_powered_by');
    }
}

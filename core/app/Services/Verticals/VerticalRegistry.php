<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Verticals;

use Juwri\Core\Models\Store;

/**
 * rev/multi-vertical F0-1 — reads config/verticals.php and answers capability
 * questions about a store's vertical. This is the seam every lane consumes
 * (DECISIONS F0-8): never branch on `store_type` with a literal string in a
 * controller/blade — ask the registry, so adding a vertical is one config entry.
 *
 * Resolve via the container: app(VerticalRegistry::class). Stateless; safe to
 * construct anywhere. (Deliberately no global helper — a new autoload.files entry
 * would force a composer dump-autoload in every worktree/prod checkout.)
 */
class VerticalRegistry
{
    /**
     * All configured vertical definitions, keyed by type.
     *
     * @return array<string,array<string,mixed>>
     */
    public function all(): array
    {
        return config('verticals.types', []);
    }

    /** The fallback vertical for stores with no/unknown store_type. */
    public function default(): string
    {
        return (string) config('verticals.default', 'restaurant');
    }

    /** Is $type a known vertical? */
    public function exists(string $type): bool
    {
        return array_key_exists($type, $this->all());
    }

    /**
     * The capability array for a type, falling back to the default vertical when
     * the type is unknown (so a legacy/garbage value never breaks the UI).
     *
     * @return array<string,mixed>
     */
    public function config(?string $type): array
    {
        $all = $this->all();
        $type = $type !== null && $type !== '' ? $type : $this->default();

        return $all[$type] ?? $all[$this->default()] ?? [];
    }

    /**
     * The capability array for a specific store.
     *
     * @return array<string,mixed>
     */
    public function forStore(Store $store): array
    {
        return $this->config($store->store_type ?? null);
    }

    /** A single capability value with a fallback. */
    public function capability(?string $type, string $key, mixed $fallback = null): mixed
    {
        return $this->config($type)[$key] ?? $fallback;
    }

    /** Boolean capability flag (food_attrs, variant_goods, fitment, age_gate, …). */
    public function has(?string $type, string $capability): bool
    {
        return (bool) ($this->config($type)[$capability] ?? false);
    }

    /** Shorthand: does this vertical use the food-only attribute set? */
    public function isFood(?string $type): bool
    {
        return $this->has($type, 'food_attrs');
    }

    /**
     * The DB `orders.type` values this vertical is allowed to use.
     *
     * @return list<string>
     */
    public function fulfillmentModes(?string $type): array
    {
        return (array) $this->capability($type, 'fulfillment', []);
    }

    /** The dashboard item-form partial key (food|retail|service). */
    public function itemForm(?string $type): string
    {
        return (string) $this->capability($type, 'item_form', 'food');
    }

    /** The storefront theme-set key (food|retail|service). */
    public function themeSet(?string $type): string
    {
        return (string) $this->capability($type, 'themes', 'food');
    }
}

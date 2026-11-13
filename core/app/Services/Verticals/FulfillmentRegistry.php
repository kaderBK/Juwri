<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Verticals;

/**
 * rev/multi-vertical F0-3 — the source of truth for `orders.type` values.
 *
 * Now that orders.type is a VARCHAR (not an enum), a new fulfillment mode is a
 * config entry here + a vertical that lists it, never a schema ALTER. A store may
 * only use the modes its vertical declares (VerticalRegistry->fulfillmentModes())
 * intersected with the modes defined here.
 */
class FulfillmentRegistry
{
    public function __construct(private VerticalRegistry $registry) {}

    /**
     * Every defined mode, keyed by value.
     *
     * @return array<string,array<string,mixed>>
     */
    public function all(): array
    {
        return config('verticals.fulfillment', []);
    }

    /**
     * All valid mode values.
     *
     * @return list<string>
     */
    public function modes(): array
    {
        return array_keys($this->all());
    }

    /** Is $mode a defined fulfillment mode? */
    public function isValid(string $mode): bool
    {
        return array_key_exists($mode, $this->all());
    }

    /**
     * Metadata for a mode (label/needs_address/needs_slot), empty if unknown.
     *
     * @return array<string,mixed>
     */
    public function meta(string $mode): array
    {
        return $this->all()[$mode] ?? [];
    }

    /** Translated label for a mode (falls back to the raw value). */
    public function label(string $mode): string
    {
        return ___((string) ($this->meta($mode)['label'] ?? $mode));
    }

    /** Does this mode collect a shipping/delivery address at checkout? */
    public function needsAddress(string $mode): bool
    {
        return (bool) ($this->meta($mode)['needs_address'] ?? false);
    }

    /** Does this mode require a time slot (pickup/appointment)? */
    public function needsSlot(string $mode): bool
    {
        return (bool) ($this->meta($mode)['needs_slot'] ?? false);
    }

    /**
     * The modes a store type may use: its vertical's declared modes, filtered to
     * those actually defined here, in declaration order.
     *
     * @return list<string>
     */
    public function modesFor(?string $storeType): array
    {
        return array_values(array_filter(
            $this->registry->fulfillmentModes($storeType),
            fn (string $mode) => $this->isValid($mode)
        ));
    }
}

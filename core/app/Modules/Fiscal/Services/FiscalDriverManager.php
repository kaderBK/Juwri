<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services;

use Juwri\Modules\Fiscal\Contracts\FiscalAdapter;

/**
 * TAX-RUN F0-P2 / J25-8: resolves the live {@see FiscalAdapter} for a country (one adapter per country). The Fiscal
 * provider registers the enabled adapters from config('fiscal.adapters'); tests bind Fake clients/adapters via the
 * container. Countries with only a profile (no live API, or a disabled adapter such as AE PINT by default) resolve
 * to null — their documents are never queued for submission.
 *
 * Registered as a singleton so registrations persist. (The name stays FiscalDriverManager for BC.)
 */
class FiscalDriverManager
{
    /** @var array<string,callable():FiscalAdapter> */
    private array $factories = [];

    /** @var array<string,FiscalAdapter> */
    private array $resolved = [];

    public function register(string $country, callable $factory): void
    {
        $this->factories[strtoupper($country)] = $factory;
        unset($this->resolved[strtoupper($country)]);
    }

    public function for(?string $country): ?FiscalAdapter
    {
        $cc = strtoupper((string) $country);
        if ($cc === '' || ! isset($this->factories[$cc])) {
            return null;
        }

        return $this->resolved[$cc] ??= ($this->factories[$cc])();
    }

    public function has(?string $country): bool
    {
        return isset($this->factories[strtoupper((string) $country)]);
    }

    /** @return list<string> the countries with a registered adapter */
    public function countries(): array
    {
        return array_keys($this->factories);
    }
}

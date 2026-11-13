<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Pci;

/**
 * One <script> element of a rendered page (PCI DSS 4.0 req. 6.4.3 inventory, 11.6.1 change detection).
 *
 * `src` is the URL the browser will load: the `src` attribute, or the `data-src` of a consent-gated tag
 * (`type="text/plain" data-cookie-consent=…`, activated by the cookie banner) — those load once the visitor
 * consents, so they are payment-page scripts too.
 */
final class ScriptTag
{
    public function __construct(
        public readonly ?string $src,
        public readonly ?string $integrity,
        public readonly ?string $crossorigin,
        public readonly bool $nonced,
        public readonly string $type,
        public readonly bool $consentGated,
        public readonly string $body,
    ) {}

    public function isExternal(): bool
    {
        return $this->src !== null && $this->src !== '';
    }

    /** Script the browser runs (classic or module); JSON / ld+json / templates are data. Consent-gated = runs later. */
    public function isExecutable(): bool
    {
        if ($this->consentGated) {
            return true;
        }

        return in_array($this->type, ['', 'text/javascript', 'application/javascript', 'module', 'text/ecmascript', 'application/ecmascript'], true);
    }
}

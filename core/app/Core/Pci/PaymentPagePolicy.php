<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Pci;

use Juwri\Core\Support\Csp\Directive;
use Juwri\Core\Support\Csp\Keyword;
use Juwri\Core\Support\Csp\Policy;
use Juwri\Core\Support\Csp\Scheme;

/**
 * PCI DSS 4.0 6.4.3: the strict CSP of one payment page. Scripts run only from this site ('self'), with this
 * response's nonce (inline), or from the exact third-party files/hosts the inventory authorises for THIS page —
 * never 'unsafe-inline', never a scheme-wide `https:`. 'unsafe-eval' only on a page whose inventory entry says why
 * (the dashboard shell runs Alpine, which evaluates its expressions; script sources stay pinned).
 * Violations go to the payment report endpoint (report-uri + Reporting API report-to), which stores them.
 */
class PaymentPagePolicy extends Policy
{
    public const REPORT_GROUP = 'payment-csp';

    public function __construct(private string $page) {}

    public function page(): string
    {
        return $this->page;
    }

    public function configure(): void
    {
        $page = PaymentScriptInventory::page($this->page) ?? [];

        $this
            ->addDirective(Directive::DEFAULT, Keyword::SELF)
            ->addDirective(Directive::BASE, Keyword::SELF)
            ->addDirective(Directive::OBJECT, Keyword::NONE)
            ->addDirective(Directive::FRAME_ANCESTORS, Keyword::SELF)
            ->addDirective(Directive::SCRIPT, Keyword::SELF)
            ->addDirective(Directive::SCRIPT_ATTR, Keyword::NONE)
            ->addDirective(Directive::STYLE, [Keyword::SELF, Scheme::HTTPS])
            // style="" attributes cannot run script; the themes and the kit set colours/positions with them.
            ->addDirective(Directive::STYLE_ATTR, Keyword::UNSAFE_INLINE)
            ->addDirective(Directive::IMG, [Keyword::SELF, Scheme::DATA, Scheme::HTTPS])
            ->addDirective(Directive::FONT, [Keyword::SELF, Scheme::DATA, Scheme::HTTPS])
            ->addDirective(Directive::MEDIA, [Keyword::SELF, Scheme::HTTPS])
            ->addDirective(Directive::CONNECT, Keyword::SELF)
            ->addDirective(Directive::FRAME, Keyword::SELF)
            ->addDirective(Directive::FORM_ACTION, Keyword::SELF)
            ->addNonceForDirective(Directive::SCRIPT)
            ->addNonceForDirective(Directive::STYLE);

        if (trim((string) ($page['unsafe_eval'] ?? '')) !== '') {
            $this->addDirective(Directive::SCRIPT, Keyword::UNSAFE_EVAL);
        }

        foreach ([Directive::SCRIPT, Directive::CONNECT, Directive::FRAME, Directive::FORM_ACTION, Directive::IMG, Directive::STYLE, Directive::FONT] as $directive) {
            $sources = PaymentScriptInventory::cspSources($this->page, $directive);
            if ($sources !== []) {
                $this->addDirective($directive, $sources);
            }
        }

        $this->reportTo(self::reportUrl($this->page));
        $this->addDirective(Directive::REPORT_TO, self::REPORT_GROUP);
    }

    public static function reportUrl(string $page): string
    {
        return route('pci.csp-report', ['page' => $page]);
    }

    /** The `Reporting-Endpoints` header value naming the report-to group. */
    public static function reportingEndpoints(string $page): string
    {
        return self::REPORT_GROUP.'="'.self::reportUrl($page).'"';
    }
}

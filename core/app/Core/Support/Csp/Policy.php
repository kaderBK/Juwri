<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support\Csp;

use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-00a: a Content-Security-Policy built by a subclass's configure().
 *
 * juwri's own replacement for spatie/laravel-csp 2.x (no spatie/* — J18; its 3.x
 * line changed the API and dropped csp_nonce()). Same header output as before:
 * directives joined by ';', keywords and sha*- hashes single-quoted, 'none'
 * replaces everything else, report-uri from config('csp.report_uri'), and an
 * existing header of the same name is never overwritten.
 */
abstract class Policy
{
    /** @var array<string, list<string>> */
    protected array $directives = [];

    protected bool $reportOnly = false;

    abstract public function configure(): void;

    /**
     * @param  string|list<string>  $values
     */
    public function addDirective(string $directive, string|array $values): static
    {
        if (! Directive::isValid($directive)) {
            throw new InvalidArgumentException("Unknown CSP directive [{$directive}].");
        }

        $values = array_values(array_filter(array_merge(...array_map(
            fn (string $value) => explode(' ', $value),
            (array) $values,
        ))));

        if (in_array(Keyword::NONE, $values, true)) {
            if (count($values) > 1) {
                throw new InvalidArgumentException("'none' must be the only value of a CSP directive.");
            }
            $this->directives[$directive] = [$this->quote(Keyword::NONE)];

            return $this;
        }

        $current = array_values(array_filter(
            $this->directives[$directive] ?? [],
            fn (string $value) => $value !== $this->quote(Keyword::NONE),
        ));
        foreach ($values as $value) {
            $value = $this->quote($value);
            if (! in_array($value, $current, true)) {
                $current[] = $value;
            }
        }
        $this->directives[$directive] = $current;

        return $this;
    }

    public function addNonceForDirective(string $directive): static
    {
        if (! config('csp.nonce_enabled', true)) {
            return $this;
        }

        $nonce = app('csp-nonce');

        return $nonce === '' ? $this : $this->addDirective($directive, "'nonce-{$nonce}'");
    }

    public function reportOnly(): static
    {
        $this->reportOnly = true;

        return $this;
    }

    public function enforce(): static
    {
        $this->reportOnly = false;

        return $this;
    }

    public function reportTo(string $uri): static
    {
        $this->directives[Directive::REPORT] = [$uri];

        return $this;
    }

    public function shouldBeApplied(Request $request, Response $response): bool
    {
        return (bool) config('csp.enabled');
    }

    /** Runs configure() and returns the header name to use. */
    public function prepareHeader(): string
    {
        $this->configure();

        return $this->reportOnly ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
    }

    public function applyTo(Response $response): void
    {
        $header = $this->prepareHeader();

        if (! $response->headers->has($header)) {
            $response->headers->set($header, (string) $this);
        }
    }

    public function __toString(): string
    {
        $parts = [];
        foreach ($this->directives as $directive => $values) {
            $parts[] = trim($directive.' '.implode(' ', $values));
        }

        return implode(';', $parts);
    }

    private function quote(string $value): string
    {
        return Keyword::isKeyword($value) || preg_match('/^sha(256|384|512)-/', $value) ? "'{$value}'" : $value;
    }
}

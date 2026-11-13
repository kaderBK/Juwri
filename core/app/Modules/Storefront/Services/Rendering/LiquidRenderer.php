<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Rendering;

/**
 * P2-1: render an untrusted Liquid theme string with data, through the P1
 * RenderClient budget (output + wall-clock) AND the Liquid ResourceLimits
 * (length + score). Data must be Drop presenters / scalars only — never an
 * Eloquent model or env (P2-2 Drop discipline; the security guarantee on
 * shared hosting).
 */
class LiquidRenderer
{
    public function __construct(
        protected LiquidEnvironmentFactory $factory,
        protected RenderClient $client,
    ) {}

    /**
     * @param  array<string,mixed>  $data  Drop presenters / scalars only.
     * @param  'live'|'upload'  $mode
     * @param  array<string,mixed>  $registers  server-set values for filters/tags
     *                                          (e.g. store_id) — NOT reachable as theme
     *                                          variables, so a theme cannot forge them.
     * @param  array<string,string>  $snippets  theme-version snippet map for
     *                                          {% render 'name' %} (key lookup, no FS).
     *
     * @throws RenderFailedException on any breach or error — caller falls back.
     */
    public function render(string $source, array $data = [], string $mode = 'live', array $registers = [], array $snippets = []): string
    {
        $env = $mode === 'upload' ? $this->factory->upload($snippets) : $this->factory->live($snippets);

        return $this->client->render(function () use ($env, $source, $data, $registers) {
            $template = $env->parseString($source);

            // P6-263/P6-266: theme output is allowlist-sanitised AFTER render (Liquid does not escape, so a tag can be assembled
            // at render time); trusted platform tags park their HTML via ThemeOutputSanitizer::hold().
            ThemeOutputSanitizer::begin();
            try {
                $html = $template->render(
                    $env->newRenderContext(
                        data: $data,
                        registers: $registers,
                        resourceLimits: $this->factory->limits(),
                    )
                );
            } catch (\Throwable $e) {
                ThemeOutputSanitizer::abort();
                throw $e;
            }

            return $this->finishOutput($html);
        });
    }

    /** P6-266: allowlist-sanitise the theme output and fill the trusted platform slots of this render. */
    protected function finishOutput(string $html): string
    {
        return ThemeOutputSanitizer::finish($html);
    }
}

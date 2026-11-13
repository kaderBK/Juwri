<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\Studio;

use App\Support\HostCapabilities;

/**
 * Print export for template-mode designs: the SAME standalone HTML the preview
 * iframe shows goes through headless Chrome (HeadlessChrome: node + puppeteer;
 * J18 replaced spatie/browsershot) — what you previewed is what prints.
 * Swapped out in tests; when the toolchain is missing the endpoint answers 501
 * and the client falls back to window.print() on the preview.
 */
class StudioTemplateExportService
{
    /** Node + puppeteer + Chrome resolvable (and process functions enabled)? Cheap static check, not a launch. */
    public function available(): bool
    {
        return app(HostCapabilities::class)->headlessBrowserAvailable();
    }

    /** @return string PDF bytes */
    public function toPdf(string $html, string $aspect): string
    {
        return $this->chrome()->pdf($html, $aspect === 'letter' ? 'Letter' : 'A4');
    }

    /**
     * Social exports (square/story): element screenshot of the sheet at 2x for
     * crisp text. Window sized to the sheet's CSS mm footprint at 96dpi.
     *
     * @return string PNG bytes
     */
    public function toPng(string $html, string $aspect): string
    {
        [$w, $h] = $aspect === 'story' ? [681, 1210] : [794, 794]; // 180x320mm / 210x210mm

        return $this->chrome()->screenshot($html, $w, $h, 2.0, '.sheet');
    }

    protected function chrome(): HeadlessChrome
    {
        return app(HeadlessChrome::class);
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\Studio;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Juwri\Modules\Content\Models\StudioSession;

/**
 * HTML/CSS menu templates bound to the session's draft.json (spec §0: text stays
 * data, never pixels). Templates are packages auto-discovered from the templates
 * dir: {slug}/template.blade.php + style.css + manifest.json (+ thumb.png).
 *
 * The slug whitelist comes from discovery — request slugs are never used to build
 * paths before being checked against it.
 */
class StudioTemplateService
{
    /** @var array<string, array>|null per-request discovery cache */
    protected ?array $templates = null;

    /** Manifest keys a template package must declare to be listed. */
    protected const REQUIRED = ['name', 'aspects', 'colors', 'density'];

    public function basePath(): string
    {
        return config('studio.templates_path') ?: resource_path('views/studio/templates');
    }

    /**
     * All valid template packages, keyed by slug. Invalid packages are skipped
     * (warning logged), never fatal — one broken manifest must not kill the gallery.
     *
     * @return array<string, array> manifest + ['slug' => .., 'path' => ..]
     */
    public function all(): array
    {
        if ($this->templates !== null) {
            return $this->templates;
        }

        $found = [];
        $base = $this->basePath();
        foreach (is_dir($base) ? scandir($base) : [] as $entry) {
            if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $entry) || ! is_dir("$base/$entry")) {
                continue;
            }
            $manifest = $this->readManifest("$base/$entry");
            if ($manifest === null) {
                Log::warning("studio template '$entry' skipped: invalid or missing manifest/blade");

                continue;
            }
            $found[$entry] = $manifest + ['slug' => $entry, 'path' => "$base/$entry"];
        }

        return $this->templates = $found;
    }

    public function exists(string $slug): bool
    {
        return array_key_exists($slug, $this->all());
    }

    /** @throws \InvalidArgumentException on a slug not in the whitelist */
    public function manifest(string $slug): array
    {
        $all = $this->all();
        if (! isset($all[$slug])) {
            throw new \InvalidArgumentException("Unknown studio template: $slug");
        }

        return $all[$slug];
    }

    /**
     * Render a template to a full standalone HTML page for the session's draft.
     * Free (no credits): preview and export share this exact output.
     *
     * $options: aspect, colors (hex overrides), slots (store_name, tagline,
     * footer_note), currency — everything user-tunable, everything validated here.
     */
    public function render(StudioSession $session, string $slug, array $options = []): string
    {
        $draftPath = $session->storagePath('draft.json');
        if (! Storage::disk('local')->exists($draftPath)) {
            throw new \RuntimeException('No extracted menu to design yet.');
        }
        $draft = json_decode(Storage::disk('local')->get($draftPath), true) ?: [];

        return $this->renderWithDraft($draft, $slug, $options, $this->backgroundDataUri($session));
    }

    /** Session-free render (thumbnail command, previews of sample data). */
    public function renderWithDraft(array $draft, string $slug, array $options = [], ?string $background = null): string
    {
        $manifest = $this->manifest($slug);

        $aspect = in_array($options['aspect'] ?? null, $manifest['aspects'], true)
            ? $options['aspect'] : $manifest['aspects'][0];

        // Colors: manifest defaults, overridden only by well-formed hex values.
        $colors = $manifest['colors'];
        foreach (($options['colors'] ?? []) as $key => $hex) {
            if (isset($colors[$key]) && is_string($hex) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $hex)) {
                $colors[$key] = $hex;
            }
        }

        // Slot text is user data — templates must output it with {{ }} only.
        $slots = [];
        foreach (($manifest['slots'] ?? []) as $slot) {
            $slots[$slot] = mb_substr(trim((string) (($options['slots'] ?? [])[$slot] ?? '')), 0, 200);
        }

        return View::file($manifest['path'].'/template.blade.php', [
            'draft' => $draft,
            'manifest' => $manifest,
            'aspect' => $aspect,
            'colors' => $colors,
            'slots' => $slots,
            'currency' => (string) ($options['currency'] ?? config('studio.currency', 'SAR')),
            'rtl' => (bool) ($options['rtl'] ?? false),
            'css' => is_file($manifest['path'].'/style.css') ? file_get_contents($manifest['path'].'/style.css') : '',
            'fit' => $options['fit'] ?? null,
            // P4: AI background art (session file, server-built data URI only).
            'background' => $background,
        ])->render();
    }

    /** P4: session background art as a data URI (keeps pages standalone). */
    protected function backgroundDataUri(StudioSession $session): ?string
    {
        $path = $session->storagePath('template-bg.png');
        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode(Storage::disk('local')->get($path));
    }

    /** Parse + validate one package dir; null = skip it. */
    protected function readManifest(string $dir): ?array
    {
        if (! is_file("$dir/manifest.json") || ! is_file("$dir/template.blade.php")) {
            return null;
        }
        $manifest = json_decode((string) file_get_contents("$dir/manifest.json"), true);
        if (! is_array($manifest)) {
            return null;
        }
        foreach (self::REQUIRED as $key) {
            if (empty($manifest[$key])) {
                return null;
            }
        }
        // Aspects must be a non-empty subset of the studio-wide list.
        $known = config('studio.aspects', []);
        $manifest['aspects'] = array_values(array_intersect((array) $manifest['aspects'], $known));
        if ($manifest['aspects'] === [] || ! is_array($manifest['colors'])) {
            return null;
        }

        return $manifest;
    }
}

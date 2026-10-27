<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Browser\Support;

use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * axe-core helper for the Pest browser tests.
 *
 * pest-plugin-browser (v5.1.2, see composer.lock) has a built-in `assertNoAccessibilityIssues()`, but it only
 * filters by impact level (critical/serious/moderate/minor) and runs axe with its default rule set; it cannot be
 * scoped to the WCAG 2.1 tags. The plugin already bundles axe-core and injects it into every page as `window.axe`
 * through its init script (Pest\Browser\Playwright\InitScript), so no npm dependency is needed. This helper runs
 * axe with an explicit WCAG 2.1 level A + AA rule filter and reports failures as
 * "rule | impact | selector | html snippet" lines.
 */
final class Axe
{
    /** axe rule tags covering WCAG 2.1 level A and AA. */
    public const WCAG21AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

    /**
     * Runs axe-core on the page and returns the violations it found.
     *
     * @param  object  $page  the page returned by visit()
     * @param  array<int, string>  $tags  axe rule tags to run (defaults to WCAG 2.1 A + AA)
     * @return array<int, array{
     *     id: string,
     *     impact: string|null,
     *     help: string|null,
     *     helpUrl: string|null,
     *     nodes: array<int, array{
     *         target: array<int, string>,
     *         html: string|null,
     *         failureSummary: string|null,
     *     }>,
     * }>
     */
    public static function violations(object $page, array $tags = self::WCAG21AA): array
    {
        self::assertAxeIsAvailable($page);

        $options = json_encode([
            'runOnly' => ['type' => 'tag', 'values' => array_values($tags)],
            'resultTypes' => ['violations'],
        ], JSON_THROW_ON_ERROR);

        $violations = $page->script('async () => (await window.axe.run(document, '.$options.')).violations');

        return is_array($violations) ? $violations : [];
    }

    /**
     * Ensures pest-plugin-browser has injected axe-core into the page.
     */
    private static function assertAxeIsAvailable(object $page): void
    {
        if ($page->script('() => typeof window.axe !== "undefined"') === true) {
            return;
        }

        throw new RuntimeException(
            'axe-core is not available on the page; pest-plugin-browser should inject window.axe through its init script.',
        );
    }

    /**
     * Asserts the page has no axe-core violations for the given tags, failing with a readable report.
     *
     * @param  array<int, string>  $tags
     */
    public static function assertClean(object $page, string $label, array $tags = self::WCAG21AA): void
    {
        $violations = self::violations($page, $tags);

        if ($violations === []) {
            Assert::assertSame([], $violations);

            return;
        }

        Assert::fail(self::report($label, $violations));
    }

    /**
     * @param  array<int, array{
     *     id?: string,
     *     impact?: string|null,
     *     nodes?: array<int, array{target?: array<int, string>|string|null, html?: string|null, any?: array<int, array<string, mixed>>}>,
     * }>  $violations
     */
    private static function report(string $label, array $violations): string
    {
        $lines = [sprintf('axe-core accessibility violations on %s:', $label)];

        foreach ($violations as $violation) {
            $id = $violation['id'] ?? 'unknown';
            $impact = $violation['impact'] ?? 'unknown';
            $nodes = $violation['nodes'] ?? [];

            if ($nodes === []) {
                $lines[] = sprintf('%s | %s | (no nodes) | ', $id, $impact);

                continue;
            }

            foreach ($nodes as $node) {
                $target = $node['target'] ?? '';
                if (is_array($target)) {
                    $target = implode(' ', array_filter($target, static fn (string $selector): bool => $selector !== ''));
                }

                $contrast = $node['any'][0]['data'] ?? null;
                $colours = is_array($contrast) && isset($contrast['contrastRatio'])
                    ? sprintf(' [%s on %s, ratio %s, needs %s]', $contrast['fgColor'] ?? '?', $contrast['bgColor'] ?? '?', $contrast['contrastRatio'], $contrast['expectedContrastRatio'] ?? '?')
                    : '';

                $lines[] = sprintf(
                    '%s | %s | %s | %s%s',
                    $id,
                    $impact,
                    $target !== '' ? $target : '(no selector)',
                    self::snippet($node['html'] ?? null),
                    $colours,
                );
            }
        }

        return implode(PHP_EOL, $lines);
    }

    private static function snippet(?string $html): string
    {
        $html = trim((string) $html);
        $html = preg_replace('/\s+/', ' ', $html) ?? $html;

        return strlen($html) > 200 ? substr($html, 0, 197).'...' : $html;
    }
}

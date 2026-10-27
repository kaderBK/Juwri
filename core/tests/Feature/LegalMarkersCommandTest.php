<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use App\Support\LegalMarkerScanner;
use Illuminate\Support\Facades\Artisan;

/**
 * P1-23: php artisan legal:markers walks resources/legal/{en,ar}/*.md, finds every {{FILL:key}} / {{VERIFY:key}}
 * marker (key optional: {{FILL}}), groups by document, and exits 1 while any marker remains. Editors run it
 * before they ship config/legal.php so they know which keys the owner still owes; CI uses it as the second half
 * of the release gate.
 */
beforeEach(function () {
    $this->fixture = sys_get_temp_dir().'/legal-markers-'.bin2hex(random_bytes(6));
    mkdir($this->fixture.'/en', 0755, true);
    mkdir($this->fixture.'/ar', 0755, true);
    file_put_contents($this->fixture.'/en/sla.md',
        "# juwri SLA\n"
        ."Version 1.0 · Effective 4 October 2026\n"
        ."\n"
        ."We back up daily and {{VERIFY:backups}}, then test.\n"
        ."- **Higher plans**: {{FILL:higher_plans}}. {{FILL:contact}} for details.\n"
        ."A keyless {{FILL}} marker here.\n"
    );
    file_put_contents($this->fixture.'/ar/sla.md',
        "# اتفاقية مستوى الخدمة\n"
        ."الإصدار 1.0 · ساري من 4 أكتوبر 2026\n"
        ."\n"
        ."نحتفظ بنسخ احتياطية {{VERIFY:backups}} ونختبر الاستعادة.\n"
        ."- **الخطط الأعلى**: {{FILL:higher_plans}}.\n"
    );
});

afterEach(function () {
    if (! isset($this->fixture) || ! is_dir($this->fixture)) {
        return;
    }
    foreach ((array) glob($this->fixture.'/*/*.md') as $f) {
        @unlink($f);
    }
    foreach ((array) glob($this->fixture.'/*', GLOB_ONLYDIR) as $d) {
        @rmdir($d);
    }
    @rmdir($this->fixture);
});

function legalCleanFixture(): string
{
    $dir = sys_get_temp_dir().'/legal-markers-clean-'.bin2hex(random_bytes(6));
    mkdir($dir.'/en', 0755, true);
    file_put_contents($dir.'/en/clean.md', "# Clean\n\nNothing to see here.\n");

    return $dir;
}

function legalRemoveFixture(string $dir): void
{
    foreach ((array) glob($dir.'/*/*.md') as $f) {
        @unlink($f);
    }
    foreach ((array) glob($dir.'/*', GLOB_ONLYDIR) as $d) {
        @rmdir($d);
    }
    @rmdir($dir);
}

it('lists every marker grouped by document, with locale, file, line, type, key and sentence (text mode, exit 1)', function () {
    $code = Artisan::call('legal:markers', ['--path' => $this->fixture]);
    $out = Artisan::output();

    expect($code)->toBe(1);

    // Grouping: one heading per document, document name in its own line.
    expect($out)->toContain('sla');

    // Locale, file:line, type, key. Line numbers reflect the source 1-based.
    expect($out)->toContain('[en] en/sla.md:4  VERIFY backups');
    expect($out)->toContain('[en] en/sla.md:5  FILL higher_plans');
    expect($out)->toContain('[en] en/sla.md:5  FILL contact');
    expect($out)->toContain('[en] en/sla.md:6  FILL ');

    // Arabic is sorted before English (ar < en).
    expect($out)->toContain('[ar] ar/sla.md:4  VERIFY backups');
    expect($out)->toContain('[ar] ar/sla.md:5  FILL higher_plans');

    // Sentence containing the marker, with the punctuation stripped.
    expect($out)->toMatch('/VERIFY backups\s+—\s+We back up daily and \{\{VERIFY:backups\}\}, then test/');
    expect($out)->toMatch('/FILL higher_plans\s+—\s+- \*\*Higher plans\*\*: \{\{FILL:higher_plans\}\}/');

    // 2 (ar) + 4 (en) = 6 entries.
    expect($out)->toContain('Total: 6');
});

it('--json prints {"total":N,"markers":[...]} with the documented shape and exits 1 while markers remain', function () {
    $code = Artisan::call('legal:markers', ['--path' => $this->fixture, '--json' => true]);
    $raw = Artisan::output();

    expect($code)->toBe(1);
    expect(trim($raw))->toStartWith('{');
    $out = json_decode($raw, true);
    expect($out)->toBeArray();
    expect($out)->toHaveKeys(['total', 'markers']);
    expect($out)->toHaveCount(2); // exactly those two top-level keys
    expect($out['total'])->toBe(6);
    expect($out['markers'])->toHaveCount(6);

    // Every entry has the documented shape.
    foreach ($out['markers'] as $entry) {
        expect($entry)->toHaveKeys(['document', 'locale', 'file', 'line', 'type', 'key', 'sentence']);
        expect($entry['document'])->toBeString();
        expect($entry['locale'])->toBeIn(['en', 'ar']);
        expect($entry['file'])->toMatch('#^[a-z]{2}/[a-z_]+\.md$#');
        expect($entry['line'])->toBeInt();
        expect($entry['type'])->toBeIn(['FILL', 'VERIFY']);
        expect($entry['key'])->toBeString();
        expect($entry['sentence'])->toBeString();
        $expectedMarker = $entry['key'] === ''
            ? '{{'.$entry['type'].'}}'
            : '{{'.$entry['type'].':'.$entry['key'].'}}';
        expect($entry['sentence'])->toContain($expectedMarker);
    }

    // Stable order: ar before en, then file, then line.
    expect($out['markers'][0]['locale'])->toBe('ar');
    expect($out['markers'][0]['file'])->toBe('ar/sla.md');
    expect($out['markers'][0]['line'])->toBe(4);
    expect($out['markers'][0]['type'])->toBe('VERIFY');
    expect($out['markers'][0]['key'])->toBe('backups');
});

it('exits 0 and reports an empty tree when the source has no markers', function () {
    $clean = legalCleanFixture();
    try {
        $code = Artisan::call('legal:markers', ['--path' => $clean, '--json' => true]);
        expect($code)->toBe(0);
        $out = json_decode(Artisan::output(), true);
        expect($out)->toBe(['total' => 0, 'markers' => []]);

        // Text mode also exits 0 and reports a clean tree.
        expect(Artisan::call('legal:markers', ['--path' => $clean]))->toBe(0);
        expect(Artisan::output())->toContain('No {{FILL}} / {{VERIFY}} markers');
    } finally {
        legalRemoveFixture($clean);
    }
});

it('exits 0 when --path points at a missing directory (no source to scan)', function () {
    $missing = sys_get_temp_dir().'/legal-markers-missing-'.bin2hex(random_bytes(6));
    expect(Artisan::call('legal:markers', ['--path' => $missing, '--json' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), true))->toBe(['total' => 0, 'markers' => []]);
});

it('scanner returns one entry per marker occurrence (multiple on one line, keyless supported)', function () {
    $markers = (new LegalMarkerScanner)->scan($this->fixture);

    // 2 ar + 4 en = 6 entries.
    expect($markers)->toHaveCount(6);

    // Line 5 of en/sla.md has two FILL markers — they each get their own entry.
    $lineFive = array_values(array_filter(
        $markers,
        fn (array $m): bool => $m['locale'] === 'en' && $m['line'] === 5,
    ));
    expect($lineFive)->toHaveCount(2);
    $keys = array_map(fn (array $m): string => $m['key'], $lineFive);
    expect($keys)->toContain('higher_plans');
    expect($keys)->toContain('contact');

    // Keyless marker: type is FILL, key is the empty string, sentence still carries the marker text.
    $keyless = array_values(array_filter(
        $markers,
        fn (array $m): bool => $m['type'] === 'FILL' && $m['key'] === '',
    ));
    expect($keyless)->toHaveCount(1);
    expect($keyless[0]['sentence'])->toContain('{{FILL}}');
});

it('scanner sorts locales and files so the order is stable across runs', function () {
    $markers = (new LegalMarkerScanner)->scan($this->fixture);

    $locales = array_column($markers, 'locale');
    $sorted = $locales;
    sort($sorted);
    expect($locales)->toBe($sorted);

    $files = array_column($markers, 'file');
    $sortedFiles = $files;
    sort($sortedFiles);
    expect($files)->toBe($sortedFiles);
});

it('scanner splits the sentence on . ! ? ؟ ؛ boundaries and finds the segment containing the marker', function () {
    $markers = (new LegalMarkerScanner)->scan($this->fixture);

    // Line 4 (en): "We back up daily and {{VERIFY:backups}}, then test." → sentence is the line minus the trailing period.
    $verify = array_values(array_filter(
        $markers,
        fn (array $m): bool => $m['locale'] === 'en' && $m['line'] === 4 && $m['type'] === 'VERIFY',
    ))[0];
    expect($verify['sentence'])->toBe('We back up daily and {{VERIFY:backups}}, then test');

    // Line 5 (en): "- **Higher plans**: {{FILL:higher_plans}}. {{FILL:contact}} for details."
    // — the higher_plans sentence keeps the leading bullet dash and emphasis.
    $higher = array_values(array_filter(
        $markers,
        fn (array $m): bool => $m['locale'] === 'en' && $m['line'] === 5 && $m['key'] === 'higher_plans',
    ))[0];
    expect($higher['sentence'])->toBe('- **Higher plans**: {{FILL:higher_plans}}');

    // Line 4 (ar): marker sits after a period, so its sentence is just the segment after the period.
    $ar = array_values(array_filter(
        $markers,
        fn (array $m): bool => $m['locale'] === 'ar' && $m['line'] === 4,
    ))[0];
    expect($ar['sentence'])->toContain('{{VERIFY:backups}}');
});

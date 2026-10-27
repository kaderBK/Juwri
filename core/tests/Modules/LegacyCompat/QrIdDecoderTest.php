<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat;

use Juwri\Modules\LegacyCompat\Support\QrIdDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * J52b: qr-id XOR contract vectors (LEGACYCOMPAT-SPEC §7, plan §5.2). The vectors in
 * tests/Fixtures/LegacyCompat/qr-id-vectors.json come from an independent generator written from the documented
 * algorithm, with synthetic passphrases: lengths 1, 100, 101 (the chunk
 * size switch) and 500, plus mixed-case, numeric and unicode slugs, for two passphrase variants.
 */
class QrIdDecoderTest extends TestCase
{
    /** @return array{passphrases: array<string, string>, vectors: list<array{passphrase: string, label: string, plaintext_b64: string, qr_id: string}>} */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/LegacyCompat/qr-id-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{string, string, string}> */
    public static function vectors(): array
    {
        $fixture = self::fixture();
        $cases = [];
        foreach ($fixture['vectors'] as $v) {
            $cases[$v['passphrase'].'/'.$v['label']] = [
                $fixture['passphrases'][$v['passphrase']],
                (string) base64_decode($v['plaintext_b64'], true),
                $v['qr_id'],
            ];
        }

        return $cases;
    }

    #[DataProvider('vectors')]
    public function test_encode_and_decode_match_the_frozen_vector(string $passphrase, string $plain, string $qrId): void
    {
        $this->assertSame($qrId, QrIdDecoder::encode($plain, $passphrase));
        $this->assertSame($plain, QrIdDecoder::decodeWith($qrId, $passphrase));
        $this->assertTrue((new QrIdDecoder([$passphrase]))->matches($qrId, $plain));
    }

    public function test_the_vector_set_covers_the_documented_lengths_and_both_variants(): void
    {
        $fixture = self::fixture();
        $this->assertCount(2, $fixture['passphrases']);
        foreach (array_keys($fixture['passphrases']) as $variant) {
            foreach (['len_1', 'len_100', 'len_101', 'len_500'] as $label) {
                $this->assertNotEmpty(array_filter($fixture['vectors'], fn (array $v): bool => $v['passphrase'] === $variant && $v['label'] === $label), "$variant/$label");
            }
        }
    }

    public function test_chunk_size_switches_after_100_bytes(): void
    {
        $p = 'synthetic-passphrase-a';
        $short = QrIdDecoder::keystream($p, 100);
        $long = QrIdDecoder::keystream($p, 101);

        $this->assertSame(100, strlen($short));
        $this->assertSame(101, strlen($long));
        $this->assertSame(substr(sha1($p, true), 0, 2), substr($short, 0, 2));
        $this->assertSame(substr(sha1($p, true), 0, 8), substr($long, 0, 8));
        $this->assertSame(substr(sha1($p.substr($long, 0, 8), true), 0, 8), substr($long, 8, 8));
    }

    public function test_either_configured_passphrase_matches(): void
    {
        $decoder = new QrIdDecoder(['first-pass', 'second-pass']);

        $this->assertTrue($decoder->matches(QrIdDecoder::encode('demo-store', 'first-pass'), 'demo-store'));
        $this->assertTrue($decoder->matches(QrIdDecoder::encode('demo-store', 'second-pass'), 'demo-store'));
        $this->assertFalse($decoder->matches(QrIdDecoder::encode('demo-store', 'unknown-pass'), 'demo-store'));
    }

    public function test_passphrases_come_from_config_and_none_means_no_match(): void
    {
        config(['legacy_compat.qr_passphrases' => []]);
        $this->assertSame([], (new QrIdDecoder)->passphrases());
        $this->assertFalse((new QrIdDecoder)->matches(QrIdDecoder::encode('demo-store', 'cfg-pass'), 'demo-store'));

        config(['legacy_compat.qr_passphrases' => ['cfg-pass', '', 7]]);
        $this->assertSame(['cfg-pass'], (new QrIdDecoder)->passphrases());
        $this->assertTrue((new QrIdDecoder)->matches(QrIdDecoder::encode('demo-store', 'cfg-pass'), 'demo-store'));
    }

    public function test_the_match_is_exact_and_case_sensitive(): void
    {
        $decoder = new QrIdDecoder(['p']);
        $qr = QrIdDecoder::encode('Demo-Store', 'p');

        $this->assertTrue($decoder->matches($qr, 'Demo-Store'));
        $this->assertFalse($decoder->matches($qr, 'demo-store'));
        $this->assertFalse($decoder->matches($qr, 'Demo-Store2'));
        $this->assertFalse($decoder->matches(QrIdDecoder::encode('other-store', 'p'), 'Demo-Store'));
    }

    public function test_bad_input_is_no_match_and_never_throws(): void
    {
        $decoder = new QrIdDecoder(['p']);

        foreach ([null, '', '!!!not-base64!!!', '====', ['x'], 12, str_repeat('A', QrIdDecoder::MAX_QR_ID_LENGTH + 4)] as $bad) {
            $this->assertFalse($decoder->matches($bad, 'demo'), var_export($bad, true));
        }
        $this->assertNull(QrIdDecoder::decodeWith('%%%', 'p'));
    }

    public function test_an_unencoded_plus_that_arrives_as_a_space_still_decodes(): void
    {
        // Find a slug whose code contains "+" so the test proves the space→plus repair.
        for ($i = 0; $i < 2000; $i++) {
            $slug = 's-'.md5((string) $i);
            $qr = QrIdDecoder::encode($slug, 'p');
            if (str_contains($qr, '+')) {
                $this->assertTrue((new QrIdDecoder(['p']))->matches(str_replace('+', ' ', $qr), $slug));

                return;
            }
        }
        $this->fail('no sample code with a plus sign');
    }

    /** Juwri's own printed codes use the same construction, so a migrated install can list its passphrase too. */
    public function test_juwris_own_printed_code_decodes_with_its_passphrase(): void
    {
        parse_str((string) parse_url(store_public_qr_url('demo-store'), PHP_URL_QUERY), $query);

        $this->assertSame('demo-store', QrIdDecoder::decodeWith((string) $query['qr-id'], 'qr-scan'));
    }
}

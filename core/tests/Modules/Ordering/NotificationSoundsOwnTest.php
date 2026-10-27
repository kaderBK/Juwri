<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use Tests\TestCase;

/**
 * WI-05f: every bundled notification sound is juwri's own generated audio.
 *
 * The file names of the former third-party pack are kept (stored settings and the
 * defaults reference them), so this pins that the defaults still resolve to real
 * files, that no file is one of the replaced third-party files (by checksum), and
 * that THIRD-PARTY.md lists no third-party audio pack.
 */
class NotificationSoundsOwnTest extends TestCase
{
    /** md5 of the 21 files replaced in WI-05f. */
    private const REPLACED_MD5 = [
        '05ddf5c00268c6377d8e36c5ea19bb2e', '1a302a155e3d6b3c6b83c5b75965a1aa', '2ad29bb02b48f0df2a38a359fef9f8ee',
        '3940377c9b46a3789f22fc6942151962', '433c00d489b45933ec88c3d44f07c4e3', '490a34a5b447ce5fc18c2a93838c2a75',
        '4f2e9acddfbcfa6caf5931e5d8fd86fe', '56f80fcca95e64ebf665e7a9f1e49570', '5978a2aec3754da0e907d4756d181577',
        '5b74dcf24f7c84276644f403389d240c', '62a6949e08c2ebbee8c421cf4c718a2a', '950564deba6e687d4ded7855322dfcfe',
        '9cdd7d58599ae3f7a746848b80ce1310', 'a5c658f3c4f65e58be6ccfa49f9a2cc9', 'a725a2a82e051daea36f156b1a0d3f57',
        'cf5564829a83409aded36c3e82c67a77', 'd6484fe437610eba5e2c1eb3eb21d492', 'd9bea7046cf055db681d2ad3e479c05e',
        'e62442809d7678c891394b8d0cae2923', 'f22d17840b22f9392802d4dbd4de3c5f', 'fa81158f918629f36ca70c203171329e',
    ];

    public function test_default_event_sounds_exist_and_are_distinct(): void
    {
        $events = notification_sounds_defaults()['events'];
        $files = [];
        foreach ($events as $event => $cfg) {
            if ($cfg['sound'] === '') {
                continue;
            }
            $path = public_path('assets/global/audio/'.$cfg['sound']);
            $this->assertFileExists($path, "default sound for {$event}");
            $files[$cfg['sound']] = md5_file($path);
        }
        // order_ready and dispute share one file; the other defaults are all different sounds.
        $this->assertCount(4, $files);
        $this->assertCount(4, array_unique($files));
    }

    public function test_no_replaced_third_party_audio_is_shipped(): void
    {
        $audio = glob(public_path('assets/global/audio/*.{mp3,ogg,wav}'), GLOB_BRACE) ?: [];
        $this->assertCount(24, $audio);
        foreach ($audio as $file) {
            $this->assertNotContains(md5_file($file), self::REPLACED_MD5, basename($file).' is a replaced third-party file');
        }
        $this->assertFileExists(public_path('assets/global/audio/generate-sounds.py'));
        $this->assertStringNotContainsStringIgnoringCase('pixabay', (string) file_get_contents(base_path('../THIRD-PARTY.md')));
    }
}

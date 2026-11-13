<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Juwri\Core\Services\Tenancy\StoreUploadFiles;
use Tests\TestCase;

/** P6-320: uploads outside the media library are found (export) and deleted (erasure) per store. */
class StoreUploadFilesTest extends TestCase
{
    private function upload(string $relative): string
    {
        $abs = public_path('storage/'.$relative);
        File::ensureDirectoryExists(dirname($abs));
        file_put_contents($abs, 'x');

        return $abs;
    }

    public function test_lists_and_deletes_only_the_given_stores_uploads(): void
    {
        $id = 987001;
        $other = 987002;
        $mine = [
            $this->upload("notif-sounds/{$id}/a-abc123.mp3"),
            $this->upload("fonts/font_{$id}_deadbeef.woff2"),
            $this->upload("folio-pdf/{$id}-abcdef.pdf"),
            $this->upload("folio-pdf-pages/{$id}/p1.jpg"),
            $this->upload("pageview/{$id}-menu.pdf"),
        ];
        $theirs = [
            $this->upload("notif-sounds/{$other}/a.mp3"),
            $this->upload("fonts/font_{$other}_x.woff2"),
            $this->upload("folio-pdf/{$other}-abcdef.pdf"),
            $this->upload('fonts/font_9870019_x.woff2'), // 987001 must not match as a prefix of 9870019
        ];

        try {
            $listed = array_values(StoreUploadFiles::files($id));
            sort($listed);
            sort($mine);
            $this->assertSame($mine, $listed);
            $this->assertSame(5, StoreUploadFiles::delete($id));
            foreach ($mine as $f) {
                $this->assertFileDoesNotExist($f);
            }
            $this->assertDirectoryDoesNotExist(public_path("storage/notif-sounds/{$id}"));
            foreach ($theirs as $f) {
                $this->assertFileExists($f);
            }
        } finally {
            StoreUploadFiles::delete($id);
            StoreUploadFiles::delete($other);
            @unlink(public_path('storage/fonts/font_9870019_x.woff2'));
        }
    }

    public function test_a_non_positive_store_id_does_nothing(): void
    {
        $this->assertSame([], StoreUploadFiles::files(0));
        $this->assertSame(0, StoreUploadFiles::delete(-1));
    }
}

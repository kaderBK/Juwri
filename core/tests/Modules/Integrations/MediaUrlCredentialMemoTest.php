<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Modules\Media\Services\StorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WI-H11 — media_url() (StorageService::url) memoises the read-only storage
 * credential lookup per request, so a storefront render that calls it 100+ times
 * issues ONE query, not 100. The memo is invalidated by any Credential model
 * write, and never leaks into the upload/delete write paths.
 */
class MediaUrlCredentialMemoTest extends TestCase
{
    use RefreshDatabase;

    private int $rid;

    protected function setUp(): void
    {
        parent::setUp();
        // credentials.store_id is FK-constrained to posts.id (FKs are on under sqlite here).
        $this->rid = (int) Store::factory()->create()->id;
        StorageService::flushUrlCredentialMemo();
    }

    private function credentialQueryCount(): int
    {
        return count(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'credentials')));
    }

    private function makeStorageCredential(?int $rid): Credential
    {
        return Credential::create([
            'store_id' => $rid,
            'feature' => 'storage',
            'provider' => 's3',
            'secrets' => [
                'key' => 'AKIA_FAKE', 'secret' => 'fake_secret', 'region' => 'us-east-1',
                'bucket' => 'test-bucket', 'cdn_url' => 'https://cdn-h11.test',
            ],
            'active' => true,
        ]);
    }

    public function test_repeated_media_url_calls_query_credentials_once(): void
    {
        $this->makeStorageCredential($this->rid);
        StorageService::flushUrlCredentialMemo();

        DB::enableQueryLog();
        $urls = [];
        for ($i = 0; $i < 13; $i++) {
            $urls[] = media_url('storage/menu/', "i{$i}.png", $this->rid);
        }

        $this->assertSame(1, $this->credentialQueryCount(), 'a merchant with credentials must be read once, not 13x');
        foreach ($urls as $u) {
            $this->assertStringStartsWith('https://cdn-h11.test', $u);
        }
    }

    public function test_a_store_with_no_storage_credentials_queries_credentials_twice_total(): void
    {
        // No Credential rows at all: merchant miss + platform miss = 2 queries, memoised as null.
        DB::enableQueryLog();
        $urls = [];
        for ($i = 0; $i < 13; $i++) {
            $urls[] = media_url('storage/menu/', "i{$i}.png", $this->rid);
        }

        $this->assertSame(2, $this->credentialQueryCount(), 'a no-BYOK store must cost 2 (miss+miss), memoised — the array_key_exists fence');
        foreach ($urls as $u) {
            $this->assertStringNotContainsString('cdn-h11.test', $u);
        }
    }

    public function test_the_platform_scope_is_memoised_separately_from_a_store(): void
    {
        $this->makeStorageCredential(null); // platform default only
        StorageService::flushUrlCredentialMemo();

        DB::enableQueryLog();
        for ($i = 0; $i < 5; $i++) {
            media_url('storage/menu/', "p{$i}.png", null);
        }
        for ($i = 0; $i < 5; $i++) {
            media_url('storage/menu/', "s{$i}.png", $this->rid);
        }

        // platform: 1 query; store: merchant-miss + platform-hit... platform row exists so
        // store scope = 1 (merchant) + 1 (platform) = 2. Total 3.
        $this->assertSame(3, $this->credentialQueryCount());

        $platformUrl = media_url('storage/menu/', 'p.png', null);
        $storeUrl = media_url('storage/menu/', 's.png', $this->rid);
        $this->assertStringStartsWith('https://cdn-h11.test', $platformUrl);
        $this->assertStringStartsWith('https://cdn-h11.test', $storeUrl);
    }

    public function test_credentials_saved_in_the_same_request_are_used_by_the_next_media_url(): void
    {
        $first = media_url('storage/menu/', 'x.png', $this->rid);
        $this->assertStringNotContainsString('cdn-h11.test', $first);

        $this->makeStorageCredential($this->rid); // fires saved() -> flush

        $second = media_url('storage/menu/', 'x.png', $this->rid);
        $this->assertStringStartsWith('https://cdn-h11.test', $second);
    }

    public function test_deleting_credentials_in_the_same_request_falls_back_immediately(): void
    {
        $cred = $this->makeStorageCredential($this->rid);
        $primed = media_url('storage/menu/', 'x.png', $this->rid);
        $this->assertStringStartsWith('https://cdn-h11.test', $primed);

        $cred->delete(); // fires deleted() -> flush

        $after = media_url('storage/menu/', 'x.png', $this->rid);
        $this->assertStringNotContainsString('cdn-h11.test', $after);
    }

    public function test_the_write_paths_are_not_memoised(): void
    {
        media_url('storage/menu/', 'prime.png', $this->rid); // prime the memo (null answer)

        DB::enableQueryLog();
        StorageService::delete('storage/menu/', 'nope.png', $this->rid);

        $this->assertGreaterThanOrEqual(1, $this->credentialQueryCount(), 'the delete write path must re-read live credentials');
    }

    public function test_a_mass_delete_of_storage_credentials_is_still_covered(): void
    {
        $this->makeStorageCredential($this->rid);
        StorageService::flushUrlCredentialMemo();
        $this->assertStringStartsWith('https://cdn-h11.test', media_url('storage/menu/', 'x.png', $this->rid));

        // Query-builder mass delete fires NO model events -> the contract requires
        // the call site to flush explicitly. Assert the fallback after the flush.
        Credential::where('store_id', $this->rid)->where('feature', 'storage')->delete();
        StorageService::flushUrlCredentialMemo();

        $this->assertStringNotContainsString('cdn-h11.test', media_url('storage/menu/', 'x.png', $this->rid));
    }
}

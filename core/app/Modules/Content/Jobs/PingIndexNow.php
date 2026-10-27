<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * rev/seo R20: submit changed URLs to IndexNow (Bing + Yandex) so they're
 * crawled within minutes instead of on the next scheduled crawl. Google does
 * not participate — Googlebot relies on the sitemap + crawl budget.
 */
class PingIndexNow implements ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;

    /** P6-64: bounded retries; timeout stays below queue retry_after. */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public int $timeout = 120;

    /** @param array<int,string> $urls */
    public function __construct(public array $urls) {}

    public function handle(): void
    {
        $key = config('seo.indexnow_key');
        $urls = array_values(array_filter(array_unique($this->urls)));

        if (empty($key) || empty($urls)) {
            return;
        }

        $host = parse_url($urls[0], PHP_URL_HOST);
        if (! $host) {
            return;
        }

        $payload = [
            'host' => $host,
            'key' => $key,
            'keyLocation' => 'https://'.$host.'/'.$key.'.txt',
            'urlList' => array_slice($urls, 0, 10000), // IndexNow caps at 10k/request
        ];

        foreach (config('seo.indexnow_endpoints', []) as $endpoint) {
            try {
                Http::timeout(10)->acceptJson()->post($endpoint, $payload);
            } catch (\Throwable $e) {
                Log::channel(config('logging.default'))->warning('indexnow.ping_failed', [
                    'endpoint' => $endpoint, 'error' => $e->getMessage(),
                ]);
            }
        }
    }
}

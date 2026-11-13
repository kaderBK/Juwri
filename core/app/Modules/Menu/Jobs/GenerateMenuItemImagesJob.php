<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Jobs;

use App\Services\QueueGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\TenantUsageDaily;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Contracts\AiLimitExceeded;
use Juwri\Modules\Ai\Contracts\AiSpend;
use Juwri\Modules\Ai\Contracts\ImageGenerator;
use Juwri\Modules\Ai\Models\AiUsage;
use Juwri\Modules\Menu\Models\Menu;

/**
 * Generates a photo for each AI-created menu item (Gemini image model) in the
 * background, then attaches it as the item image. One failed item is skipped,
 * not fatal. Progress goes to the cache so the dashboard can show a live count.
 */
class GenerateMenuItemImagesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    /** Cost/abuse cap per run. */
    public const MAX_ITEMS = 40;

    /** @param array<int,int> $menuIds */
    public function __construct(public int $storeId, public array $menuIds, public ?int $userId = null)
    {
        $this->onQueue('heavy'); // Abuse Guard P5 (5.1): heavy AI work on the dedicated queue
    }

    public static function progressKey(int $storeId): string
    {
        return 'menu_img_progress:'.$storeId;
    }

    public function handle(ImageGenerator $provider): void
    {
        // See TranslateMenuJob::handle — survive the dispatchAfterResponse
        // fallback where web max_execution_time would kill us mid-run.
        // CLI (worker/phpunit) excluded: already unlimited there.
        if (PHP_SAPI !== 'cli') {
            @set_time_limit($this->timeout);
        }

        $key = self::progressKey($this->storeId);

        if (! $provider->isConfigured()) {
            $this->put($key, ['status' => 'error', 'message' => 'Image generation is not configured.']);

            return;
        }

        // Abuse Guard P3: image generation burns the PLATFORM Gemini key but used to
        // bypass AiService entirely — apply the same spend caps here.
        try {
            app(AiSpend::class)->assert($this->userId, $this->storeId, false);
        } catch (AiLimitExceeded $e) {
            $this->put($key, ['status' => 'error', 'message' => $e->getMessage()]);

            return;
        }

        $items = Menu::where('store_id', $this->storeId)
            ->whereIn('id', array_slice($this->menuIds, 0, self::MAX_ITEMS))
            ->get();

        $total = $items->count();
        $done = 0;
        $generated = 0;
        $consecutiveFailures = 0;
        $this->put($key, ['status' => 'running', 'done' => 0, 'total' => $total]);

        foreach ($items as $item) {
            // Never overwrite a photo the owner already set.
            if (! empty($item->getRawOriginal('image')) && $item->getRawOriginal('image') !== 'default.png') {
                $done++;
                $this->put($key, ['status' => 'running', 'done' => $done, 'total' => $total, 'generated' => $generated]);

                continue;
            }

            try {
                $bytes = $provider->generate($this->prompt($item));
                $filename = image_upload($bytes, 'storage/menu/', '500x500', null, null, $this->storeId);
                $item->image = $filename;
                $item->save();
                $generated++;
                $consecutiveFailures = 0;

                // Abuse Guard P3/P7: meter each image like any other AI call so the
                // daily caps and the ai_credits usage metric both see it.
                try {
                    AiUsage::create([
                        'user_id' => $this->userId, 'provider' => 'gemini',
                        'model' => 'image', 'feature' => 'menu_images',
                        'prompt_tokens' => 0, 'completion_tokens' => 0, 'fell_back' => false,
                    ]);
                    if ($this->userId) {
                        $resellerId = User::whereKey($this->userId)->value('reseller_id');
                        TenantUsageDaily::bump((int) $this->userId, 'ai_credits', 1, $resellerId ? (int) $resellerId : null);
                    }
                } catch (\Throwable $e) {
                    Log::warning('[AI menu images] metering failed: '.$e->getMessage());
                }
            } catch (\Throwable $e) {
                Log::warning("[AI menu images] item {$item->id} failed: ".$e->getMessage());
                // Provider down / out of quota: stop hammering it for every item.
                if (++$consecutiveFailures >= 3) {
                    $this->put($key, [
                        'status' => 'error', 'done' => $done, 'total' => $total, 'generated' => $generated,
                        'message' => 'Image generation stopped — the image service is unavailable or out of quota. Generated '.$generated.' photo(s).',
                    ]);

                    return;
                }
            }

            $done++;
            $this->put($key, ['status' => 'running', 'done' => $done, 'total' => $total, 'generated' => $generated]);
        }

        $this->put($key, ['status' => 'done', 'done' => $done, 'total' => $total, 'generated' => $generated]);
        Log::info("[AI menu images] restaurant {$this->storeId}: {$generated}/{$total} images generated");
        QueueGuard::release($this->userId);
    }

    protected function prompt(Menu $item): string
    {
        $name = trim((string) $item->getRawOriginal('name'));
        $desc = trim((string) $item->getRawOriginal('description'));

        return 'Professional food photography of "'.$name.'"'
            .($desc !== '' ? ' — '.$desc : '')
            .'. Appetising restaurant menu photo, single dish centered on a plate, '
            .'natural soft light, shallow depth of field, neutral background, '
            .'square crop. No text, no watermark, no people, no hands.';
    }

    private function put(string $key, array $data): void
    {
        Cache::put($key, $data + ['store_id' => $this->storeId], 3600);

        // Keep the worker heartbeats fresh during this long job (Queue::looping
        // doesn't fire mid-job) so concurrent requests keep queueing normally.
        // queue_worker_stamp_heartbeat() also refreshes the daemon key when this
        // runs inside a dedicated daemon.
        if ($this->job) {
            queue_worker_stamp_heartbeat();
        }
    }

    /** Abuse Guard P5 (5.1): surface a clean error state if the job dies/times out. */
    public function failed(\Throwable $e): void
    {
        QueueGuard::release($this->userId);
        $this->put(self::progressKey($this->storeId), ['status' => 'error', 'message' => 'Image generation failed. Please try again.']);
        Log::error("[AI image] job failed for restaurant {$this->storeId}: ".$e->getMessage());
    }
}

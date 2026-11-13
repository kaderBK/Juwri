<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Http\Controllers\User;

use App\Support\SearchTerm;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ai\Contracts\Ai;
use Juwri\Modules\Ai\Contracts\AiLimitExceeded;
use Juwri\Modules\Media\Contracts\MediaCopier;
use Juwri\Modules\Media\Contracts\MediaCopyFailed;
use Juwri\Modules\Media\Contracts\StockImages;
use Juwri\Modules\Media\Models\PublicMediaAsset;

/**
 * FEATURES-5 Track B — owner-facing browse/copy across the four public media
 * sources: curated shared pool, Pexels/Unsplash stock, Google image search,
 * AI-generated images. Every "use" downloads/copies the file into the owner's
 * own MediaAsset via MediaCopyService (quota enforced by StorageService).
 */
class PublicMediaBrowseController extends Controller
{
    public function __construct(
        protected MediaCopier $copier,
        protected StockImages $stock,
    ) {}

    /** Restaurant IDs owned by the current user. */
    private function ownerRestaurantIds(): array
    {
        return dashboard_store_ids(); // BL-036: a staff session is bound to its own store
    }

    private function assertOwns(int $storeId): void
    {
        if (! in_array($storeId, $this->ownerRestaurantIds(), true)) {
            abort(403);
        }
    }

    private function currentRestaurantId(Request $request): int
    {
        return (int) $request->input('store_id', $request->query('store_id', dashboard_current_store()->id ?? 0));
    }

    // ------------------------------------------------------------------
    // (a) Curated shared library
    // ------------------------------------------------------------------

    /** Paginated active curated pool with title/tag search + category filter. */
    public function publicList(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $category = trim((string) $request->query('category', ''));

        $assets = PublicMediaAsset::where('active', true)
            ->when($category !== '', fn ($qq) => $qq->where('category', $category))
            ->when($q !== '', fn ($qq) => SearchTerm::apply($qq, ['title', 'original_name', 'tags'], $q))
            ->orderByDesc('id')
            ->paginate(24);

        return response()->json([
            'results' => collect($assets->items())->map(fn ($a) => [
                'id' => $a->id,
                'thumb' => $a->url,
                'full' => $a->url,
                'title' => $a->title ?: $a->original_name,
                'category' => $a->category,
                'tags' => $a->tags ?: [],
            ])->all(),
            'has_more' => $assets->hasMorePages(),
            'page' => $assets->currentPage(),
        ]);
    }

    /** Copy a curated asset into the owner's MediaAsset (quota enforced). */
    public function copyPublic(Request $request)
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'public_id' => ['required', 'integer'],
            'folder' => ['nullable', 'string', 'max:40'],
        ]);
        $storeId = (int) $data['store_id'];
        $this->assertOwns($storeId);

        $public = PublicMediaAsset::where('active', true)->findOrFail($data['public_id']);

        $absolute = public_path(PublicMediaAsset::STORAGE_PATH.$public->filename);
        $asset = $this->copier->fromLocalFile($absolute, $storeId, [
            'original_name' => $public->title ?: $public->original_name ?: $public->filename,
            'folder' => $data['folder'] ?? 'menu',
            'source' => 'public',
        ]);

        if (! $asset) {
            return response()->json(['error' => ___('Could not add this image. You may be out of storage space.')], 422);
        }

        return response()->json(['asset' => $this->assetPayload($asset)]);
    }

    // ------------------------------------------------------------------
    // (b/c) Stock (Pexels/Unsplash) + Google image search
    // ------------------------------------------------------------------

    /** Which external providers are configured for this store. */
    public function providers(Request $request)
    {
        $storeId = $this->currentRestaurantId($request);
        if ($storeId) {
            $this->assertOwns($storeId);
        }

        return response()->json([
            'available' => $this->stock->availableProviders($storeId ?: null),
            'unsafe' => StockImages::UNSAFE_PROVIDERS,
        ]);
    }

    /** Search an external provider (pexels|unsplash|google_cse). */
    public function stockSearch(Request $request)
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'provider' => ['required', 'string'],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $storeId = (int) $data['store_id'];
        $this->assertOwns($storeId);

        $out = $this->stock->search(
            $data['provider'],
            (string) ($data['q'] ?? ''),
            (int) ($data['page'] ?? 1),
            $storeId
        );

        return response()->json([
            'results' => $out['results'],
            'error' => $out['error'],
        ]);
    }

    /**
     * Copy an external (stock/google) image into the owner's MediaAsset by
     * downloading it server-side. Stores attribution; pings the Unsplash
     * download-trigger endpoint per their API terms.
     */
    public function copyExternal(Request $request)
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'provider' => ['required', 'string'],
            'url' => ['required', 'url', 'max:2048'],
            'folder' => ['nullable', 'string', 'max:40'],
            'author' => ['nullable', 'string', 'max:255'],
            'author_url' => ['nullable', 'string', 'max:2048'],
            'source_url' => ['nullable', 'string', 'max:2048'],
            'download' => ['nullable', 'string', 'max:2048'],
        ]);
        $storeId = (int) $data['store_id'];
        $this->assertOwns($storeId);

        $provider = $data['provider'];
        if (! $this->stock->isSupported($provider)) {
            return response()->json(['error' => ___('Unknown image provider.')], 422);
        }

        $sourceKey = $provider === 'google_cse' ? 'google' : $provider;

        try {
            $asset = $this->copier->fromUrl($data['url'], $storeId, [
                'original_name' => ($data['author'] ?? $sourceKey).'-image',
                'folder' => $data['folder'] ?? 'menu',
                'source' => $sourceKey,
                'attribution' => array_filter([
                    'provider' => $sourceKey,
                    'author' => $data['author'] ?? null,
                    'author_url' => $data['author_url'] ?? null,
                    'source_url' => $data['source_url'] ?? null,
                ]),
            ]);
        } catch (MediaCopyFailed $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        if (! $asset) {
            return response()->json(['error' => ___('Could not add this image. You may be out of storage space.')], 422);
        }

        // Unsplash usage tracking (required by their API guidelines).
        $this->stock->triggerDownload($provider, $data['download'] ?? null, $storeId);

        return response()->json(['asset' => $this->assetPayload($asset)]);
    }

    // ------------------------------------------------------------------
    // (d) AI-generated images
    // ------------------------------------------------------------------

    /** Generate a preview image from a prompt (base64, not yet saved). */
    public function aiGenerate(Request $request, Ai $ai)
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'prompt' => ['required', 'string', 'min:3', 'max:1000'],
            'size' => ['nullable', 'string', 'max:20'],
        ]);
        $storeId = (int) $data['store_id'];
        $this->assertOwns($storeId);

        $size = in_array($data['size'] ?? '', ['1024x1024', '1024x1792', '1792x1024'], true)
            ? $data['size'] : '1024x1024';

        try {
            $image = $ai->generateImage(
                $data['prompt'],
                ['size' => $size],
                auth()->id(),
                $storeId,
                'image_gen'
            );
        } catch (AiLimitExceeded $e) {
            return response()->json(['error' => ___('You have reached your AI usage limit.')], 429);
        } catch (\Throwable $e) {
            return response()->json(['error' => ___('Image generation failed. The AI provider may be unavailable or out of quota.')], 502);
        }

        return response()->json([
            'preview' => 'data:'.$image->mime.';base64,'.base64_encode($image->bytes),
            'mime' => $image->mime,
            'model' => $image->model,
        ]);
    }

    /** Save a previously-generated (base64) image into the owner's MediaAsset. */
    public function aiSave(Request $request)
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'preview' => ['required', 'string'],
            'prompt' => ['nullable', 'string', 'max:1000'],
            'folder' => ['nullable', 'string', 'max:40'],
        ]);
        $storeId = (int) $data['store_id'];
        $this->assertOwns($storeId);

        // Parse the data URL produced by aiGenerate().
        if (! preg_match('#^data:(?<mime>image/[a-z+]+);base64,(?<b64>.+)$#s', $data['preview'], $m)) {
            return response()->json(['error' => ___('Invalid image data.')], 422);
        }
        $bytes = base64_decode($m['b64'], true);
        if ($bytes === false || $bytes === '') {
            return response()->json(['error' => ___('Invalid image data.')], 422);
        }

        $asset = $this->copier->fromBytes($bytes, $storeId, [
            'original_name' => Str::limit($data['prompt'] ?? 'ai-image', 60, ''),
            'folder' => $data['folder'] ?? 'menu',
            'source' => 'ai',
            'mime' => $m['mime'],
        ]);

        if (! $asset) {
            return response()->json(['error' => ___('Could not save this image. You may be out of storage space.')], 422);
        }

        return response()->json(['asset' => $this->assetPayload($asset)]);
    }

    // ------------------------------------------------------------------
    // Owner BYOK settings (stock + media_search keys)
    // ------------------------------------------------------------------

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'pexels_key' => ['nullable', 'string', 'max:255'],
            'unsplash_key' => ['nullable', 'string', 'max:255'],
            'google_api_key' => ['nullable', 'string', 'max:255'],
            'google_cx' => ['nullable', 'string', 'max:255'],
        ]);
        $storeId = (int) $data['store_id'];
        $this->assertOwns($storeId);

        $this->upsertCred($storeId, 'stock', 'pexels', ['api_key' => $data['pexels_key'] ?? null]);
        $this->upsertCred($storeId, 'stock', 'unsplash', ['access_key' => $data['unsplash_key'] ?? null]);
        $this->upsertCred($storeId, 'media_search', 'google_cse', [
            'api_key' => $data['google_api_key'] ?? null,
            'cx' => $data['google_cx'] ?? null,
        ]);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', ___('Saved'));
    }

    private function upsertCred(int $storeId, string $feature, string $provider, array $incoming): void
    {
        $incoming = array_filter($incoming, fn ($v) => $v !== null && $v !== '');
        $cred = Credential::firstOrNew([
            'store_id' => $storeId,
            'feature' => $feature,
            'provider' => $provider,
        ]);
        $secrets = is_array($cred->secrets) ? $cred->secrets : [];
        // Merge non-empty values; leaving a field blank preserves the stored key.
        $cred->secrets = array_merge($secrets, $incoming);
        if (! $cred->exists) {
            $cred->active = true;
        }
        $cred->save();
    }

    // ------------------------------------------------------------------

    private function assetPayload($asset): array
    {
        return [
            'id' => $asset->id,
            'filename' => $asset->filename,
            'name' => $asset->original_name,
            'folder' => $asset->folder,
            'url' => $asset->url,
        ];
    }
}

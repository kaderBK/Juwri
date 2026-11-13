<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Media\Http\Controllers\User;

use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Media\Models\MediaAsset;

class MediaController extends Controller
{
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

    /** Full-page media library manager. */
    public function index(Request $request)
    {
        $storeIds = $this->ownerRestaurantIds();
        $storeId = (int) $request->query('store_id', dashboard_current_store()->id ?? 0);

        if ($storeId && ! in_array($storeId, $storeIds, true)) {
            abort(403);
        }

        $stores = Store::whereIn('id', $storeIds)->get(['id', 'title']);
        if (! $storeId && $stores->count() === 1) {
            $storeId = (int) $stores->first()->id;
        }

        $query = trim((string) $request->query('q', ''));
        $folder = trim((string) $request->query('folder', ''));

        $assets = collect();
        if ($storeId) {
            $assets = MediaAsset::where('store_id', $storeId)
                ->when($folder !== '', fn ($q) => $q->where('folder', $folder))
                ->when($query !== '', fn ($q) => $q->whereLike('original_name', "%{$query}%"))
                ->orderByDesc('id')
                ->get();
        }

        return view('owner.media.index', compact(
            'stores', 'storeId', 'assets', 'query', 'folder'
        ));
    }

    /** JSON asset list for the reusable picker dialog. */
    public function list(Request $request)
    {
        $storeId = (int) $request->query('store_id', dashboard_current_store()->id ?? 0);
        $this->assertOwns($storeId);

        $folder = trim((string) $request->query('folder', ''));
        $query = trim((string) $request->query('q', ''));

        $assets = MediaAsset::where('store_id', $storeId)
            ->when($folder !== '', fn ($q) => $q->where('folder', $folder))
            ->when($query !== '', fn ($q) => $q->whereLike('original_name', "%{$query}%"))
            ->orderByDesc('id')
            ->limit(300)
            ->get(['id', 'filename', 'original_name', 'folder'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'filename' => $a->filename,
                'name' => $a->original_name,
                'folder' => $a->folder,
                'url' => $a->url,
            ]);

        return response()->json(['assets' => $assets]);
    }

    /** Upload one or more images into the library. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'folder' => ['nullable', 'string', 'max:40'],
            'images' => ['required', 'array', 'min:1'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
        ]);

        $storeId = (int) $data['store_id'];
        $this->assertOwns($storeId);

        $folder = $data['folder'] ?? 'other';
        $created = [];

        foreach ($request->file('images') as $file) {
            if (! $file->isValid()) {
                continue;
            }
            $original = $file->getClientOriginalName();
            $size = $file->getSize();

            // Abuse Guard P5: hardening + per-tenant storage quota are enforced centrally
            // in StorageService::upload. A rejected upload returns '' — skip the asset row.
            $filename = image_upload($file, 'storage/menu/', '1200', null, null, $storeId);
            if ($filename === '') {
                continue;
            }

            $asset = MediaAsset::create([
                'store_id' => $storeId,
                'filename' => $filename,
                'original_name' => $original,
                'folder' => $folder,
                'size_bytes' => $size,
            ]);

            $created[] = [
                'id' => $asset->id,
                'filename' => $asset->filename,
                'name' => $asset->original_name,
                'folder' => $asset->folder,
                'url' => $asset->url,
            ];
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['assets' => $created]);
        }

        return back()->with('success', ___('Uploaded').' ('.count($created).')');
    }

    /** Delete an asset (file removed only if unreferenced by other rows). */
    public function destroy(Request $request, $asset)
    {
        $asset = MediaAsset::findOrFail($asset);
        $this->assertOwns((int) $asset->store_id);

        // Storage bytes are released centrally by StorageService::delete (invoked via
        // MediaAsset::deleting → remove_file) so the counter isn't decremented twice.
        $asset->delete();

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', ___('Deleted'));
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Media\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Media\Models\PublicMediaAsset;

/**
 * FEATURES-5 Track B1 — superadmin CRUD for the curated shared media pool.
 * Files live under storage/public-media/ (platform-level, storeId null).
 */
class PublicMediaController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $category = trim((string) $request->query('category', ''));

        $assets = PublicMediaAsset::query()
            ->when($category !== '', fn ($qq) => $qq->where('category', $category))
            ->when($q !== '', function ($qq) use ($q) {
                $qq->where(function ($w) use ($q) {
                    $w->whereLike('title', "%{$q}%")
                        ->orWhereLike('original_name', "%{$q}%")
                        ->orWhereLike('tags', "%{$q}%");
                });
            })
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString();

        $categories = PublicMediaAsset::CATEGORIES;

        return view('admin.public-media.index', compact('assets', 'q', 'category', 'categories'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'category' => ['nullable', 'string', 'max:40'],
            'tags' => ['nullable', 'string', 'max:500'],
            'title' => ['nullable', 'string', 'max:255'],
            'images' => ['required', 'array', 'min:1'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
        ]);

        $category = in_array($data['category'] ?? '', PublicMediaAsset::CATEGORIES, true) ? $data['category'] : 'other';
        $tags = collect(explode(',', (string) ($data['tags'] ?? '')))
            ->map(fn ($t) => trim($t))->filter()->values()->all();

        $created = 0;
        foreach ($request->file('images') as $file) {
            if (! $file->isValid()) {
                continue;
            }
            $original = $file->getClientOriginalName();
            $size = $file->getSize();

            // Platform-level upload (storeId null): no tenant quota, still
            // hardened by UploadGuard inside StorageService.
            $filename = image_upload($file, PublicMediaAsset::STORAGE_PATH, '1400', null, null, null);
            if ($filename === '') {
                continue;
            }

            PublicMediaAsset::create([
                'filename' => $filename,
                'original_name' => $original,
                'title' => $data['title'] ?: pathinfo($original, PATHINFO_FILENAME),
                'tags' => $tags,
                'category' => $category,
                'size_bytes' => $size,
                'source' => 'curated',
                'active' => true,
            ]);
            $created++;
        }

        return back()->with('success', ___('Uploaded').' ('.$created.')');
    }

    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:40'],
            'tags' => ['nullable', 'string', 'max:500'],
        ]);

        $asset = PublicMediaAsset::findOrFail($id);
        $asset->title = $data['title'] ?? $asset->title;
        $asset->category = in_array($data['category'] ?? '', PublicMediaAsset::CATEGORIES, true) ? $data['category'] : $asset->category;
        if (array_key_exists('tags', $data)) {
            $asset->tags = collect(explode(',', (string) $data['tags']))
                ->map(fn ($t) => trim($t))->filter()->values()->all();
        }
        $asset->save();

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', ___('Saved'));
    }

    public function toggle(Request $request, $id)
    {
        $asset = PublicMediaAsset::findOrFail($id);
        $asset->active = ! $asset->active;
        $asset->save();

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'active' => $asset->active]);
        }

        return back()->with('success', ___('Saved'));
    }

    public function destroy(Request $request, $id)
    {
        $asset = PublicMediaAsset::findOrFail($id);
        $asset->delete(); // model boot removes the physical file if unreferenced

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', ___('Deleted'));
    }
}

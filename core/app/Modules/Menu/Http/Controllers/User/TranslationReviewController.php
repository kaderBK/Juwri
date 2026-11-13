<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Http\Controllers\User;

use App\Models\MenuCategory;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ai\Models\TranslationMemory;
use Juwri\Modules\Menu\Models\Menu;

/**
 * #34 (#10): human-in-the-loop review of machine translations. Lists items and
 * categories with their source text and each language's translation, its review
 * state (missing / machine / stale / low-confidence / approved), inline editing,
 * and bulk approve. Approving/editing removes the machine flag and feeds the
 * result back into the translation memory.
 */
class TranslationReviewController extends Controller
{
    private function owned(Request $request, Store $store): void
    {
        abort_unless($store->user_id == $request->user()->id && dashboard_can_access_store($store), 403);
    }

    public function index(Request $request, Store $store)
    {
        $this->owned($request, $store);

        $default = store_options($store->id, 'default_language');
        $codes = store_options($store->id, 'menu_languages');
        $codes = is_array($codes) ? $codes : (array) $codes;
        $targets = array_values(array_filter($codes, fn ($c) => $c && $c !== $default));

        $langNames = Language::whereIn('code', $targets)->pluck('name', 'code')->toArray();

        $rows = [];
        foreach (MenuCategory::where('store_id', $store->id)->orderBy('position')->get() as $c) {
            $rows[] = $this->row('category', $c, ['title' => (string) $c->getRawOriginal('name')], $targets);
        }
        foreach (Menu::where('store_id', $store->id)->orderBy('position')->get() as $m) {
            $rows[] = $this->row('item', $m, [
                'title' => (string) $m->getRawOriginal('name'),
                'description' => (string) $m->getRawOriginal('description'),
            ], $targets);
        }
        // Keep only rows that have something to review in at least one language.
        $rows = array_values(array_filter($rows, fn ($r) => $r['needs']));

        return view('owner.menu.translations', [
            'store' => $store,
            'langNames' => $langNames,
            'targets' => $targets,
            'rows' => $rows,
        ]);
    }

    private function row(string $type, $model, array $source, array $targets): array
    {
        $source = array_filter($source, fn ($v) => trim((string) $v) !== '');
        $hash = sha1(implode('|', array_values($source)));
        $t = (object) ($model->translations ?? new \stdClass);

        $langs = [];
        $needs = false;
        foreach ($targets as $code) {
            $e = $t->{$code} ?? null;
            $state = 'missing';
            if ($e && trim((string) ($e->title ?? '')) !== '') {
                if (! empty($e->_machine) && (($e->_src_hash ?? null) !== $hash)) {
                    $state = 'stale';
                } elseif (! empty($e->_lowconf)) {
                    $state = 'lowconf';
                } elseif (! empty($e->_machine)) {
                    $state = 'machine';
                } else {
                    $state = 'approved';
                }
            }
            if ($state !== 'approved') {
                $needs = true;
            }
            $langs[$code] = [
                'title' => $e->title ?? '',
                'description' => $e->description ?? '',
                'state' => $state,
            ];
        }

        return [
            'type' => $type,
            'id' => $model->id,
            'source' => $source,
            'langs' => $langs,
            'needs' => $needs,
        ];
    }

    public function save(Request $request, Store $store)
    {
        $this->owned($request, $store);

        $data = $request->validate([
            'type' => 'required|in:item,category',
            'id' => 'required|integer',
            'lang' => 'required|string|max:12',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
        ]);

        $model = $this->model($store, $data['type'], (int) $data['id']);
        if (! $model) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $t = (object) ($model->translations ?? new \stdClass);
        $entry = ['title' => trim($data['title'])]; // owner-approved: no _machine flag
        if ($data['type'] === 'item' && $request->filled('description')) {
            $entry['description'] = trim($data['description']);
        }
        $t->{$data['lang']} = (object) $entry;
        $model->translations = $t;
        $model->save();

        // Learn: feed the approved translation back into memory.
        $baseTitle = (string) $model->getRawOriginal($data['type'] === 'category' ? 'name' : 'name');
        TranslationMemory::remember($store->id, $data['lang'], $baseTitle, $entry['title']);
        if (isset($entry['description'])) {
            TranslationMemory::remember($store->id, $data['lang'], (string) $model->getRawOriginal('description'), $entry['description']);
        }

        return response()->json(['success' => true]);
    }

    public function approveAll(Request $request, Store $store)
    {
        $this->owned($request, $store);
        $onlyLang = $request->input('lang'); // optional

        $approved = 0;
        $apply = function ($model, string $baseTitle, ?string $baseDesc) use ($store, $onlyLang, &$approved) {
            $t = (object) ($model->translations ?? new \stdClass);
            $changed = false;
            foreach (get_object_vars($t) as $code => $entry) {
                if ($onlyLang && $code !== $onlyLang) {
                    continue;
                }
                if (empty($entry->_machine) || trim((string) ($entry->title ?? '')) === '') {
                    continue;
                }
                $clean = ['title' => $entry->title];
                if (isset($entry->description)) {
                    $clean['description'] = $entry->description;
                }
                $t->{$code} = (object) $clean; // strip _machine / _src_hash / _lowconf
                TranslationMemory::remember($store->id, $code, $baseTitle, (string) $entry->title);
                if (isset($entry->description) && $baseDesc !== null) {
                    TranslationMemory::remember($store->id, $code, $baseDesc, (string) $entry->description);
                }
                $changed = true;
                $approved++;
            }
            if ($changed) {
                $model->translations = $t;
                $model->save();
            }
        };

        foreach (MenuCategory::where('store_id', $store->id)->get() as $c) {
            $apply($c, (string) $c->getRawOriginal('name'), null);
        }
        foreach (Menu::where('store_id', $store->id)->get() as $m) {
            $apply($m, (string) $m->getRawOriginal('name'), (string) $m->getRawOriginal('description'));
        }

        return response()->json(['success' => true, 'approved' => $approved]);
    }

    private function model(Store $store, string $type, int $id)
    {
        return $type === 'category'
            ? MenuCategory::where('store_id', $store->id)->find($id)
            : Menu::where('store_id', $store->id)->find($id);
    }
}

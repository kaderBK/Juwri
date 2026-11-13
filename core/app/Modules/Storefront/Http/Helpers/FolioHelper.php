<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Media\Contracts\Images;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Models\FolioPageStat;

/*
 * Folio Polish (FP run) — backend read helpers for the flip-book theme.
 *
 * Kept OUT of the god-file Helper.php (which rides the ControllerSizeGuardTest
 * line ceiling), autoloaded via composer.json "files" exactly like
 * GeoHelper.php / NavHelper.php / WhatsAppHelper.php. Every function is
 * sparse-safe: a fresh store with zero options set resolves to sane empty
 * defaults, never a warning or a throw. The storefront (lane A) guards every
 * call with function_exists() and keeps today's behaviour as the fallback, so
 * these live entirely on the backend side of the FP contract (SPEC §10/§12).
 */

if (! function_exists('folio_json_option')) {
    /**
     * Read a JSON post-option as a plain associative array, whatever form the
     * option store hands back. StoreOption::getStoreOption auto-decodes any value
     * that is valid JSON (so a stored array/map comes back as array/stdClass),
     * while a corrupt/plain string comes back verbatim and an unset key comes
     * back as false — all three normalise to [].
     *
     * @return array<mixed>
     */
    function folio_json_option(int $storeId, string $key): array
    {
        $raw = store_options($storeId, $key);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }
        if (is_array($raw) || is_object($raw)) {
            $decoded = json_decode((string) json_encode($raw), true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}

if (! function_exists('folio_valid_hhmm')) {
    /**
     * Normalise a value to a strict "HH:MM" (00:00–23:59) or null. Shared by the
     * book validators so start/end are always comparable as plain strings.
     */
    function folio_valid_hhmm(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : null;
    }
}

if (! function_exists('folio_books')) {
    /**
     * Validated flip-book definitions for a store, in defined order (max 6).
     * Each entry is `{key,label,start,end}` with start/end as strict "HH:MM".
     * Corrupt JSON, missing fields, and duplicate keys are dropped silently;
     * an unset/empty/corrupt option yields [].
     *
     * @return array<int, array{key:string,label:string,start:string,end:string}>
     */
    function folio_books(int $storeId): array
    {
        $decoded = folio_json_option($storeId, 'restaurant_folio_books');
        if ($decoded === []) {
            return [];
        }

        $books = [];
        $seen = [];
        foreach ($decoded as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $key = trim((string) ($entry['key'] ?? ''));
            $label = trim((string) ($entry['label'] ?? ''));
            $start = folio_valid_hhmm($entry['start'] ?? null);
            $end = folio_valid_hhmm($entry['end'] ?? null);
            if ($key === '' || $label === '' || $start === null || $end === null || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $books[] = ['key' => $key, 'label' => $label, 'start' => $start, 'end' => $end];
            if (count($books) >= 6) {
                break;
            }
        }

        return $books;
    }
}

if (! function_exists('folio_store_tz')) {
    /**
     * The timezone a store's flip-book windows resolve in: the post's first
     * ACTIVE branch timezone, else the app default. Never throws.
     */
    function folio_store_tz(int $storeId): string
    {
        $tz = (string) config('app.timezone', 'UTC');
        try {
            $branch = Branch::where('store_id', $storeId)
                ->orderBy('position')->orderBy('id')->get()
                ->first(fn ($b) => (bool) $b->active);
            if ($branch && ! empty($branch->timezone)) {
                $tz = (string) $branch->timezone;
            }
        } catch (Throwable $e) {
            // fall through to app tz
        }

        return $tz !== '' ? $tz : 'UTC';
    }
}

if (! function_exists('folio_active_book')) {
    /**
     * The key of the first book whose daily time window covers "now" in the
     * store's timezone, or null when none apply / no books defined. Overnight
     * windows (end < start, e.g. 18:00–02:00) bleed across midnight the same way
     * Branch::isOpenNow() handles them.
     */
    function folio_active_book(int $storeId): ?string
    {
        $books = folio_books($storeId);
        if ($books === []) {
            return null;
        }

        try {
            $now = Carbon::now(folio_store_tz($storeId))->format('H:i');
        } catch (Throwable $e) {
            $now = Carbon::now()->format('H:i');
        }

        foreach ($books as $book) {
            $start = $book['start'];
            $end = $book['end'];
            if ($start === $end) {
                return $book['key']; // full-day window
            }
            if ($end < $start) {
                if ($now >= $start || $now <= $end) {
                    return $book['key'];
                }
            } elseif ($now >= $start && $now <= $end) {
                return $book['key'];
            }
        }

        return null;
    }
}

if (! function_exists('folio_page_book_tags')) {
    /**
     * Map of imageMenuId => bookKey from `restaurant_folio_page_books`. Invalid
     * rows (non-positive id, empty key) are dropped; [] on unset/corrupt.
     *
     * @return array<int, string>
     */
    function folio_page_book_tags(int $storeId): array
    {
        $decoded = folio_json_option($storeId, 'restaurant_folio_page_books');

        $out = [];
        foreach ($decoded as $imageMenuId => $bookKey) {
            $id = (int) $imageMenuId;
            $key = trim((string) $bookKey);
            if ($id > 0 && $key !== '') {
                $out[$id] = $key;
            }
        }

        return $out;
    }
}

if (! function_exists('folio_hotspots_payload')) {
    /**
     * Tappable-hotspot data for a store's flip-book, ready for the storefront:
     * `['spots' => [imageMenuId => [{x,y,w,h,item}]], 'items' => [id => {...}]]`.
     * Coordinates are clamped to 0–1; prices are pre-formatted with the platform
     * money helpers; only items that actually belong to the store survive. []
     * when nothing valid is configured.
     *
     * @return array{spots: array<int, array<int, array{x:float,y:float,w:float,h:float,item:int}>>, items: array<int, array{name:string,price:string,image:string,desc:string,sold_out:bool}>}|array{}
     */
    function folio_hotspots_payload(Store $store): array
    {
        $decoded = folio_json_option($store->id, 'restaurant_folio_hotspots');
        if ($decoded === []) {
            return [];
        }

        $clamp = static function ($v): ?float {
            if (! is_numeric($v)) {
                return null;
            }

            return max(0.0, min(1.0, (float) $v));
        };

        $spots = [];
        $itemIds = [];
        foreach ($decoded as $imageMenuId => $rects) {
            $pid = (int) $imageMenuId;
            if ($pid <= 0 || ! is_array($rects)) {
                continue;
            }
            $clean = [];
            foreach ($rects as $rect) {
                if (! is_array($rect)) {
                    continue;
                }
                $item = (int) ($rect['item'] ?? 0);
                $x = $clamp($rect['x'] ?? null);
                $y = $clamp($rect['y'] ?? null);
                $w = $clamp($rect['w'] ?? null);
                $h = $clamp($rect['h'] ?? null);
                if ($item <= 0 || $x === null || $y === null || $w === null || $h === null || $w <= 0.0 || $h <= 0.0) {
                    continue;
                }
                $clean[] = ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'item' => $item];
                $itemIds[$item] = true;
            }
            if ($clean !== []) {
                $spots[$pid] = $clean;
            }
        }
        if ($spots === []) {
            return [];
        }

        $items = [];
        $rows = Menu::where('store_id', $store->id)
            ->whereIn('id', array_keys($itemIds))
            ->get();
        foreach ($rows as $m) {
            $items[(int) $m->id] = [
                'name' => (string) $m->name,
                'price' => price_symbol_format($m->price),
                'image' => ($m->image && $m->image !== 'default.png')
                    ? media_url('storage/menu/', $m->image, $store->id)
                    : '',
                'desc' => (string) ($m->description ?? ''),
                'sold_out' => (bool) $m->is_sold_out,
            ];
        }

        // Drop rects pointing at a foreign / missing item, then drop empty pages.
        foreach ($spots as $pid => $rects) {
            $kept = array_values(array_filter($rects, fn ($r) => isset($items[$r['item']])));
            if ($kept === []) {
                unset($spots[$pid]);
            } else {
                $spots[$pid] = $kept;
            }
        }
        if ($spots === []) {
            return [];
        }

        return ['spots' => $spots, 'items' => $items];
    }
}

if (! function_exists('folio_cta')) {
    /**
     * The configured order call-to-action for a store's flip-book, or null when
     * hidden/misconfigured. `kind` is whatsapp|link; whatsapp builds a wa.me
     * href off the store's number, link uses the owner's validated http(s) URL.
     * `label` is the owner's text or the translated "Order".
     *
     * @return array{kind:string,href:string,label:string}|null
     */
    function folio_cta(Store $store): ?array
    {
        $kind = store_options($store->id, 'restaurant_folio_cta');
        $kind = is_string($kind) ? $kind : '';
        if ($kind !== 'whatsapp' && $kind !== 'link') {
            return null;
        }

        $label = trim((string) (store_options($store->id, 'restaurant_folio_cta_label') ?: ''));
        if ($label === '') {
            $label = ___('Order');
        }

        if ($kind === 'whatsapp') {
            $number = (string) storefront_whatsapp_number($store, null, false);
            if ($number === '') {
                return null;
            }

            return ['kind' => 'whatsapp', 'href' => whatsapp_link($number), 'label' => $label];
        }

        $url = trim((string) (store_options($store->id, 'restaurant_folio_cta_url') ?: ''));
        if (! preg_match('#^https?://#i', $url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return ['kind' => 'link', 'href' => $url, 'label' => $label];
    }
}

if (! function_exists('folio_make_variant')) {
    /**
     * Best-effort: write the `-w900.webp` responsive variant of a menu image
     * beside the original in storage/menu/ (rid-aware via StorageService, so a
     * BYOK/S3 store gets it in its own bucket). `$source` is the fresh
     * UploadedFile on upload, or a local file path for the backfill command.
     * Returns the variant filename, or null on skip/failure — a failure never
     * blocks the parent save (folio_srcset just returns null and the storefront
     * serves full-res).
     *
     * @param  \Illuminate\Http\UploadedFile|string  $source
     */
    function folio_make_variant($source, string $image, ?int $rid = null): ?string
    {
        $image = trim($image);
        if ($image === '' || $image === 'default.png') {
            return null;
        }

        return app(Images::class)->thumbnail(
            $source, 'storage/menu/', pathinfo($image, PATHINFO_FILENAME).'-w900', '900', $rid
        );
    }
}

if (! function_exists('folio_srcset')) {
    /**
     * A `srcset` string pairing the -w900 responsive variant with the original,
     * or null when the variant is not present on the local disk (a BYOK/S3 store
     * has no local file to stat, so it falls back to full-res). Deliberately
     * carries NO static memo — a variant can be created between two calls in the
     * same process (backfill command, editor upload).
     */
    function folio_srcset(string $image, ?int $rid = null): ?string
    {
        $image = trim($image);
        if ($image === '' || $image === 'default.png') {
            return null;
        }
        $variant = pathinfo($image, PATHINFO_FILENAME).'-w900.webp';
        if (! is_file(public_path('storage/menu/'.$variant))) {
            return null;
        }

        return media_url('storage/menu/', $variant, $rid).' 900w, '
            .media_url('storage/menu/', $image, $rid).' 1600w';
    }
}

if (! function_exists('folio_pdf_pages')) {
    /**
     * Absolute URLs of the pre-rastered PDF pages for a store's flip-book, in
     * page order, or [] when none. The count is the source of truth
     * (`restaurant_folio_pdf_pages`); each file is confirmed present before it is
     * listed so a partial upload degrades gracefully to the legacy PDF path.
     *
     * @return array<int, string>
     */
    function folio_pdf_pages(int $storeId): array
    {
        $count = (int) (store_options($storeId, 'restaurant_folio_pdf_pages') ?: 0);
        if ($count < 1) {
            return [];
        }

        $out = [];
        for ($n = 1; $n <= $count; $n++) {
            $rel = 'storage/folio-pdf-pages/'.$storeId.'/p'.$n.'.jpg';
            if (is_file(public_path($rel))) {
                $out[] = asset($rel);
            }
        }

        return $out;
    }
}

if (! function_exists('folio_metrics_summary')) {
    /**
     * Roll up the last $days of flip-book page views for the owner "Insights"
     * panel: total views, per-page view counts (page => views, ascending), and
     * the page with the biggest single drop to the next page (or null). Empty +
     * table-absent (pre-migrate) both resolve to the zero state.
     *
     * @return array{total:int, pages:array<int,int>, dropoff:?int}
     */
    function folio_metrics_summary(int $storeId, int $days = 30): array
    {
        $empty = ['total' => 0, 'pages' => [], 'dropoff' => null];
        if (! Schema::hasTable('folio_page_stats')) {
            return $empty;
        }

        $days = max(1, $days);
        $since = Carbon::today()->subDays($days - 1)->toDateString();
        $rows = FolioPageStat::where('store_id', $storeId)
            ->whereDate('day', '>=', $since)
            ->get(['page', 'views']);
        if ($rows->isEmpty()) {
            return $empty;
        }

        $pages = [];
        foreach ($rows as $r) {
            $p = (int) $r->page;
            $pages[$p] = ($pages[$p] ?? 0) + (int) $r->views;
        }
        ksort($pages);

        $dropoff = null;
        $maxDrop = 0;
        $keys = array_keys($pages);
        for ($i = 0, $n = count($keys) - 1; $i < $n; $i++) {
            $drop = $pages[$keys[$i]] - $pages[$keys[$i + 1]];
            if ($drop > $maxDrop) {
                $maxDrop = $drop;
                $dropoff = $keys[$i];
            }
        }

        return ['total' => array_sum($pages), 'pages' => $pages, 'dropoff' => $dropoff];
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;

/**
 * DIRECTORY P0-2: a cuisine in the directory taxonomy.
 *
 * Stores carry at most 3 (T1-1 enforces the cap) — the pivot is what powers
 * filters, /fusion/explore/{city}/{cuisine} landing pages and the
 * `servesCuisine` property in the Restaurant JSON-LD.
 *
 * P1-13 13d: the taxonomy stays Community (store settings, storefront JSON-LD and the Bio generator read a store's
 * cuisines); listings() and suggestions() (directory listings / backfill suggestions) are Marketplace dynamic
 * relations, registered by the Marketplace module.
 */
class Cuisine extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'position' => 'integer',
    ];

    /** Stores tagged with this cuisine. */
    public function stores()
    {
        return $this->belongsToMany(Store::class, 'cuisine_store', 'cuisine_id', 'store_id');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('position')->orderBy('name');
    }

    /**
     * T1-1: the public-facing name, resolved through a NAMESPACED lang key
     * (`cuisine_<slug>`) rather than a bare ___() call on the name itself.
     *
     * A bare `___($cuisine->name)` would slug the ENGLISH name into a lookup
     * key — `___('Healthy')` resolves the platform's existing `healthy` key,
     * which in Arabic already means "good", and `pizza`/`desserts`/`bakery`/
     * `breakfast`/`vegetarian`/`vegan` collide the same way (see
     * CuisineSeeder). Checking `Lang::has()` directly (never calling ___())
     * also means this method can never trip the ___() auto-register trap —
     * it only ever READS a lang key, it never writes one.
     *
     * Until a translation is deliberately added under `cuisine_<slug>` (a
     * LANG-QUEUE flush, not this method), every locale falls back to the DB
     * `name` column, which the console makes editable — the platform's normal
     * untranslated-string behaviour, same as CuisineSeeder documents.
     */
    public function displayName(): string
    {
        $key = 'lang.cuisine_'.Str::slug($this->slug, '_');

        return Lang::has($key) ? (string) trans($key, [], get_lang()) : $this->name;
    }
}

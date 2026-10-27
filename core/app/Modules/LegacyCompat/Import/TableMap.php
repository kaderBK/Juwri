<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

use Closure;

/**
 * Explicit column map of one source table onto one target table (plan §1.1, R1). Nothing is copied by name match:
 * every target column names its source column(s); a source column that is neither mapped, used by the transform nor
 * listed in $ignore is reported as UNKNOWN, a mapped source column the source does not have is reported as MISSING
 * (the target keeps its default), and a mapped target column this install does not have is reported and dropped.
 *
 * Column specs map a target column to a source column name, or to a list of candidate names (the first one the source
 * has wins, for columns renamed across source versions).
 *
 * Scope:
 *  - `platform`: no store (users, allergies);
 *  - `store`: rows of one store, selected by the first present column of $storeColumns;
 *  - `child`: rows of a parent table of the same store, selected by $parentColumn against the parent ids just loaded.
 *
 * Tenancy (J29-1): account_id and store_id of a store/child row are set by the loader from the store being imported
 * (never from a source column); branch_id comes from the source and must name a branch of the same store.
 */
final class TableMap
{
    public const PLATFORM = 'platform';

    public const STORE = 'store';

    public const CHILD = 'child';

    /**
     * @param  array<string, string|list<string>>  $columns  target => source copied as-is
     * @param  array<string, string|list<string>>  $dates  target => source DATETIME, converted from the source zone to UTC
     * @param  array<string, string|list<string>>  $bools  target => source, cast to bool
     * @param  array<string, string|list<string>>  $money  target *_minor => source amount (LegacyMoney, store currency)
     * @param  array<string, string>  $refs  target column => target table: the id must be a row of the same store
     *                                       (or an imported platform row); otherwise NULL + conflict
     * @param  array<string, string>  $requiredRefs  like $refs, but the row is skipped when the id does not resolve
     * @param  array<string, mixed>  $defaults  target => constant
     * @param  list<string>  $storeColumns  candidate source columns holding the store id (store scope)
     * @param  list<string>  $ignore  source columns deliberately not imported (documented, not reported)
     * @param  list<string>  $uses  extra source columns the transform reads
     * @param  (Closure(array<string, mixed>, array<string, mixed>, ImportContext): (array<string, mixed>|string))|null  $transform
     *                                                                                                                               final say on the target row; a string return skips the row with that reason
     * @param  (Closure(array<string, mixed>): bool)|null  $rowFilter  the source rows this map takes; the others belong to
     *                                                                 a sibling map of the same source table (not counted, not reported)
     * @param  bool  $freshIds  the target id is a new one above every kept id (two source tables share one target and
     *                          their ids would collide); the side table maps source id => target id
     * @param  (Closure(ImportContext, list<int>): void)|null  $afterLoad  runs after the rows are in (same transaction),
     *                                                                     with the source ids written
     * @param  (Closure(array<string, mixed>): array<string, mixed>)|null  $checksumRow  the source row as checksummed into
     *                                                                                   the side table (personal data the
     *                                                                                   target only keeps hashed is
     *                                                                                   hashed there too)
     */
    public function __construct(
        public readonly string $source,
        public readonly string $target,
        public readonly string $scope,
        public readonly array $columns = [],
        public readonly array $dates = [],
        public readonly array $bools = [],
        public readonly array $money = [],
        public readonly array $refs = [],
        public readonly array $requiredRefs = [],
        public readonly array $defaults = [],
        public readonly array $storeColumns = [],
        public readonly ?string $parentColumn = null,
        public readonly ?string $parentTarget = null,
        public readonly ?string $selfParent = null,
        public readonly bool $currency = false,
        public readonly array $ignore = [],
        public readonly array $uses = [],
        public readonly ?Closure $transform = null,
        public readonly ?Closure $rowFilter = null,
        public readonly bool $freshIds = false,
        public readonly ?Closure $afterLoad = null,
        public readonly ?Closure $checksumRow = null,
    ) {}

    /** @return array<string, string|list<string>> every target column fed from a source column */
    public function sourced(): array
    {
        return $this->columns + $this->dates + $this->bools + $this->money;
    }
}

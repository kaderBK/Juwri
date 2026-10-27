<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;

/**
 * TAX-RUN F0-P2: one fiscal document (simplified/standard/credit_note) in the
 * append-only ledger. Immutable once submitted — corrections create a NEW
 * document that references the original via supersedes_id. Status machine:
 *   draft → queued → submitted → accepted | rejected ; rejected → (new doc)
 *   any accepted/rejected → superseded (when a correction references it).
 *
 * P1-05: `total` / `tax_total` are stored as `*_minor` in the row's `currency` (the document's; never
 * re-interpreted). They keep the number shape of the old `float` casts. The SEALED body uses the `*_minor` columns
 * and the currency. Authority payloads are built from the sale's FiscalReceiptContext, never from these columns.
 *
 * J25-8: `order_id` is a plain reference; the `order()` relation is defined by Ordering
 * (OrderingServiceProvider, resolveRelationUsing) — Fiscal never reads an order.
 *
 * @property float $total major amount
 * @property float $tax_total major amount
 * @property int $total_minor
 * @property int $tax_total_minor
 * @property string|null $currency
 */
class FiscalDocument extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use HasMinorMoney;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $minorMoney = ['total' => 'total_minor', 'tax_total' => 'tax_total_minor'];

    protected ?string $minorMoneyCurrencyColumn = 'currency';

    protected $casts = [
        'authority_response' => 'array',
        'buyer' => 'array', // P6-176: the B2B buyer of a standard document
        'counter' => 'integer',
        'seq' => 'integer',
        'queued_at' => 'datetime',
        'submitted_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public const STATUSES = ['draft', 'queued', 'submitted', 'accepted', 'rejected', 'superseded'];

    /** Allowed forward transitions (append-only; no un-submitting). */
    public const TRANSITIONS = [
        'draft' => ['queued', 'submitted'],
        'queued' => ['submitted', 'rejected'],
        'submitted' => ['accepted', 'rejected'],
        'accepted' => ['superseded'],
        'rejected' => ['superseded', 'queued'], // resubmit path re-queues
        'superseded' => [],
    ];

    public function minorMoneyFollowsStoreCurrency(): bool
    {
        return false;
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * P6-176: ZATCA clears (rather than reports) a standard (B2B) document: a standard invoice, or a credit / debit note
     * of one (those always carry the buyer).
     */
    public function isStandardB2b(): bool
    {
        return $this->type === 'standard' || (in_array($this->type, ['credit_note', 'debit_note'], true) && ! empty($this->buyer));
    }

    public function canTransitionTo(string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * Move to a new status, enforcing the machine. Returns false (no write) on an
     * illegal transition so callers can guard invariants.
     */
    public function transitionTo(string $to, array $attrs = []): bool
    {
        if (! $this->canTransitionTo($to)) {
            return false;
        }

        $attrs['status'] = $to;
        if ($to === 'submitted' && ! $this->submitted_at) {
            $attrs['submitted_at'] = now();
        }
        if ($to === 'accepted' && ! $this->accepted_at) {
            $attrs['accepted_at'] = now();
        }

        return $this->update($attrs);
    }

    public function isImmutable(): bool
    {
        return in_array($this->status, ['submitted', 'accepted', 'superseded'], true);
    }

    /**
     * Fields that must never change once a document is submitted (chain
     * integrity). NOTE: `payload` is intentionally NOT sealed — the clearance
     * flow legitimately replaces it with the authority-stamped/cleared version
     * on the submitted→accepted transition. The numbering + hash chain is what
     * must be frozen. P1-05: the amounts are the `*_minor` columns — and their
     * `currency` (a changed currency would re-read the same minor units).
     */
    public const SEALED_FIELDS = ['number', 'seq', 'uuid', 'hash', 'previous_hash', 'counter', 'total_minor', 'tax_total_minor', 'currency', 'device_key', 'country'];

    protected static function booted(): void
    {
        static::updating(function (self $doc) {
            // Once submitted/accepted/superseded, the sealed fields are frozen.
            // Status/authority_response/timestamps may still advance (the machine
            // guards status), but the document body can never be rewritten.
            $wasImmutable = in_array($doc->getOriginal('status'), ['submitted', 'accepted', 'superseded'], true);
            if (! $wasImmutable) {
                return;
            }
            foreach (self::SEALED_FIELDS as $field) {
                if (! $doc->isDirty($field)) {
                    continue;
                }
                // First-time sealing (null/empty → value) is finalization, not a
                // rewrite — allowed. Changing an already-set value is forbidden. A
                // real 0 total is a SET value, not "unset", so it stays sealed.
                $original = $doc->getOriginal($field);
                if ($original === null || $original === '') {
                    continue;
                }
                throw new \RuntimeException("Fiscal document {$doc->id} is immutable: cannot modify {$field}.");
            }
        });
    }
}

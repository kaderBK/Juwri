<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Services\Import;

use App\Models\Customer;
use App\Models\ImportBatch;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Modules\Messaging\Data\PhoneCountry;

/**
 * T7-P2 GloriaFood customer import. Brings across the customer list (name/phone/email)
 * as records only. COMPLIANCE: imported customers are NOT auto-opted into marketing —
 * marketing_consent + sms_consent stay false; the restaurant must earn consent freshly.
 * Deduped by phone/email per restaurant; idempotent per file hash.
 */
class CustomerImporter
{
    private const HEADERS = [
        'name' => ['name', 'customer', 'customer name', 'full name'],
        'phone' => ['phone', 'mobile', 'telephone', 'phone number'],
        'email' => ['email', 'e-mail', 'email address'],
    ];

    /** P6-324: refuse a file with more than this many data rows. */
    private const MAX_ROWS = 20000;

    public function parse(string $csv, ?int $storeId = null): array
    {
        $dial = $this->dialCodeFor($storeId);
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);

        // Parse with fgetcsv on a temp stream (escape '\') so a quoted field that
        // contains a line break stays a single record, instead of being split into
        // two rows by a naive newline split before str_getcsv.
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $header = fgetcsv($stream, null, ',', '"', '\\');
        if ($header === false) {
            fclose($stream);

            return ['rows' => [], 'errors' => [__('The file has no data rows.')]];
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        $map = [];
        foreach ($header as $idx => $col) {
            foreach (self::HEADERS as $field => $aliases) {
                if (in_array($col, $aliases, true) && ! isset($map[$field])) {
                    $map[$field] = $idx;
                }
            }
        }
        if (! isset($map['phone']) && ! isset($map['email'])) {
            fclose($stream);

            return ['rows' => [], 'errors' => [__('Could not find a Phone or Email column.')]];
        }

        $rows = [];
        $badRows = [];
        $rowNum = 1; // header is row 1
        $dataRows = 0;
        while (($cells = fgetcsv($stream, null, ',', '"', '\\')) !== false) {
            $rowNum++;
            if ($this->isBlankRecord($cells)) {
                continue;
            }
            $dataRows++;
            if ($dataRows > self::MAX_ROWS) {
                fclose($stream);

                return ['rows' => [], 'errors' => [___('The file has too many rows (maximum :max).', ['max' => self::MAX_ROWS])]];
            }
            $phone = isset($map['phone']) ? trim((string) ($cells[$map['phone']] ?? '')) : '';
            $email = isset($map['email']) ? trim((string) ($cells[$map['email']] ?? '')) : '';
            if ($phone === '' && $email === '') {
                continue;
            }
            // P6-228: validate + lower-case the e-mail, normalise the phone with the store country.
            // A bad row is reported, never fatal.
            $email = mb_strtolower($email);
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $badRows[] = ___('Row :row: skipped — ":email" is not a valid e-mail address.', ['row' => $rowNum, 'email' => mb_substr($email, 0, 60)]);

                continue;
            }
            if ($phone !== '') {
                $normalised = $this->normalizePhone($phone, $dial);
                if ($normalised === null) {
                    $badRows[] = ___('Row :row: skipped — ":phone" is not a valid phone number.', ['row' => $rowNum, 'phone' => mb_substr($phone, 0, 40)]);

                    continue;
                }
                $phone = $normalised;
            }
            $rows[] = [
                'name' => isset($map['name']) ? trim((string) ($cells[$map['name']] ?? '')) : '',
                'phone' => $phone,
                'email' => $email ?: null,
            ];
        }
        fclose($stream);

        if ($dataRows === 0) {
            return ['rows' => [], 'errors' => [__('The file has no data rows.')]];
        }

        return ['rows' => $rows, 'errors' => $badRows];
    }

    /**
     * A blank record is an empty line, or a line holding only whitespace (fgetcsv yields a single empty/whitespace cell).
     *
     * @param  array<int, string|null>  $cells
     */
    private function isBlankRecord(array $cells): bool
    {
        return count($cells) === 1 && ($cells[0] === null || trim((string) $cells[0]) === '');
    }

    /** Dial code (digits) of the store's own phone number, or '' when it is not a known market. */
    private function dialCodeFor(?int $storeId): string
    {
        $phone = $storeId ? (string) Store::whereKey($storeId)->value('phone') : '';
        if ($phone === '' || ! str_starts_with(ltrim($phone), '+') && ! str_starts_with(ltrim($phone), '00')) {
            return '';
        }
        $digits = ltrim((string) preg_replace('/\D+/', '', $phone), '0');
        foreach ([3, 2, 1] as $len) {
            if (isset(PhoneCountry::DIAL_CODES[substr($digits, 0, $len)])) {
                return substr($digits, 0, $len);
            }
        }

        return '';
    }

    /** Digits-only canonical form: Arabic-Indic digits to Latin, "+"/"00" prefix kept, national "0" replaced by the store dial code. */
    private function normalizePhone(string $raw, string $dial): ?string
    {
        $p = strtr($raw, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        $p = (string) preg_replace('/[\s\-().\x{200E}\x{200F}]/u', '', $p);
        if (str_starts_with($p, '00')) {
            $p = '+'.substr($p, 2);
        }
        if (strlen((string) preg_replace('/\D/', '', $p)) < 3) {
            return null;
        }
        if (! str_starts_with($p, '+') && $dial !== '') {
            $p = '+'.$dial.ltrim($p, '0');
        }

        return preg_match('/^\+?\d{3,15}$/', $p) ? $p : null;
    }

    private function canonicalExisting(?string $phone, string $dial): ?string
    {
        return $phone ? $this->normalizePhone((string) $phone, $dial) : null;
    }

    public function preview(int $storeId, string $csv): array
    {
        $parsed = $this->parse($csv, $storeId);
        $dial = $this->dialCodeFor($storeId);
        $existing = Customer::where('store_id', $storeId)->get(['phone', 'email']);
        $phones = $existing->map(fn ($c) => $this->canonicalExisting($c->phone, $dial))->filter()->flip();
        $emails = $existing->pluck('email')->filter()->map(fn ($e) => mb_strtolower($e))->flip();

        $new = 0;
        $skip = 0;
        foreach ($parsed['rows'] as $r) {
            if (($r['phone'] && isset($phones[$r['phone']])) || ($r['email'] && isset($emails[$r['email']]))) {
                $skip++;
            } else {
                $new++;
            }
        }

        return ['errors' => $parsed['errors'], 'total_rows' => count($parsed['rows']), 'created' => $new, 'skipped' => $skip];
    }

    public function import(int $storeId, string $csv): ImportBatch
    {
        $hash = hash('sha256', $csv);
        $existing = ImportBatch::where('store_id', $storeId)
            ->where('source', 'gloriafood')->where('kind', 'customers')->where('file_hash', $hash)->first();

        // P6-324: dedupe only while that earlier batch's customers still exist.
        // When they were deleted, the same file must import again (and the batch
        // row is updated in place — the import_batches unique key is
        // (store_id, source, kind, file_hash), so re-inserting would crash).
        if ($existing && Customer::where('store_id', $storeId)->where('source', 'gloriafood_import')->exists()) {
            $existing->already_imported = true;

            return $existing;
        }

        $rows = $this->parse($csv, $storeId)['rows'];
        $dial = $this->dialCodeFor($storeId);

        return DB::transaction(function () use ($storeId, $rows, $hash, $dial, $existing) {
            $existingC = Customer::where('store_id', $storeId)->get(['phone', 'email']);
            $phones = $existingC->map(fn ($c) => $this->canonicalExisting($c->phone, $dial))->filter()->flip();
            $emails = $existingC->pluck('email')->filter()->map(fn ($e) => mb_strtolower($e))->flip();

            $created = 0;
            $skipped = 0;
            foreach ($rows as $r) {
                if (($r['phone'] && isset($phones[$r['phone']])) || ($r['email'] && isset($emails[$r['email']]))) {
                    $skipped++;

                    continue;
                }
                Customer::create([
                    'store_id' => $storeId,
                    'name' => $r['name'] ?: ($r['phone'] ?: $r['email']),
                    'phone' => $r['phone'] ?: null,
                    'email' => $r['email'],
                    'source' => 'gloriafood_import',
                    'marketing_consent' => false, // compliance: no auto opt-in
                    'sms_consent' => false,
                ]);
                if ($r['phone']) {
                    $phones->put($r['phone'], true);
                }
                if ($r['email']) {
                    $emails->put($r['email'], true);
                }
                $created++;
            }

            if ($existing) {
                $existing->update([
                    'status' => 'done',
                    'items_created' => $created,
                    'skipped' => $skipped,
                ]);

                return $existing;
            }

            return ImportBatch::create([
                'store_id' => $storeId, 'source' => 'gloriafood', 'kind' => 'customers',
                'file_hash' => $hash, 'status' => 'done',
                'categories_created' => 0, 'items_created' => $created, 'skipped' => $skipped,
            ]);
        });
    }
}

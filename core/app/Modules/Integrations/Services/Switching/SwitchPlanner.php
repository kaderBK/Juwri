<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Switching;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyLedger;
use App\Models\MenuCategory;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Modules\Menu\Models\Menu;

/**
 * P4-22: what an import does to ONE store — the same code for the dry run (preview, nothing written) and the commit
 * (inside one transaction), so the preview is exactly what the commit does when nothing changed in between. Every
 * lookup and write is scoped to the store: another store's items, customers or loyalty accounts are never read,
 * matched or touched, even with the same SKU, phone or e-mail.
 *
 *  menu       match an item by SKU, else by name (case-insensitive); new → created (+ its category); existing →
 *             unchanged in "add" mode, price / description / availability updated in "update" mode. Prices are
 *             parsed exactly into minor units of the store's currency (more decimals than the currency has → error,
 *             never rounded).
 *  customers  match by e-mail or phone; new → created WITHOUT marketing or SMS consent (consent is never imported);
 *             existing → only empty fields filled. A phone of one customer and an e-mail of another → error.
 *  loyalty    the customer must exist (import customers first); the points are credited ONCE per customer and
 *             source as an opening balance ("Opening balance imported (source)") — a second import of the same
 *             source never adds them again, and nothing is ever deducted.
 *
 * @phpstan-type Plan array{counts: array<string, int>, rows: list<array{row: string, action: string, label: string, detail: string}>, errors: list<array{row: string, message: string, field?: string}>}
 */
final class SwitchPlanner
{
    public const PREVIEW_ROWS = 500;

    /**
     * @param  list<array<string, string>>  $rows
     * @return Plan
     */
    public function run(string $dataset, Store $store, array $rows, bool $write, string $mode = 'add', string $source = 'file'): array
    {
        $run = fn () => match (SwitchColumns::dataset($dataset)) {
            'menu' => $this->menu($store, $rows, $write, $mode === 'update'),
            'customers' => $this->customers($store, $rows, $write, $source),
            'loyalty' => $this->loyalty($store, $rows, $write, $source),
        };

        return $write ? DB::transaction($run) : $run();
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return Plan
     */
    private function menu(Store $store, array $rows, bool $write, bool $update): array
    {
        $plan = $this->plan();
        $currency = (string) store_currency((int) $store->id);
        $cats = [];
        foreach (MenuCategory::query()->where('store_id', $store->id)->get() as $c) {
            $cats[mb_strtolower(trim((string) $c->getRawOriginal('name')))] = $c;
        }
        $bySku = [];
        $byName = [];
        foreach (Menu::query()->where('store_id', $store->id)->get() as $m) {
            if (($sku = mb_strtolower(trim((string) $m->external_sku))) !== '') {
                $bySku[$sku] = $m;
            }
            $byName[mb_strtolower(trim((string) $m->getRawOriginal('name')))] ??= $m;
        }
        $seen = [];
        $newCats = [];
        foreach ($rows as $r) {
            $name = $r['name'] ?? '';
            if ($name === '') {
                $this->error($plan, $r, ___('No name.'), 'name');

                continue;
            }
            try {
                $price = self::price($r['price'] ?? '', $currency);
            } catch (SwitchError $e) {
                $this->error($plan, $r, $e->getMessage(), 'price');

                continue;
            }
            $sku = mb_strtolower(trim($r['sku'] ?? ''));
            $key = $sku !== '' ? 'sku:'.$sku : 'name:'.mb_strtolower($name);
            if (isset($seen[$key])) {
                $this->error($plan, $r, ___('Same item as row :row.', ['row' => $seen[$key]]), $sku !== '' ? 'sku' : 'name');

                continue;
            }
            $seen[$key] = $r['_row'];
            $active = self::flag($r['active'] ?? '');
            $category = trim($r['category'] ?? '') !== '' ? trim($r['category']) : ___('Menu');
            $existing = ($sku !== '' ? ($bySku[$sku] ?? null) : null) ?? $byName[mb_strtolower($name)] ?? null;

            if ($existing === null) {
                $catKey = mb_strtolower($category);
                if (! isset($cats[$catKey]) && ! isset($newCats[$catKey])) {
                    $newCats[$catKey] = true;
                    $plan['counts']['categories']++;
                    if ($write) {
                        $cats[$catKey] = MenuCategory::create(['store_id' => $store->id, 'name' => $category, 'active' => '1']);
                    }
                }
                $this->row($plan, $r, 'create', $name, Money::majorFromMinor($price, $currency).' '.$currency);
                if ($write) {
                    Menu::create(array_filter([
                        'store_id' => $store->id, 'category_id' => $cats[$catKey]->id, 'name' => $name,
                        'description' => ($r['description'] ?? '') !== '' ? $r['description'] : null,
                        'price_minor' => $price, 'currency_code' => $currency,
                        'external_sku' => trim($r['sku'] ?? '') !== '' ? trim($r['sku']) : null,
                        'active' => $active === false ? '0' : '1',
                        'translations' => ($r['name_ar'] ?? '') !== '' ? ['ar' => ['title' => $r['name_ar']]] : null,
                    ], fn ($v) => $v !== null));
                }

                continue;
            }
            $changes = [];
            if ((int) $existing->price_minor !== $price) {
                $changes['price_minor'] = $price;
            }
            if (($r['description'] ?? '') !== '' && (string) $existing->getRawOriginal('description') !== $r['description']) {
                $changes['description'] = $r['description'];
            }
            if ($active !== null && (string) $existing->active !== ($active ? '1' : '0')) {
                $changes['active'] = $active ? '1' : '0';
            }
            if (! $update || $changes === []) {
                $this->row($plan, $r, 'skip', $name, $changes === [] ? ___('Already the same') : ___('Exists (choose "update" to change it)'));

                continue;
            }
            $this->row($plan, $r, 'update', $name, implode(', ', array_keys($changes)));
            if ($write) {
                $existing->forceFill($changes + (isset($changes['price_minor']) ? ['currency_code' => $currency] : []))->save();
            }
        }

        return $plan;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return Plan
     */
    private function customers(Store $store, array $rows, bool $write, string $source): array
    {
        $plan = $this->plan();
        [$byPhone, $byEmail] = $this->customerIndex($store);
        $seen = [];
        foreach ($rows as $r) {
            $phone = self::phone($r['phone'] ?? '', $r['dial_code'] ?? '');
            $email = self::email($r['email'] ?? '');
            if ($phone === false || $email === false) {
                $this->error($plan, $r, $phone === false ? ___('The phone number is not valid.') : ___('The e-mail address is not valid.'), $phone === false ? 'phone' : 'email');

                continue;
            }
            if ($phone === null && $email === null) {
                $this->error($plan, $r, ___('No phone or e-mail.'), 'phone');

                continue;
            }
            foreach (array_filter(['p:'.$phone => $phone, 'e:'.$email => $email]) as $k => $_) {
                if (isset($seen[$k])) {
                    $this->error($plan, $r, ___('Same customer as row :row.', ['row' => $seen[$k]]), str_starts_with($k, 'p:') ? 'phone' : 'email');

                    continue 2;
                }
            }
            foreach (array_filter(['p:'.$phone => $phone, 'e:'.$email => $email]) as $k => $_) {
                $seen[$k] = $r['_row'];
            }
            $a = $phone !== null ? ($byPhone[$phone] ?? null) : null;
            $b = $email !== null ? ($byEmail[$email] ?? null) : null;
            if ($a && $b && $a->id !== $b->id) {
                $this->error($plan, $r, ___('The phone and the e-mail belong to two different customers.'), 'phone');

                continue;
            }
            $existing = $a ?? $b;
            $name = trim($r['name'] ?? '');
            $birthday = self::date($r['birthday'] ?? '');
            $label = $name !== '' ? $name : (string) ($email ?? $phone);
            if ($existing === null) {
                $this->row($plan, $r, 'create', $label, '');
                if ($write) {
                    $c = Customer::create(array_filter([
                        'store_id' => $store->id, 'name' => $label, 'phone' => $phone, 'email' => $email, 'birthday' => $birthday,
                        'source' => 'import:'.$source, 'marketing_consent' => false, 'sms_consent' => false,
                    ], fn ($v) => $v !== null));
                    if ($phone !== null) {
                        $byPhone[$phone] = $c;
                    }
                    if ($email !== null) {
                        $byEmail[$email] = $c;
                    }
                }

                continue;
            }
            $fill = array_filter([
                'name' => trim((string) $existing->name) === '' && $name !== '' ? $name : null,
                'phone' => empty($existing->phone) ? $phone : null,
                'email' => empty($existing->email) ? $email : null,
                'birthday' => empty($existing->birthday) ? $birthday : null,
            ], fn ($v) => $v !== null);
            $this->row($plan, $r, $fill === [] ? 'skip' : 'update', $label, $fill === [] ? ___('Already the same') : implode(', ', array_keys($fill)));
            if ($write && $fill !== []) {
                $existing->forceFill($fill)->save();
            }
        }

        return $plan;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return Plan
     */
    private function loyalty(Store $store, array $rows, bool $write, string $source): array
    {
        $plan = $this->plan();
        [$byPhone, $byEmail] = $this->customerIndex($store);
        $note = 'Opening balance imported ('.mb_substr($source, 0, 40).')';
        $seen = [];
        foreach ($rows as $r) {
            $phone = self::phone($r['phone'] ?? '', $r['dial_code'] ?? '');
            $email = self::email($r['email'] ?? '');
            $customer = ($phone ? ($byPhone[$phone] ?? null) : null) ?? ($email ? ($byEmail[$email] ?? null) : null);
            if ($customer === null) {
                $this->error($plan, $r, ___('No customer of this store has this phone or e-mail (import the customers first).'), 'phone');

                continue;
            }
            $raw = str_replace([' ', ','], '', self::latinDigits($r['points'] ?? ''));
            if (! preg_match('/^\d+(\.0+)?$/', $raw) || (int) $raw > 10_000_000) {
                $this->error($plan, $r, ___('Points must be a whole number from 0 to 10,000,000.'), 'points');

                continue;
            }
            $points = (int) $raw;
            if (isset($seen[$customer->id])) {
                $this->error($plan, $r, ___('Same customer as row :row.', ['row' => $seen[$customer->id]]), 'phone');

                continue;
            }
            $seen[$customer->id] = $r['_row'];
            $label = (string) ($customer->name ?: $customer->phone ?: $customer->email);
            $account = LoyaltyAccount::query()->where('store_id', $store->id)->where('customer_id', $customer->id)->first();
            $done = $account && LoyaltyLedger::query()->where('loyalty_account_id', $account->id)->where('note', $note)->exists();
            if ($points === 0 || $done) {
                $this->row($plan, $r, 'skip', $label, $done ? ___('Already imported from this source') : ___('No points'));

                continue;
            }
            $this->row($plan, $r, 'credit', $label, '+'.$points);
            if ($write) {
                LoyaltyAccount::forCustomer((int) $customer->id, (int) $store->id)->creditOnce($points, $note);
            }
        }

        return $plan;
    }

    /** @return array{0: array<string, Customer>, 1: array<string, Customer>} this store's customers by phone and e-mail */
    private function customerIndex(Store $store): array
    {
        $byPhone = [];
        $byEmail = [];
        foreach (Customer::query()->where('store_id', $store->id)->get(['id', 'store_id', 'name', 'phone', 'email', 'birthday']) as $c) {
            if (is_string($p = self::phone((string) $c->phone, ''))) {
                $byPhone[$p] ??= $c;
            }
            if (is_string($e = self::email((string) $c->email))) {
                $byEmail[$e] ??= $c;
            }
        }

        return [$byPhone, $byEmail];
    }

    /** A price as minor units of $currency, exactly: "14.50", "14,50", "1,234.50", "SAR 14.5", "١٤٫٥٠". */
    public static function price(string $raw, string $currency): int
    {
        $s = self::latinDigits($raw);
        $s = str_replace(['٫', '٬'], ['.', ','], $s);
        $s = (string) preg_replace('/[^\d.,\-]/u', '', $s);
        if (str_starts_with($s, '-')) {
            throw new SwitchError(___('The price cannot be negative.'));
        }
        if (str_contains($s, '.') && str_contains($s, ',')) {
            $s = strrpos($s, '.') > strrpos($s, ',') ? str_replace(',', '', $s) : str_replace(['.', ','], ['', '.'], $s);
        } elseif (str_contains($s, ',')) {
            $s = preg_match('/^\d{1,3}(,\d{3})+$/', $s) ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        }
        if (! preg_match('/^\d+(\.\d+)?$/', $s)) {
            throw new SwitchError(___('The price is not a number.'));
        }
        $exp = Currencies::exponent($currency);
        $decimals = str_contains($s, '.') ? rtrim(explode('.', $s)[1], '0') : '';
        if (strlen($decimals) > $exp) {
            throw new SwitchError(___('The price has more decimals than :currency allows.', ['currency' => $currency]));
        }
        if (strlen(explode('.', $s)[0]) > 12) {
            throw new SwitchError(___('The price is too large.'));
        }

        return Money::minorFromMajor($s, $currency);
    }

    /** Spaces and punctuation removed, a leading 00 → +, a separate dial code joined; false = not a phone number. */
    public static function phone(string $raw, string $dial): string|false|null
    {
        $p = (string) preg_replace('/[\s\-().\x{200E}\x{200F}]/u', '', self::latinDigits($raw));
        if ($p === '') {
            return null;
        }
        if (str_starts_with($p, '00')) {
            $p = '+'.substr($p, 2);
        }
        $d = (string) preg_replace('/\D/', '', self::latinDigits($dial));
        if ($d !== '' && ! str_starts_with($p, '+')) {
            $p = '+'.$d.ltrim($p, '0');
        }
        if (! preg_match('/^\+?\d{6,15}$/', $p)) {
            return false;
        }

        return $p;
    }

    public static function email(string $raw): string|false|null
    {
        $e = mb_strtolower(trim($raw));
        if ($e === '') {
            return null;
        }

        return filter_var($e, FILTER_VALIDATE_EMAIL) !== false ? $e : false;
    }

    private static function date(string $raw): ?string
    {
        $raw = trim(self::latinDigits($raw));
        foreach (['Y-m-d', 'd/m/Y', 'Y/m/d', 'd-m-Y'] as $f) {
            $d = \DateTimeImmutable::createFromFormat('!'.$f, $raw);
            if ($d !== false && $d->format($f) === $raw && (int) $d->format('Y') >= 1900 && $d < new \DateTimeImmutable) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    private static function flag(string $raw): ?bool
    {
        $v = mb_strtolower(trim($raw));

        return match (true) {
            $v === '' => null,
            in_array($v, ['1', 'yes', 'true', 'active', 'y', 'نعم', 'نشط', 'متاح'], true) => true,
            in_array($v, ['0', 'no', 'false', 'inactive', 'n', 'لا', 'غير نشط'], true) => false,
            default => null,
        };
    }

    private static function latinDigits(string $s): string
    {
        return strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    }

    /** @return Plan */
    private function plan(): array
    {
        return ['counts' => ['create' => 0, 'update' => 0, 'credit' => 0, 'skip' => 0, 'error' => 0, 'categories' => 0], 'rows' => [], 'errors' => []];
    }

    /**
     * @param  Plan  $plan
     * @param  array<string, string>  $r
     */
    private function row(array &$plan, array $r, string $action, string $label, string $detail): void
    {
        $plan['counts'][$action]++;
        if (count($plan['rows']) < self::PREVIEW_ROWS) {
            $plan['rows'][] = ['row' => $r['_row'], 'action' => $action, 'label' => mb_substr($label, 0, 120), 'detail' => mb_substr($detail, 0, 200)];
        }
    }

    /**
     * @param  Plan  $plan
     * @param  array<string, string>  $r
     */
    private function error(array &$plan, array $r, string $message, ?string $field = null): void
    {
        $plan['counts']['error']++;
        $plan['errors'][] = ['row' => $r['_row'], 'message' => $message] + ($field !== null ? ['field' => $field] : []);
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Switching;

/**
 * P4-22: the three datasets a store can bring across, their columns, and the header names accepted for each column —
 * juwri's own template, common exports (GloriaFood, Square, generic POS), Foodics-style exports
 * (`name_localized`, `category_reference`, `is_active`, `dial_code`…) and Arabic headers. Matching ignores case,
 * spaces, dashes and underscores.
 */
final class SwitchColumns
{
    public const DATASETS = ['menu', 'customers', 'loyalty'];

    /** @var array<string, array<string, list<string>>> dataset => field => aliases */
    private const ALIASES = [
        'menu' => [
            'category' => ['category', 'category name', 'category_name', 'category_reference', 'group', 'section', 'menu section', 'الفئة', 'التصنيف', 'القسم'],
            'name' => ['name', 'item', 'item name', 'product', 'product name', 'dish', 'title', 'الاسم', 'اسم المنتج', 'المنتج', 'الصنف'],
            'name_ar' => ['name_ar', 'name ar', 'name_localized', 'name localized', 'arabic name', 'الاسم بالعربية', 'الاسم العربي'],
            'description' => ['description', 'desc', 'details', 'description_localized', 'الوصف'],
            'price' => ['price', 'amount', 'unit price', 'selling price', 'list price', 'السعر', 'سعر البيع'],
            'sku' => ['sku', 'item sku', 'reference', 'internal reference', 'code', 'product code', 'barcode', 'رمز المنتج', 'الباركود'],
            'active' => ['active', 'is_active', 'is active', 'available', 'status', 'نشط', 'متاح'],
        ],
        'customers' => [
            'name' => ['name', 'customer', 'customer name', 'full name', 'الاسم', 'اسم العميل'],
            'dial_code' => ['dial_code', 'dial code', 'country code', 'رمز الدولة'],
            'phone' => ['phone', 'mobile', 'telephone', 'phone number', 'mobile number', 'رقم الجوال', 'الجوال', 'الهاتف', 'رقم الهاتف'],
            'email' => ['email', 'e-mail', 'email address', 'البريد الإلكتروني', 'البريد'],
            'birthday' => ['birthday', 'birth_date', 'birth date', 'date of birth', 'dob', 'تاريخ الميلاد'],
        ],
        'loyalty' => [
            'phone' => ['phone', 'mobile', 'phone number', 'customer phone', 'رقم الجوال', 'الجوال', 'الهاتف'],
            'dial_code' => ['dial_code', 'dial code', 'country code'],
            'email' => ['email', 'e-mail', 'customer email', 'البريد الإلكتروني'],
            'points' => ['points', 'loyalty points', 'loyalty_points', 'balance', 'points balance', 'loyalty balance', 'النقاط', 'رصيد النقاط'],
        ],
    ];

    /** @var array<string, list<list<string>>> dataset => fields that must be present (any one of each group) */
    private const REQUIRED = [
        'menu' => [['name'], ['price']],
        'customers' => [['phone', 'email']],
        'loyalty' => [['phone', 'email'], ['points']],
    ];

    /** @var array<string, list<string>> the downloadable template rows (header + one example) */
    private const TEMPLATES = [
        'menu' => ['category,name,name_ar,description,price,sku,active', 'Hot drinks,Flat white,فلات وايت,Double shot,14.50,FW-01,1'],
        'customers' => ['name,phone,email,birthday', 'Sara Ali,+966501234567,sara@example.com,1990-05-14'],
        'loyalty' => ['phone,email,points', '+966501234567,sara@example.com,120'],
    ];

    public const STYLES = ['juwri', 'foodics', 'arabic'];

    /** @var array<string, array<string, list<string>>> style => dataset => rows (header + one example); juwri = TEMPLATES */
    private const STYLE_TEMPLATES = [
        'foodics' => [
            'menu' => ['name,name_localized,description,sku,category_reference,price,is_active', 'Flat white,فلات وايت,Double shot,FW-01,Hot drinks,14.50,1'],
            'customers' => ['name,dial_code,phone,email,birth_date', 'Sara Ali,966,501234567,sara@example.com,1990-05-14'],
            'loyalty' => ['dial_code,phone,email,loyalty_points', '966,501234567,sara@example.com,120'],
        ],
        'arabic' => [
            'menu' => ['الفئة,الاسم,الاسم بالعربية,الوصف,السعر,رمز المنتج,نشط', 'مشروبات ساخنة,Flat white,فلات وايت,شوتان,14.50,FW-01,1'],
            'customers' => ['الاسم,رقم الجوال,البريد الإلكتروني,تاريخ الميلاد', 'سارة علي,+966501234567,sara@example.com,1990-05-14'],
            'loyalty' => ['رقم الجوال,البريد الإلكتروني,النقاط', '+966501234567,sara@example.com,120'],
        ],
    ];

    /** @return 'menu'|'customers'|'loyalty' */
    public static function dataset(string $dataset): string
    {
        if (! in_array($dataset, self::DATASETS, true)) {
            throw new SwitchError(___('Unknown import type.'));
        }

        return $dataset;
    }

    /**
     * Header → field => column index; refuses a file without the required columns.
     *
     * @param  list<string>  $header
     * @return array<string, int>
     */
    public static function map(string $dataset, array $header): array
    {
        $norm = fn (string $h): string => (string) preg_replace('/[\s_\-]+/u', ' ', mb_strtolower(trim($h)));
        $map = [];
        foreach ($header as $i => $h) {
            foreach (self::ALIASES[$dataset] as $field => $aliases) {
                if (! isset($map[$field]) && in_array($norm($h), array_map($norm, $aliases), true)) {
                    $map[$field] = $i;

                    break;
                }
            }
        }
        foreach (self::REQUIRED[$dataset] as $group) {
            if (array_intersect($group, array_keys($map)) === []) {
                throw new SwitchError(___('The file has no :column column.', ['column' => implode(' / ', $group)]));
            }
        }

        return $map;
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  array<string, int>  $map
     * @return list<array<string, string>> each row as field => value (row numbers start at 2: the header is row 1)
     */
    public static function rows(array $rows, array $map): array
    {
        $out = [];
        foreach ($rows as $i => $cells) {
            $row = ['_row' => (string) ($i + 2)];
            foreach ($map as $field => $idx) {
                $row[$field] = trim((string) ($cells[$idx] ?? ''));
            }
            $out[] = $row;
        }

        return $out;
    }

    public static function template(string $dataset, string $style = 'juwri'): string
    {
        $dataset = self::dataset($dataset);
        if (! in_array($style, self::STYLES, true)) {
            throw new SwitchError(___('Unknown import type.'));
        }
        $rows = $style === 'juwri' ? self::TEMPLATES[$dataset] : self::STYLE_TEMPLATES[$style][$dataset];

        return "\xEF\xBB\xBF".implode("\r\n", $rows)."\r\n";
    }
}

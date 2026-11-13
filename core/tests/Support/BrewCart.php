<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

/** P3-07 test helper: the cafe demo's "Flat white" with every required choice at its first option plus the first add-on. */
final class BrewCart
{
    /**
     * @return array{item: string, qty: int, options: list<string>, note?: string}
     */
    public static function flatWhite(ViewModelScenario $s, int $qty = 1, ?string $note = null): array
    {
        $home = $s->build('en');
        $card = collect($home['catalog']['items'])->firstWhere('name', 'Flat white');
        $detail = $s->build('en', 'item', ['item' => $card['id']])['page']['item'];
        $options = [];
        foreach ($detail['option_groups'] as $group) {
            $first = collect($group['options'])->first(fn (array $o) => $o['available'] ?? true);
            if ($group['required'] || $group['label'] === 'Extras') {
                $options[] = $first['id'];
            }
        }
        $line = ['item' => $card['id'], 'qty' => $qty, 'options' => $options];
        if ($note !== null) {
            $line['note'] = $note;
        }

        return $line;
    }

    /** @return array{item: string, qty: int, options: list<string>} an item without choices (the croissant's "Serving" is optional) */
    public static function plain(ViewModelScenario $s, string $name = 'Basque cheesecake', int $qty = 1): array
    {
        $card = collect($s->build('en')['catalog']['items'])->firstWhere('name', $name);

        return ['item' => $card['id'], 'qty' => $qty, 'options' => []];
    }
}

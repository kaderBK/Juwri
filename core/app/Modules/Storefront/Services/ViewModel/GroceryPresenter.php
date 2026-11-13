<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ViewModel;

use Juwri\Modules\Menu\Models\Modifier;
use Juwri\Modules\Menu\Models\ModifierGroup;

/**
 * P3-09 (basket): the grocery pieces of the view model — the store's "If it's out of stock" choice as a View Model
 * OptionGroup (never priced; the picker action shows as the option's short note) and the weighed-item notes.
 */
final class GroceryPresenter
{
    /** @return array<string, mixed> View Model OptionGroup of the substitution choice */
    public static function substitutionGroup(ModifierGroup $group, ?Modifier $selected): array
    {
        $options = [];
        foreach ($group->modifiers as $modifier) {
            /** @var Modifier $modifier */
            $row = ['id' => (string) $modifier->public_id, 'label' => (string) $modifier->title];
            $sub = self::actionNote((string) $modifier->picker_action);
            if ($sub !== null) {
                $row['sub_label'] = $sub;
            }
            if ($selected !== null && (int) $modifier->id === (int) $selected->id) {
                $row['selected'] = true;
            }
            $options[] = $row;
        }
        $out = [
            'id' => (string) $group->public_id,
            'label' => (string) $group->title,
            'kind' => 'radio',
            'purpose' => 'substitution',
            'required' => true,
            'min' => 1,
            'max' => 1,
        ];
        if ($selected !== null) {
            $out['selected_label'] = (string) $selected->title;
        }
        $out['options'] = $options;

        return $out;
    }

    /** The server's short note under an out-of-stock option, from its picker action (API-25). */
    public static function actionNote(string $action): ?string
    {
        return match ($action) {
            'offer_replacement' => ___('We suggest a similar item for you to review'),
            'call_customer' => ___('We call you before replacing'),
            'remove_item' => ___('You get a refund for it'),
            default => null,
        };
    }

    /** "Estimated SAR 5.90. You pay for the actual weight, never more than SAR 6.49 (+10%)." */
    public static function estimateNote(MoneyFormat $money, int $estimateMinor, int $capMinor, int $capPercent): string
    {
        return ___('Estimated :estimate. You pay for the actual weight, never more than :cap (+:percent%).', [
            'estimate' => $money->format($estimateMinor), 'cap' => $money->format($capMinor), 'percent' => (string) $capPercent,
        ]);
    }

    /** The quote line note of a weighed line: "Estimated: you pay the actual weight, max SAR 6.49". */
    public static function lineNote(MoneyFormat $money, int $capMinor): string
    {
        return ___('Estimated: you pay the actual weight, max :cap', ['cap' => $money->format($capMinor)]);
    }
}

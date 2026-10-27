<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony;

use App\Services\Agents\AgentToolsService;
use Juwri\Core\Models\Store;
use Juwri\Modules\Agents\Models\AgentConversation;
use Juwri\Modules\Agents\Models\AgentMessage;

/**
 * P6-09 V-08: a voice create_order runs only after an explicit "yes" from the caller, in a LATER turn than the
 * one where the agent read the cart back, for the SAME cart. The order key (client_uuid) is derived from the
 * call + cart hash, so a retried or replayed create_order for that cart returns the same order, never a second.
 * The gate is server-side: the model cannot skip it.
 */
final class VoiceOrderConfirm
{
    /**
     * @param  array<string, mixed>  $args
     * @return array{allow: bool, args: array<string, mixed>, result: array<string, mixed>}
     */
    public static function gate(AgentConversation $conversation, Store $store, array $args, string $userText): array
    {
        $hash = self::cartHash($args);
        $turn = AgentMessage::where('conversation_id', $conversation->id)->where('role', 'user')->count();
        $meta = (array) $conversation->meta;
        $pending = (array) ($meta['voice_confirm'] ?? []);

        if (($pending['hash'] ?? null) === $hash && (int) ($pending['turn'] ?? PHP_INT_MAX) < $turn && self::isYes($userText)) {
            $args['client_uuid'] = self::orderKey((string) $conversation->session_key, $hash);

            return ['allow' => true, 'args' => $args, 'result' => []];
        }

        $meta['voice_confirm'] = ['hash' => $hash, 'turn' => $turn];
        $conversation->forceFill(['meta' => $meta])->save();
        $check = AgentToolsService::validateCart($store, (array) ($args['items'] ?? []), $args['coupon_code'] ?? null);

        return ['allow' => false, 'args' => $args, 'result' => [
            'ok' => false,
            'needs_confirmation' => true,
            'confirmation' => (string) ($check['confirmation'] ?? ''),
            'error' => 'Not placed yet. Read this order and total back to the caller and ask them to say yes. Call create_order again with the same cart only after they say yes.',
        ]];
    }

    /**
     * Stable hash of what would be ordered (items, fulfilment, coupon, time).
     *
     * @param  array<string, mixed>  $args
     */
    public static function cartHash(array $args): string
    {
        $items = array_map(function ($line) {
            $line = (array) $line;
            $line['quantity'] = (int) ($line['quantity'] ?? 1);

            return self::sortKeys($line);
        }, array_values((array) ($args['items'] ?? [])));
        usort($items, fn ($a, $b) => strcmp((string) json_encode($a), (string) json_encode($b)));

        $basis = ['items' => $items];
        foreach (['type', 'table_number', 'address', 'coupon_code', 'scheduled_at'] as $k) {
            $basis[$k] = isset($args[$k]) ? trim((string) $args[$k]) : null;
        }

        return hash('sha256', (string) json_encode($basis));
    }

    /** Deterministic UUID-shaped order key for (call, cart). */
    public static function orderKey(string $sessionKey, string $cartHash): string
    {
        $h = hash('sha256', 'voice|'.$sessionKey.'|'.$cartHash);

        return substr($h, 0, 8).'-'.substr($h, 8, 4).'-5'.substr($h, 13, 3).'-a'.substr($h, 17, 3).'-'.substr($h, 20, 12);
    }

    /** An explicit yes (EN/AR) with no negation in the same utterance. */
    public static function isYes(string $text): bool
    {
        $words = preg_split('/[^\p{L}\p{N}\']+/u', mb_strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $no = ['no', 'not', 'nope', "don't", 'dont', 'wait', 'cancel', 'stop', 'لا', 'لأ', 'مش', 'بلاش', 'انتظر', 'الغ', 'ألغ'];
        if (array_intersect($words, $no) !== []) {
            return false;
        }
        $yes = ['yes', 'yeah', 'yep', 'yup', 'sure', 'ok', 'okay', 'confirm', 'confirmed', 'correct',
            'نعم', 'ايوه', 'أيوه', 'ايوا', 'أيوا', 'اي', 'إي', 'تمام', 'اكيد', 'أكيد', 'موافق', 'أكد', 'اكد', 'صح'];
        if (array_intersect($words, $yes) !== []) {
            return true;
        }
        $joined = ' '.implode(' ', $words).' ';

        return str_contains($joined, ' go ahead ') || str_contains($joined, ' place it ');
    }

    /**
     * @param  array<mixed>  $a
     * @return array<mixed>
     */
    private static function sortKeys(array $a): array
    {
        ksort($a);
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $a[$k] = self::sortKeys($v);
            }
        }

        return $a;
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Team\Services;

use Juwri\Modules\Team\Models\TeamChannel;
use Juwri\Modules\Team\Models\TeamChannelMember;
use Juwri\Modules\Team\Models\TeamMessage;
use Juwri\Modules\Team\Models\TeamMessageRead;

/**
 * SYSTEMS-RUN T-P1: team chat. Poll-based (no websockets). Channels are
 * restaurant-scoped; only members (+ the owner) may read/post; managers/owner
 * create channels. Announcements are a one-way channel with optional ack.
 */
class TeamChatService
{
    public function createChannel(int $storeId, string $name, string $type, array $staffIds = [], bool $requireAck = false, ?int $branchId = null): TeamChannel
    {
        $channel = TeamChannel::create([
            'store_id' => $storeId,
            'branch_id' => $branchId,
            'name' => $name,
            'type' => in_array($type, ['team', 'group', 'dm', 'announcement'], true) ? $type : 'group',
            'require_ack' => $requireAck,
            'created_by' => auth()->id(),
        ]);
        // Owner is always a member.
        TeamChannelMember::create(['channel_id' => $channel->id, 'staff_id' => null, 'is_owner' => true]);
        foreach (array_unique($staffIds) as $sid) {
            TeamChannelMember::firstOrCreate(['channel_id' => $channel->id, 'staff_id' => $sid], ['is_owner' => false]);
        }

        return $channel;
    }

    /** Post a message. $senderStaffId null + fromOwner true = owner message. */
    public function post(TeamChannel $channel, string $body, ?int $senderStaffId = null, bool $fromOwner = false, ?string $attachment = null): TeamMessage
    {
        return TeamMessage::create([
            'channel_id' => $channel->id,
            'sender_staff_id' => $senderStaffId,
            'from_owner' => $fromOwner,
            'body' => $body,
            'attachment' => $attachment,
        ]);
    }

    public function markRead(TeamMessage $message, ?int $staffId, bool $byOwner = false, bool $ack = false): void
    {
        TeamMessageRead::updateOrCreate(
            ['message_id' => $message->id, 'staff_id' => $staffId],
            ['by_owner' => $byOwner, 'acknowledged' => $ack, 'read_at' => now()]
        );
    }

    /** Unread count for a staff member (or owner) in a channel. */
    public function unreadCount(TeamChannel $channel, ?int $staffId, bool $owner = false): int
    {
        $readIds = TeamMessageRead::whereIn('message_id', $channel->messages()->pluck('id'))
            ->when($owner, fn ($q) => $q->where('by_owner', true), fn ($q) => $q->where('staff_id', $staffId))
            ->pluck('message_id');

        return $channel->messages()
            ->whereNotIn('id', $readIds)
            ->when($staffId, fn ($q) => $q->where(fn ($w) => $w->where('sender_staff_id', '!=', $staffId)->orWhereNull('sender_staff_id')))
            ->count();
    }

    public function acknowledgers(TeamMessage $message)
    {
        return TeamMessageRead::where('message_id', $message->id)->where('acknowledged', true)->get();
    }
}

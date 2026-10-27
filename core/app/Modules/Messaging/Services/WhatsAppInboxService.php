<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services;

use App\Models\StaffMember;
use Illuminate\Validation\ValidationException;
use Juwri\Modules\Messaging\Models\WhatsAppConversation;

/**
 * SYSTEMS-RUN S-P2: shared-inbox state — conversation status + assignment.
 * Assignment is restricted to manager+ staff. Keyed by (restaurant, phone).
 */
class WhatsAppInboxService
{
    public function conversationFor(int $storeId, string $phone): WhatsAppConversation
    {
        return WhatsAppConversation::firstOrCreate(
            ['store_id' => $storeId, 'phone' => $phone],
            ['status' => 'open']
        );
    }

    public function setStatus(WhatsAppConversation $conv, string $status): void
    {
        if (! in_array($status, ['open', 'pending', 'closed'], true)) {
            throw ValidationException::withMessages(['status' => ___('Invalid status.')]);
        }
        $conv->update(['status' => $status]);
    }

    public function assign(WhatsAppConversation $conv, ?int $staffId): void
    {
        if ($staffId !== null) {
            $staff = StaffMember::where('store_id', $conv->store_id)->find($staffId);
            if (! $staff) {
                throw ValidationException::withMessages(['assignee' => ___('Staff not found.')]);
            }
            if (! in_array($staff->role, ['manager'], true)) {
                throw ValidationException::withMessages(['assignee' => ___('Only managers can be assigned conversations.')]);
            }
        }
        $conv->update(['assignee_staff_id' => $staffId]);
    }
}

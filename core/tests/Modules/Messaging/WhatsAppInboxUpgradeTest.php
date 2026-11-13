<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Http\Controllers\User\WhatsAppInboxController;
use Juwri\Modules\Messaging\Models\CannedReply;
use Juwri\Modules\Messaging\Models\WhatsAppConversation;
use Juwri\Modules\Messaging\Services\WhatsAppInboxService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * SYSTEMS-RUN S-P2: WhatsApp shared-inbox upgrade — status, assignment, canned.
 */
class WhatsAppInboxUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): array
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => 'x', 'phone' => '1',
        ]);
        $manager = StaffMember::create(['owner_id' => $owner->id, 'store_id' => $r->id, 'name' => 'M', 'email' => 'm'.Str::random(4).'@x.co', 'password' => Hash::make('secret1'), 'role' => 'manager', 'active' => true]);
        $waiter = StaffMember::create(['owner_id' => $owner->id, 'store_id' => $r->id, 'name' => 'W', 'email' => 'w'.Str::random(4).'@x.co', 'password' => Hash::make('secret1'), 'role' => 'waiter', 'active' => true]);

        return [$owner, $r, $manager, $waiter];
    }

    public function test_status_transitions(): void
    {
        [$owner, $r] = $this->scenario();
        $svc = new WhatsAppInboxService;
        $conv = $svc->conversationFor($r->id, '+15551234');
        $this->assertSame('open', $conv->status);
        $svc->setStatus($conv, 'pending');
        $this->assertSame('pending', $conv->fresh()->status);
        $this->expectException(ValidationException::class);
        $svc->setStatus($conv, 'bogus');
    }

    public function test_only_manager_can_be_assigned(): void
    {
        [$owner, $r, $manager, $waiter] = $this->scenario();
        $svc = new WhatsAppInboxService;
        $conv = $svc->conversationFor($r->id, '+15551234');
        $svc->assign($conv, $manager->id);
        $this->assertSame($manager->id, $conv->fresh()->assignee_staff_id);

        $this->expectException(ValidationException::class);
        $svc->assign($conv, $waiter->id);
    }

    public function test_canned_reply_renders_variables(): void
    {
        [$owner, $r] = $this->scenario();
        $canned = CannedReply::create(['store_id' => $r->id, 'title' => 'Hi', 'body' => 'Hello {customer_name}, thanks!']);
        $this->assertSame('Hello Sam, thanks!', $canned->render(['customer_name' => 'Sam']));
    }

    public function test_status_endpoint_and_owner_guard(): void
    {
        [$owner, $r, $manager] = $this->scenario();
        [$other, $otherR] = $this->scenario();
        $ctrl = app(WhatsAppInboxController::class);
        $inbox = app(WhatsAppInboxService::class);

        $this->actingAs($owner);
        $req = Request::create('/x', 'POST', ['phone' => '+15551234', 'status' => 'closed']);
        $ctrl->setStatus($req, $r, $inbox);
        $this->assertSame('closed', WhatsAppConversation::where('store_id', $r->id)->first()->status);

        // Owner cannot touch another tenant's restaurant.
        $this->expectException(HttpException::class);
        $ctrl->setStatus($req, $otherR, $inbox);
    }
}

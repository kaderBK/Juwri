<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Team\Http\Controllers\User;

use App\Models\ChecklistRun;
use App\Models\ChecklistTemplate;
use App\Models\StaffMember;
use App\Services\Team\ChecklistService;
use App\Support\StoreClock;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Team\Models\ShiftNote;
use Juwri\Modules\Team\Models\TeamChannel;
use Juwri\Modules\Team\Services\TeamChatService;

/**
 * SYSTEMS-RUN T-P1: owner dashboard team inbox — channels, threads, compose,
 * announcements. Tenant scoped; behind team_comms.
 */
class TeamCommsController extends Controller
{
    use ResolvesCurrentStore;

    public function __construct(private TeamChatService $chat) {}

    private function ownedChannel(int $id): TeamChannel
    {
        return TeamChannel::whereIn('store_id', $this->storeIds())->findOrFail($id);
    }

    public function index(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $channels = TeamChannel::where('store_id', $rid)->latest('updated_at')->get();

        return view('owner.team.inbox', [
            'rid' => $rid,
            'channels' => $channels,
            'staff' => StaffMember::where('store_id', $rid)->get(['id', 'name']),
            'unread' => $channels->mapWithKeys(fn ($c) => [$c->id => $this->chat->unreadCount($c, null, true)]),
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function thread(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $channel = $this->ownedChannel($id);
        $messages = $channel->messages()->with('reads')->orderBy('id')->get();
        // Owner reads everything on open.
        foreach ($messages as $m) {
            $this->chat->markRead($m, null, true);
        }

        return view('owner.team.thread', [
            'rid' => $channel->store_id,
            'channel' => $channel,
            'messages' => $messages,
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function createChannel(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:team,group,dm,announcement'],
            'staff_ids' => ['nullable', 'array'],
            'require_ack' => ['nullable', 'boolean'],
        ]);
        // Guard staff belong to this tenant.
        $staffIds = collect($data['staff_ids'] ?? [])
            ->filter(fn ($sid) => StaffMember::where('store_id', $rid)->whereKey($sid)->exists())
            ->all();
        $this->chat->createChannel($rid, $data['name'], $data['type'], $staffIds, (bool) ($data['require_ack'] ?? false));

        return back()->with('success', ___('Channel created.'));
    }

    public function post(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $channel = $this->ownedChannel($id);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $this->chat->post($channel, $data['body'], null, true);
        $channel->touch();

        return back()->with('success', ___('Sent.'));
    }

    // ---- T-P2: shift notes + checklists ----

    public function checklists(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $date = $request->query('date') ?: StoreClock::today($rid);
        new ChecklistService()->generateForDate($date, $rid);

        return view('owner.team.checklists', [
            'rid' => $rid,
            'date' => $date,
            'templates' => ChecklistTemplate::where('store_id', $rid)->get(),
            'runs' => ChecklistRun::where('store_id', $rid)->whereDate('date', $date)->with('template')->get(),
            'notes' => ShiftNote::where('store_id', $rid)->whereDate('date', $date)->latest()->get(),
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function storeTemplate(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'schedule' => ['required', 'in:opening,closing,daily'],
            'items' => ['required', 'string'],
        ]);
        ChecklistTemplate::create([
            'store_id' => $rid,
            'name' => $data['name'],
            'schedule' => $data['schedule'],
            'items' => array_values(array_filter(array_map('trim', explode("\n", $data['items'])))),
        ]);

        return back()->with('success', ___('Checklist template created.'));
    }

    public function toggleChecklistItem(Request $request, int $runId)
    {
        $this->currentRestaurantId($request);
        $run = ChecklistRun::whereIn('store_id', $this->storeIds())->findOrFail($runId);
        $data = $request->validate(['index' => ['required', 'integer'], 'done' => ['required', 'boolean']]);
        new ChecklistService()->toggleItem($run, (int) $data['index'], null, (bool) $data['done']);

        return back()->with('success', ___('Updated.'));
    }

    public function storeNote(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $data = $request->validate([
            'date' => ['required', 'date'],
            'body' => ['required', 'string', 'max:2000'],
        ]);
        ShiftNote::create(['store_id' => $rid, 'date' => $data['date'], 'body' => $data['body'], 'author_id' => auth()->id()]);

        return back()->with('success', ___('Note saved.'));
    }
}

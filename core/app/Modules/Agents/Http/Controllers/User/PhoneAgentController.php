<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Http\Controllers\User;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Modules\Agents\Models\CallLog;
use Juwri\Modules\Agents\Models\PhoneLine;
use Juwri\Modules\Agents\Services\Telephony\CallbackConfigurator;
use Juwri\Modules\Agents\Services\Telephony\VoiceCostLedger;

/**
 * T6-P3 owner AI phone-agent config: connect a BYOK number, set greeting / hours /
 * human-handoff, toggle order-taking, and review call logs + transcripts. Tenant-scoped.
 */
class PhoneAgentController extends Controller
{
    private function storeIds(): array
    {
        return dashboard_store_ids();
    }

    private function currentRestaurantId(Request $request): int
    {
        $ids = $this->storeIds();
        $rid = (int) ($request->query('store_id') ?: $request->input('store_id') ?: (dashboard_current_store()->id ?? $ids[0] ?? 0));
        abort_unless(in_array($rid, $ids, true), 403);

        return $rid;
    }

    /** @return \Illuminate\Support\Collection<int, \Juwri\Core\Models\Store> */
    private function restaurantOptions()
    {
        return Store::whereIn('id', $this->storeIds())->get(['id', 'title']);
    }

    public function index(Request $request)
    {
        $rid = $this->currentRestaurantId($request);

        return view('owner.phone-agent.index', [
            'rid' => $rid,
            'lines' => PhoneLine::where('store_id', $rid)->orderBy('id')->get(),
            'calls' => CallLog::where('store_id', $rid)->latest('id')->limit(10)->get(),
            'stores' => $this->restaurantOptions(),
            'minutesUsage' => $this->restaurantOptions()->map(fn ($r) => [
                'title' => (string) $r->title,
                'usage' => VoiceCostLedger::monthUsage((int) $r->id),
            ])->values()->all(),
        ]);
    }

    /** Recording is only lawful in a chosen jurisdiction; refuse enabled recording without a region. */
    private function assertRecordingRegion(Request $request): void
    {
        if ($request->boolean('recording_enabled') && blank($request->input('recording_region'))) {
            throw ValidationException::withMessages([
                'recording_region' => ___('Recording region is required when recording is on.'),
            ]);
        }
    }

    public function save(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        if ($request->input('language') === '') {
            $request->merge(['language' => null]);
        }
        $data = $request->validate([
            'provider' => ['required', 'in:twilio,telnyx'],
            'number' => ['required', 'string', 'max:32'],
            'greeting' => ['nullable', 'string', 'max:512'],
            'active_hours' => ['nullable', 'string', 'max:191'],
            'handoff_number' => ['nullable', 'string', 'max:32'],
            'orders_enabled' => ['nullable', 'boolean'],
            'monthly_minute_cap' => ['nullable', 'integer', 'min:0'],
            'recording_enabled' => ['nullable', 'boolean'],
            'recording_region' => ['nullable', 'in:ksa,uae,eu,other'],
            'link_pay_only' => ['nullable', 'boolean'],
            'auth_token' => ['nullable', 'string', 'max:191'],
            'language' => ['nullable', 'in:en,ar'],
            'account_sid' => ['nullable', 'string', 'max:191', 'regex:/^AC[0-9a-fA-F]{32}$/'],
            'api_key' => ['nullable', 'string', 'max:255', function ($attr, $value, $fail) use ($request) {
                // Twilio API keys are SK + 32 hex; Telnyx keys have their own format and are left alone.
                if ($request->input('provider') === 'twilio' && $value !== null && $value !== '' && ! preg_match('/^SK[0-9a-fA-F]{32}$/', $value)) {
                    $fail(___('Twilio API key must start with SK followed by 32 hexadecimal characters.'));
                }
            }],
            'connection_id' => ['nullable', 'string', 'max:191', 'regex:/^(\d+|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})$/'],
            'public_key' => ['nullable', 'string', 'max:255', function ($attr, $value, $fail) {
                if ($value !== null && $value !== '') {
                    $raw = base64_decode($value, true);
                    if ($raw === false || strlen($raw) !== 32) {
                        $fail(___('Telnyx public key must be base64 text that decodes to exactly 32 bytes.'));
                    }
                }
            }],
        ], [
            'account_sid.regex' => ___('Twilio Account SID must start with AC followed by 32 hexadecimal characters.'),
            'connection_id.regex' => ___('Telnyx connection ID must be digits or a UUID.'),
        ]);

        $this->assertRecordingRegion($request);

        // The (provider, number) pair is globally unique, so an updateOrCreate keyed on it
        // alone would re-point ANOTHER store's existing line to the caller — a cross-tenant
        // takeover of that number (and it vanishes from the real owner's dashboard). Refuse
        // when the number is already registered to a different store.
        $existingLine = PhoneLine::where('provider', $data['provider'])->where('number', $data['number'])->first();
        if ($existingLine && (int) $existingLine->store_id !== (int) $rid) {
            app_alert_error(___('That number is already registered to another store.'));

            return back();
        }

        $line = PhoneLine::updateOrCreate(
            ['provider' => $data['provider'], 'number' => $data['number']],
            [
                'store_id' => $rid,
                'greeting' => $data['greeting'] ?? null,
                'active_hours' => $data['active_hours'] ?? null,
                'handoff_number' => $data['handoff_number'] ?? null,
                'orders_enabled' => (bool) ($data['orders_enabled'] ?? true),
                'monthly_minute_cap' => $data['monthly_minute_cap'] ?? null,
                'recording_enabled' => $request->boolean('recording_enabled'),
                'recording_region' => $data['recording_region'] ?? null,
                'link_pay_only' => $request->boolean('link_pay_only'),
                'language' => $data['language'] ?? null,
                'active' => true,
            ]
        );

        $secrets = array_filter(
            Arr::only($data, ['auth_token', 'account_sid', 'api_key', 'connection_id', 'public_key']),
            fn ($v) => $v !== null && $v !== ''
        );
        if ($secrets !== []) {
            $existing = Credential::where('store_id', $rid)->where('feature', $data['provider'])->first();
            Credential::updateOrCreate(
                ['store_id' => $rid, 'feature' => $data['provider']],
                ['provider' => $data['provider'], 'secrets' => array_merge($existing->secrets ?? [], $secrets), 'active' => true]
            );
        }

        // P6-09: point the provider's call-end callback at our V-12 status endpoint (idempotent, best effort).
        app(CallbackConfigurator::class)->configure($line);

        app_alert_success(___('Phone line saved.'));

        return back();
    }

    public function toggle(Request $request, int $id)
    {
        $line = PhoneLine::whereIn('store_id', $this->storeIds())->findOrFail($id);
        $line->active = ! $line->active;
        $line->save();
        app_alert_success($line->active ? ___('Line enabled.') : ___('Line disabled.'));

        return back();
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $line = PhoneLine::whereIn('store_id', $this->storeIds())->findOrFail($id);

        if ($request->input('language') === '') {
            $request->merge(['language' => null]);
        }
        $data = $request->validate([
            'greeting' => ['nullable', 'string', 'max:512'],
            'active_hours' => ['nullable', 'string', 'max:191'],
            'handoff_number' => ['nullable', 'string', 'max:32'],
            'orders_enabled' => ['nullable', 'boolean'],
            'monthly_minute_cap' => ['nullable', 'integer', 'min:0'],
            'recording_enabled' => ['nullable', 'boolean'],
            'recording_region' => ['nullable', 'in:ksa,uae,eu,other'],
            'link_pay_only' => ['nullable', 'boolean'],
            'language' => ['nullable', 'in:en,ar'],
        ]);

        $this->assertRecordingRegion($request);

        $line->greeting = $data['greeting'] ?? null;
        $line->active_hours = $data['active_hours'] ?? null;
        $line->handoff_number = $data['handoff_number'] ?? null;
        $line->orders_enabled = $request->boolean('orders_enabled');
        $line->monthly_minute_cap = $data['monthly_minute_cap'] ?? null;
        $line->recording_enabled = $request->boolean('recording_enabled');
        $line->recording_region = $data['recording_region'] ?? null;
        $line->link_pay_only = $request->boolean('link_pay_only');
        $line->language = $data['language'] ?? null;
        $line->save();

        app_alert_success(___('Settings saved.'));

        return back();
    }

    public function calls(Request $request): View
    {
        $rid = $this->currentRestaurantId($request);

        return view('owner.phone-agent.calls', [
            'rid' => $rid,
            'calls' => CallLog::where('store_id', $rid)->latest('id')->paginate(20),
            'stores' => $this->restaurantOptions(),
        ]);
    }

    public function show(Request $request, int $id): View
    {
        $rid = $this->currentRestaurantId($request);
        $call = CallLog::whereIn('store_id', $this->storeIds())->findOrFail($id);

        return view('owner.phone-agent.call', [
            'rid' => $rid,
            'call' => $call,
            'stores' => $this->restaurantOptions(),
        ]);
    }

    public function call(Request $request, int $id)
    {
        $call = CallLog::whereIn('store_id', $this->storeIds())->findOrFail($id);

        return response()->json([
            'id' => $call->id,
            'from' => $call->from_number,
            'outcome' => $call->outcome,
            'order_id' => $call->order_id,
            'duration' => $call->duration_seconds,
            'transcript' => $call->transcript ?? [],
        ]);
    }
}

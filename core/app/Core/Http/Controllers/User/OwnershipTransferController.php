<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers\User;

use App\Models\StoreOption;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Mail\StoreTransferOfferMail;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\StoreTransfer;
use Juwri\Core\Models\User;
use Juwri\Core\Services\OwnershipTransferService;
use Juwri\Core\Services\Tenancy\TenantContext;

/**
 * WI-G7: owner-driven ownership transfer. Every action is scoped and consent is
 * two-party: the initiator owns the store; the recipient must accept. Ownership
 * is re-checked at accept time, offers expire, and each apply is audited by the
 * service.
 */
class OwnershipTransferController extends Controller
{
    public function __construct(private OwnershipTransferService $service) {}

    private function ownedStore(): Store
    {
        $store = dashboard_current_store();
        abort_unless($store, 404, ___('Pick a store first.'));
        abort_unless((int) $store->user_id === (int) auth()->id(), 403);

        return $store;
    }

    /** The ownership-transfer page: incoming offers, outgoing offers, branch move. */
    public function index()
    {
        $store = $this->ownedStore();
        $uid = (int) auth()->id();

        return view('owner.stores.transfer', [
            'store' => $store,
            // P1-04 slice 5: an incoming offer belongs to the SENDER's account — read across accounts,
            // keyed on to_user_id (the recipient is the only reader).
            'incoming' => TenantContext::bypass(fn () => StoreTransfer::where('kind', 'store')->where('to_user_id', $uid)
                ->where('status', 'pending')->with(['store', 'fromUser'])->orderByDesc('id')->get()),
            'outgoing' => StoreTransfer::where('kind', 'store')->where('from_user_id', $uid)
                ->where('status', 'pending')->with('store')->orderByDesc('id')->get(),
            'branches' => Branch::where('store_id', $store->id)->orderBy('id')->get(['id', 'name']),
            'others' => Store::where('user_id', $uid)->where('id', '!=', $store->id)->orderBy('title')->get(['id', 'title']),
        ]);
    }

    /** Initiate a STORE transfer offer to another user by email. */
    public function offerStore(Request $request)
    {
        $store = $this->ownedStore();
        $data = $request->validate(['to_email' => 'required|email']);

        $recipient = User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $data['to_email']))])->first();
        // Do not leak whether an email maps to an account, or whether it is merely
        // unverified; require a real, verified target with one non-committal message.
        abort_unless(
            $recipient && $recipient->id !== auth()->id() && $recipient->email_verified_at,
            422,
            ___('That recipient can\'t receive this store.')
        );

        // One pending offer per store at a time.
        $exists = StoreTransfer::where('kind', 'store')->where('store_id', $store->id)
            ->where('status', 'pending')->exists();
        if ($exists) {
            return back()->with('error', ___('There is already a pending transfer for this store.'));
        }

        // E2: cooldown — no re-offer while a recent transfer of this store is still
        // inside its window (superadmin force is exempt; it never reaches here).
        if ($this->service->cooldownActive($store)) {
            return back()->with('error', ___('This store was transferred recently. Please wait before offering it again.'));
        }

        // E2: billing guard — don't let an owner hand a store off while they still
        // owe the platform for an unsettled charge.
        if ($this->service->initiatorHasUnsettledBilling((int) auth()->id())) {
            return back()->with('error', ___('Please settle your outstanding platform charges before transferring a store.'));
        }

        $transfer = StoreTransfer::create([
            'kind' => 'store',
            'token' => StoreTransfer::newToken(),
            'from_user_id' => auth()->id(),
            'to_email' => $recipient->email,
            'to_user_id' => $recipient->id,
            'store_id' => $store->id,
            'status' => 'pending',
            'manifest' => $this->service->storeManifest($store),
            'expires_at' => now()->addDays(StoreTransfer::DEFAULT_TTL_DAYS),
        ]);

        if (! $this->notify($recipient->email, $transfer, $store->title, 'offer')) {
            // P6-194: the offer is saved; tell the sender the e-mail did not go out.
            return back()->with('warning', ___('Offer saved, but the email was not sent. Send the recipient this link: :link', ['link' => route('transfer.index')]));
        }

        return back()->with('success', ___('Transfer offer sent. It stays pending until the recipient accepts.'));
    }

    /** E2: save the per-store transfer cooldown (days). §5 field: transfer_cooldown_days. */
    public function saveSettings(Request $request): RedirectResponse
    {
        $store = $this->ownedStore();
        $data = $request->validate(['transfer_cooldown_days' => 'required|integer|min:0|max:365']);
        StoreOption::updateStoreOption($store->id, 'transfer_cooldown_days', (string) (int) $data['transfer_cooldown_days']);

        return back()->with('success', ___('Transfer settings saved.'));
    }

    /** Initiator cancels their own pending offer. */
    public function cancel(string $token)
    {
        $transfer = StoreTransfer::where('token', $token)
            ->where('from_user_id', auth()->id())
            ->where('status', 'pending')
            ->firstOrFail();
        $transfer->update(['status' => 'cancelled', 'responded_at' => now()]);

        return back()->with('success', ___('Transfer cancelled.'));
    }

    /**
     * Recipient accepts a pending offer directed at them. P1-04 slice 5: the offer and the store belong to the
     * sender's account until the transfer applies, so this runs outside the tenant scope (the token +
     * to_user_id checks below are the authorization).
     */
    public function accept(string $token)
    {
        return TenantContext::bypass(fn () => $this->acceptOffer($token));
    }

    private function acceptOffer(string $token): RedirectResponse
    {
        $transfer = StoreTransfer::where('token', $token)
            ->where('to_user_id', auth()->id())
            ->firstOrFail();
        abort_unless($transfer->isPending(), 410, ___('This transfer is no longer available.'));

        // Ownership re-checked at accept time.
        $stillOwned = Store::where('id', $transfer->store_id)
            ->where('user_id', $transfer->from_user_id)->exists();
        abort_unless($stillOwned, 410, ___('This store is no longer available for transfer.'));

        if (! $this->service->recipientHasCapacity(auth()->user())) {
            return back()->with('error', ___('Your plan has no room for another store. Upgrade, then accept.'));
        }

        $storeName = Store::where('id', $transfer->store_id)->value('title');
        $this->service->applyStore($transfer);

        // Tell the previous owner it went through.
        $fromEmail = User::where('id', $transfer->from_user_id)->value('email');
        if ($fromEmail) {
            $this->notify($fromEmail, $transfer, $storeName, 'accepted');
        }

        return redirect()->route('dashboard')->with('success', ___('The store is now yours.'));
    }

    /** Best-effort transfer email (P6-194: queued after commit, failure logged) — never blocks the transfer. */
    private function notify(string $email, StoreTransfer $transfer, ?string $storeName, string $kind): bool
    {
        try {
            Mail::to($email)->queue((new StoreTransferOfferMail($transfer, $storeName, $kind))->afterCommit());

            return true;
        } catch (\Throwable $e) {
            Log::warning('[ownership-transfer] '.$kind.' mail not sent: '.$e->getMessage(), ['transfer_id' => $transfer->id]);

            return false;
        }
    }

    /** Recipient declines (the offer is the sender's row: read outside the tenant scope, keyed on to_user_id). */
    public function decline(string $token)
    {
        $transfer = TenantContext::bypass(fn () => StoreTransfer::where('token', $token)
            ->where('to_user_id', auth()->id())
            ->where('status', 'pending')
            ->firstOrFail());
        $transfer->update(['status' => 'declined', 'responded_at' => now()]);

        return back()->with('success', ___('Transfer declined.'));
    }

    /** Move a BRANCH to another of the owner's stores (same-owner: direct). */
    public function moveBranch(Request $request)
    {
        $store = $this->ownedStore();
        $data = $request->validate([
            'branch_id' => 'required|integer',
            'target_store_id' => 'required|integer',
        ]);

        $branch = Branch::where('id', $data['branch_id'])->where('store_id', $store->id)->firstOrFail();
        $target = Store::where('id', $data['target_store_id'])->firstOrFail();
        // Same-owner move applies directly; cross-owner would require accept (not in this direct path).
        abort_unless((int) $target->user_id === (int) auth()->id(), 403, ___('You can only move a branch between your own stores.'));

        $transfer = StoreTransfer::create([
            'kind' => 'branch',
            'token' => StoreTransfer::newToken(),
            'from_user_id' => auth()->id(),
            'store_id' => $store->id,
            'branch_id' => $branch->id,
            'target_store_id' => $target->id,
            'status' => 'pending',
        ]);
        // P6-341: every branch-keyed row follows the branch or the move is refused with what to do.
        $why = $this->service->applyBranch($transfer);
        if ($why !== null) {
            return back()->with('error', match ($why) {
                'history' => ___('This branch has orders, shifts, fiscal or stock records, so it cannot move to another store. Open a new branch in the other store instead.'),
                'linked' => ___('This branch has coupons, combos, chats or connected integrations tied to this store. Remove or disconnect them before moving the branch.'),
                'stock' => ___('This branch still holds stock. Count its stock to zero before moving it.'),
                'open_session' => ___('This branch has an open table session. Try again when it has ended.'),
                'last' => ___('A store must keep at least one branch, so its only branch cannot be moved.'),
                'limit' => ___('The other store has reached its branch limit. Upgrade its plan or remove a branch there first.'),
                'same_store' => ___('The branch is already in this store.'),
                default => ___('This branch cannot be moved yet: some of its data has no move rule. Contact support.'),
            });
        }

        return back()->with('success', ___('Branch moved.'));
    }
}

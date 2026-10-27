<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Http\Controllers\User;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Media\Contracts\StorageQuotas;
use Juwri\Modules\Ordering\Models\OrderVoiceNote;

/**
 * FEATURES-5 Track C — customer order voice notes.
 *
 * Flow (two-step, avoids multipart bloat in the JSON submitOrder path):
 *   1. Customer records via MediaRecorder, POSTs the blob to
 *      {store}/order/voice-note (session-scoped) -> returns a temp token.
 *   2. submitOrder receives `voice_note_token` and attaches the temp row to the
 *      new order (see StoreController::attachVoiceNote()).
 *
 * Playback is only reachable through authorized streaming routes:
 *   - staff: dashboard/orders/{order}/voice-note (owner/staff of the restaurant)
 *   - customer: {store}/order/voice-note/{token} (must own it in session)
 * Files live on the PRIVATE disk (storage/app/voice-notes/...), never public.
 */
class VoiceNoteController extends Controller
{
    private const SESSION_KEY = 'voice_note_tokens';

    /** Private disk used for voice-note blobs. */
    private function disk()
    {
        return Storage::disk('local');
    }

    /**
     * Step 1: receive a recorded blob, store it privately, return a temp token.
     * Rejected when the restaurant toggle is OFF or the file fails validation.
     */
    public function upload(Request $request, Store $store)
    {
        if (! $store->exists) {
            $routeRestaurant = $request->route('store');
            if ($routeRestaurant instanceof Store) {
                $store = $routeRestaurant;
            } else {
                $store = Store::query()
                    ->idOrSlug($routeRestaurant)
                    ->firstOrFail();
            }
        }

        // Owner toggle (default OFF).
        if (! self::acceptEnabled($store->id)) {
            return response()->json([
                'success' => false,
                'message' => ___('Voice notes are not accepted for this restaurant.'),
            ], 422);
        }

        $file = $request->file('voice_note');
        if (! $file || ! $file->isValid()) {
            return response()->json([
                'success' => false,
                'message' => ___('No voice recording was received.'),
            ], 422);
        }

        // Size cap (2 MB).
        $size = (int) $file->getSize();
        if ($size <= 0 || $size > OrderVoiceNote::MAX_BYTES) {
            return response()->json([
                'success' => false,
                'message' => ___('The voice recording is too large.'),
            ], 422);
        }

        // Mime validation (server-side, do not trust the client label alone).
        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
        $baseMime = strtolower(trim(explode(';', $mime)[0]));
        if (! in_array($baseMime, OrderVoiceNote::ALLOWED_MIMES, true)) {
            return response()->json([
                'success' => false,
                'message' => ___('That audio format is not supported.'),
            ], 422);
        }

        // Duration cap (client-reported, clamped defensively).
        $duration = (int) round((float) $request->input('duration', 0));
        if ($duration < 0) {
            $duration = 0;
        }
        if ($duration > OrderVoiceNote::MAX_SECONDS + 2) {
            return response()->json([
                'success' => false,
                'message' => ___('The voice recording is too long.'),
            ], 422);
        }

        // Per-tenant storage quota (voice files count too).
        $ownerId = (int) $store->user_id;
        if ($ownerId && ! app(StorageQuotas::class)->allow($ownerId, $size)) {
            return response()->json([
                'success' => false,
                'message' => ___('Storage limit reached. Please try again later.'),
            ], 422);
        }

        $ext = $this->extensionForMime($baseMime, $file->getClientOriginalExtension());
        // Random >=40-char filename: never publicly guessable.
        $filename = Str::random(48).'.'.$ext;
        $relDir = 'voice-notes/'.$store->id;

        $stored = $this->disk()->putFileAs($relDir, $file, $filename);
        if (! $stored) {
            return response()->json([
                'success' => false,
                'message' => ___('Could not save the voice recording.'),
            ], 500);
        }

        if ($ownerId) {
            app(StorageQuotas::class)->add($ownerId, $size);
        }

        $note = OrderVoiceNote::create([
            'order_id' => null,
            'store_id' => $store->id,
            'token' => OrderVoiceNote::newToken(),
            'filename' => $filename,
            'mime' => $baseMime,
            'duration_seconds' => $duration ?: null,
            'size_bytes' => $size,
        ]);

        // Remember ownership so the customer can replay their own note.
        if ($request->hasSession()) {
            $tokens = (array) $request->session()->get(self::SESSION_KEY, []);
            $tokens[] = $note->token;
            $request->session()->put(self::SESSION_KEY, array_slice(array_unique($tokens), -20));
        }

        return response()->json([
            'success' => true,
            'token' => $note->token,
            'duration' => $note->duration_seconds,
        ]);
    }

    /**
     * Staff playback: streams the note for an order the current dashboard user
     * (owner or staff) is allowed to see. Range-request capable.
     */
    public function staffStream(Request $request, Order $order)
    {
        $ownerId = dashboard_owner_id();
        if (! $ownerId || (int) optional($order->store)->user_id !== (int) $ownerId) {
            abort(403);
        }

        $note = $order->voiceNote;
        if (! $note) {
            abort(404);
        }

        return $this->streamNote($note);
    }

    /**
     * Signed playback for the staff PWA / native API clients: an <audio> element
     * cannot attach a bearer token, so FormatsOrders hands out a short-lived
     * temporarySignedRoute URL instead. The signature (validated by the `signed`
     * middleware) IS the authorization — the URL is minted server-side only for a
     * staff member already authenticated for that order's restaurant.
     */
    public function signedStream(Request $request, Order $order)
    {
        $note = $order->voiceNote;
        if (! $note) {
            abort(404);
        }

        return $this->streamNote($note);
    }

    /**
     * Customer replay: only if this browser session uploaded the token.
     */
    public function customerStream(Request $request, $store, string $token)
    {
        $tokens = $request->hasSession() ? (array) $request->session()->get(self::SESSION_KEY, []) : [];
        if (! in_array($token, $tokens, true)) {
            abort(403);
        }

        $note = OrderVoiceNote::where('token', $token)->first();
        if (! $note) {
            abort(404);
        }

        return $this->streamNote($note);
    }

    /**
     * Stream a stored note with the correct mime + HTTP range support so
     * players can seek. Uses the private-disk absolute path.
     */
    private function streamNote(OrderVoiceNote $note)
    {
        $disk = $this->disk();
        $rel = $note->relativePath();
        if (! $disk->exists($rel)) {
            abort(404);
        }

        $absolute = $disk->path($rel);

        // BinaryFileResponse honours the Range header automatically.
        return response()->file($absolute, [
            'Content-Type' => $note->mime ?: 'application/octet-stream',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=3600',
            'Content-Disposition' => 'inline; filename="voice-note.'.pathinfo($note->filename, PATHINFO_EXTENSION).'"',
        ]);
    }

    private function extensionForMime(string $mime, ?string $clientExt): string
    {
        return match ($mime) {
            'audio/webm', 'video/webm' => 'webm',
            'audio/ogg' => 'ogg',
            'audio/mp4', 'audio/m4a', 'audio/x-m4a' => 'm4a',
            'audio/aac' => 'aac',
            'audio/mpeg' => 'mp3',
            default => preg_match('/^[a-z0-9]{1,5}$/i', (string) $clientExt) ? strtolower($clientExt) : 'bin',
        };
    }

    /** Owner toggle: "Accept voice notes on orders" (default OFF). */
    public static function acceptEnabled(int $storeId): bool
    {
        return (string) store_options($storeId, 'accept_voice_notes') === '1';
    }
}

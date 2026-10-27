<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony\Bridge;

use Juwri\Modules\Agents\Models\PhoneLine;
use Juwri\Modules\Agents\Services\Telephony\VoiceSession;

/**
 * P6-09 V-04: Twilio ConversationRelay WebSocket message codec.
 *
 * Pure and socket-free on purpose: {@see decode()} takes one inbound JSON frame as a
 * string and returns one outbound JSON frame (or null when there is nothing to say), so
 * the bridge worker (V-06) only owns the transport. Frames follow the public message
 * reference: https://www.twilio.com/docs/voice/conversationrelay/websocket-messages
 *
 * Inbound: setup (stores callSid/from), prompt (caller speech -> VoiceSession::turn),
 * interrupt (drops any buffered partial utterance), dtmf / error (ignored). Unknown or
 * malformed frames are ignored safely — decode() never throws.
 *
 * Outbound: {"type":"text","token":"…","last":true} for an agent reply and
 * {"type":"end"} when the codec is told to end (handoff / hangup).
 */
final class TwilioRelayCodec
{
    private ?string $callSid = null;

    private ?string $from = null;

    /** Buffered fragments of a caller prompt that has not been finalised (last=false). */
    private string $pending = '';

    public function __construct(private readonly PhoneLine $line) {}

    /** The call SID captured from the ConversationRelay `setup` frame, if any. */
    public function callSid(): ?string
    {
        return $this->callSid;
    }

    /** The caller number captured from the `setup` frame, if any. */
    public function from(): ?string
    {
        return $this->from;
    }

    /** The partial caller utterance buffered so far (cleared by `interrupt`). */
    public function pending(): string
    {
        return $this->pending;
    }

    /**
     * Decode one inbound frame to one outbound frame, or null when the frame needs no reply.
     *
     * @param  string  $frame  raw JSON sent by ConversationRelay
     * @return string|null  JSON to send back, or null to stay quiet
     */
    public function decode(string $frame): ?string
    {
        $message = json_decode($frame, true);
        if (! is_array($message) || ! isset($message['type']) || ! is_string($message['type'])) {
            return null;
        }

        return match ($message['type']) {
            'setup' => $this->handleSetup($message),
            'prompt' => $this->handlePrompt($message),
            'interrupt' => $this->handleInterrupt(),
            default => null,
        };
    }

    /** Build the `end` frame that hands control back to Twilio. */
    public function end(?string $handoffReason = null): string
    {
        $this->pending = '';
        $payload = ['type' => 'end'];
        if ($handoffReason !== null && $handoffReason !== '') {
            $payload['handoffData'] = $handoffReason;
        }

        return $this->encode($payload);
    }

    /**
     * Store the call identity from the first `setup` frame.
     *
     * @param  array<string, mixed>  $message
     */
    private function handleSetup(array $message): null
    {
        $callSid = $message['callSid'] ?? null;
        if (! is_string($callSid) || $callSid === '') {
            return null;
        }

        $this->callSid = $callSid;
        $from = $message['from'] ?? null;
        $this->from = is_string($from) && $from !== '' ? $from : null;
        $this->pending = '';

        return null;
    }

    /**
     * Buffer partial transcription; on the final fragment run the agent turn and reply.
     *
     * @param  array<string, mixed>  $message
     */
    private function handlePrompt(array $message): ?string
    {
        $voicePrompt = $message['voicePrompt'] ?? null;
        if (! is_string($voicePrompt) || trim($voicePrompt) === '') {
            return null;
        }

        $appended = $this->pending === '' ? $voicePrompt : $this->pending.' '.$voicePrompt;

        // `last` absent means a single, complete utterance.
        if (($message['last'] ?? true) === false) {
            $this->pending = $appended;

            return null;
        }

        $this->pending = '';
        if ($this->callSid === null) {
            return null;
        }

        // TODO(V-09): transcript digit filter hook
        $reply = VoiceSession::turn($this->line, $this->callSid, $this->from, $appended);

        return $this->encode([
            'type' => 'text',
            'token' => $reply,
            'last' => true,
        ]);
    }

    /** Barge-in: discard the buffered partial caller utterance. */
    private function handleInterrupt(): null
    {
        $this->pending = '';

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return (string) json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}

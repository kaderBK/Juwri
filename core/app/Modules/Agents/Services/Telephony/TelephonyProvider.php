<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony;

use Illuminate\Http\Request;
use Juwri\Modules\Agents\Models\PhoneLine;

/**
 * T6 telephony contract. A provider (Twilio, Telnyx) answers an inbound call by
 * returning call-control markup that hands the audio to the platform's voice bridge,
 * and verifies its own webhook signatures. The voice agent brain is provider-agnostic
 * (AgentChatService, wired in T6-P2); this layer is only the phone transport.
 */
interface TelephonyProvider
{
    /** Provider key: 'twilio' | 'telnyx'. */
    public function key(): string;

    /** Verify an inbound webhook against the line's stored credentials. */
    public function verifyWebhook(Request $request, PhoneLine $line): bool;

    /** The dialled (restaurant) number from an inbound-call webhook, to resolve the line. */
    public function dialledNumber(Request $request): ?string;

    /** The caller's number (trusted only for callback SMS, never for tenant resolution). */
    public function callerNumber(Request $request): ?string;

    /**
     * Call-control markup answering the call: connect the audio to our voice bridge
     * WebSocket (ConversationRelay for Twilio / TeXML for Telnyx). Returns [body, contentType].
     */
    public function answer(PhoneLine $line, string $wsUrl): array;

    /**
     * FIX-2 F2-13: call-control markup for "the AI agent is not available on this
     * line" — the tenant's ai_phone_agent entitlement lapsed (or the platform
     * killed the feature). Returns [body, contentType]; it must be a graceful
     * 200 answer, never a refusal: an inbound call is a customer on the phone,
     * and a non-2xx would only make the provider retry. Falls back to the line's
     * human handoff number when one is configured, otherwise says so and hangs up.
     */
    public function unavailable(PhoneLine $line): array;

    /**
     * P6-09 V-13: warm-transfer the live call to a human number, whispering a short
     * summary to the staff member who answers ("caller wants X, cart Y"). Returns
     * call-control markup [body, contentType] the provider executes, exactly like
     * {@see answer()}. Twilio/Telnyx emit a `<Dial><Number url=...>` where the
     * `url` is the whisper TwiML/TeXML endpoint carrying $summary; the Fake returns
     * a plain array for tests. $number and $summary are escaped by the provider.
     *
     * @return array{0: mixed, 1: string}
     */
    public function transfer(PhoneLine $line, string $number, string $summary): array;
}
